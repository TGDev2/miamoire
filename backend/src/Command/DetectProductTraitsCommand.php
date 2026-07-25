<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ProductTrait;
use App\Repository\ProductRepository;
use App\Service\ProductTraitDetector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Complète l'état des produits enregistrés avant l'arrivée de `product.traits`.
 *
 * Les produits déjà porteurs d'un état ne sont jamais touchés : une correction
 * faite à la main dans l'application prime sur la déduction automatique.
 */
#[AsCommand(
    name: 'app:products:detect-traits',
    description: 'Déduit l\'état (conserve, surgelé, épluché…) des produits qui n\'en ont pas',
)]
class DetectProductTraitsCommand extends Command
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductTraitDetector $detector,
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

        $updated = 0;

        foreach ($this->products->findAll() as $product) {
            if ([] !== $product->getTraits()) {
                continue;
            }

            $detected = $this->detector->detect($product->getName(), $product->getCategory());

            if ([] === $detected) {
                continue;
            }

            $io->text(sprintf('%s → %s', $product->getName(), implode(', ', ProductTrait::toValues($detected))));

            if (!$dryRun) {
                $product->setTraits($detected);
            }

            ++$updated;
        }

        if (!$dryRun) {
            $this->entityManager->flush();
        }

        $io->success(sprintf(
            '%d produit(s) %s.',
            $updated,
            $dryRun ? 'seraient complétés' : 'complétés',
        ));

        return Command::SUCCESS;
    }
}
