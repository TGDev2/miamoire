<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\DataOrigin;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Food\FoodNatureDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Classe les variantes enregistrées avant l'arrivée de `household_product_variant.nature`.
 *
 * La migration les a toutes posées à « conditionné », qui était le comportement de
 * l'application jusque-là. Cette commande les relit et range du côté frais ce qui
 * l'est réellement.
 *
 * **Elle ne touche à aucun lot.** Un lot déjà en stock sans date de péremption la
 * garde : quelqu'un l'a laissée vide, et lui inventer rétroactivement une date
 * ferait remonter d'un coup des dizaines de lignes « périmées » qui ne sont que
 * mal datées. La règle ne vaut que pour ce qu'on saisit désormais.
 *
 * Comme {@see DetectProductTraitsCommand}, elle respecte les corrections humaines :
 * une variante dont la nature porte `DataOrigin::User` n'est jamais reclassée.
 */
#[AsCommand(
    name: 'app:variants:detect-nature',
    description: 'Range les aliments enregistrés entre produits frais et produits conditionnés',
)]
class DetectFoodNatureCommand extends Command
{
    public function __construct(
        private readonly HouseholdProductVariantRepository $variants,
        private readonly FoodNatureDetector $detector,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui serait écrit sans rien modifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $changed = 0;
        $kept = 0;

        foreach ($this->variants->findAll() as $variant) {
            if (DataOrigin::User === $variant->getNatureOrigin()) {
                ++$kept;

                continue;
            }

            $detected = $this->detector->detect(
                $variant->getName(),
                $variant->getCategory(),
                $variant->getTraits(),
                scanned: null !== $variant->getProduct()?->getBarcode(),
            );

            if ($detected === $variant->getNature()) {
                continue;
            }

            $io->text(sprintf(
                '%s : %s → %s',
                $variant->getCulinaryLabel(),
                mb_strtolower($variant->getNature()->label()),
                mb_strtolower($detected->label()),
            ));

            if (!$dryRun) {
                $variant->setNature($detected, DataOrigin::Detected);
            }

            ++$changed;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%d aliment(s) %s, %d correction(s) humaine(s) préservée(s), 0 lot modifié.',
            $changed,
            $dryRun ? 'seraient reclassés' : 'reclassés',
            $kept,
        ));

        return Command::SUCCESS;
    }
}
