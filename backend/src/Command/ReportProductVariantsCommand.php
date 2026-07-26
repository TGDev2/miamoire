<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HouseholdProductVariantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Le rapport de reprise : ce que l'ancien modèle fusionnait.
 *
 * Tant que l'inventaire regroupait sur la seule clé d'ingrédient, deux variantes
 * d'un même aliment — tomates fraîches et tomates en conserve, farine au kilo et
 * farine au paquet — ne formaient qu'une ligne, dont la quantité additionnait des
 * choses non additionnables et dont les traits étaient l'union des deux.
 *
 * La migration les a séparées, sans jamais fusionner ce qu'elle ne savait pas
 * justifier. Cette commande donne la liste à relire : ce sont exactement les cas
 * où l'inventaire disait quelque chose de faux, et ceux où un foyer voudra peut-être
 * renommer une variante pour les distinguer d'un coup d'œil.
 */
#[AsCommand(
    name: 'app:variants:report',
    description: 'Liste les aliments qu\'un foyer possède sous plusieurs variantes',
)]
class ReportProductVariantsCommand extends Command
{
    public function __construct(private readonly HouseholdProductVariantRepository $variants)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $variants = $this->variants->findAmbiguousVariants();

        if ([] === $variants) {
            $io->success('Aucun aliment n\'existe sous plusieurs variantes : rien n\'était fusionné.');

            return Command::SUCCESS;
        }

        $keys = [];
        $rows = [];

        foreach ($variants as $variant) {
            $keys[$variant->getHousehold()->getName().'|'.$variant->getIngredientKey()] = true;

            $rows[] = [
                $variant->getHousehold()->getName(),
                $variant->getIngredientKey(),
                $variant->getSlug(),
                $variant->getCulinaryLabel(),
                $variant->getUnitFamily()->label(),
            ];
        }

        $io->title('Aliments présents sous plusieurs variantes');
        $io->table(['Foyer', 'Clé', 'Identifiant', 'Libellé culinaire', 'Compté en'], $rows);
        $io->note(sprintf(
            '%d clé(s) concernée(s), %d variante(s). Ces lignes étaient auparavant additionnées '
            .'en une seule, avec l\'union de leurs états. Vérifiez que la séparation correspond '
            .'bien à deux stocks distincts.',
            count($keys),
            count($rows),
        ));

        return Command::SUCCESS;
    }
}
