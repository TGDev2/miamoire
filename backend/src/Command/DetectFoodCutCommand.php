<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\DataOrigin;
use App\Enum\FoodCut;
use App\Exception\StockException;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Food\FoodCutDetector;
use App\Service\Product\VariantResolver;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Donne sa forme au parc existant, et absorbe l'ancien trait `sliced`.
 *
 * Deux reprises en une, parce qu'elles portent sur les mêmes lignes :
 *
 * - **la découpe**, déduite du libellé — « Escalopes de poulet » enregistré avant
 *   cet axe doit devenir `escalope`, sans quoi le générateur continuera de choisir
 *   la forme qui l'arrange ;
 * - **`sliced`**, retiré de {@see \App\Enum\ProductTrait}. Les variantes qui le
 *   portent encore en base gardent une empreinte calculée sur un trait qui n'existe
 *   plus : leur identité est périmée, et un nouveau lot du même aliment créerait un
 *   doublon au lieu de les rejoindre. Une découpe leur est donc rendue — celle que
 *   nomme leur libellé, ou `pieces` à défaut, qui est exactement ce que `sliced`
 *   voulait dire — et leur empreinte est recalculée.
 *
 * Deux garanties, comme {@see DetectFoodNatureCommand} :
 *
 * - **aucune correction humaine n'est écrasée** — une découpe portant
 *   `DataOrigin::User` est laissée telle quelle ;
 * - **aucune fusion silencieuse** — si la nouvelle identité en heurte une autre,
 *   la ligne est signalée et laissée intacte. Réunir deux stocks est une décision,
 *   pas un effet de bord.
 *
 * Aucun lot n'est touché.
 *
 * `--dry-run` d'abord, et sa sortie se lit ligne à ligne : une règle qui classe ne
 * se valide pas sur des exemples choisis (leçon n° 30).
 */
#[AsCommand(
    name: 'app:variants:detect-cut',
    description: 'Déduit la découpe des aliments enregistrés et absorbe l\'ancien trait « coupé »',
)]
class DetectFoodCutCommand extends Command
{
    public function __construct(
        private readonly HouseholdProductVariantRepository $variants,
        private readonly FoodCutDetector $detector,
        private readonly VariantResolver $resolver,
        private readonly Connection $connection,
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

        $legacy = $this->variantsStillCarryingTheSlicedTrait();

        $changed = 0;
        $kept = 0;
        $collisions = [];

        foreach ($this->variants->findAll() as $variant) {
            if (DataOrigin::User === $variant->getCutOrigin()) {
                ++$kept;

                continue;
            }

            $wasSliced = isset($legacy[(string) $variant->getId()]);

            // « Coupé » sans autre précision devient « en morceaux » : c'est tout ce
            // que l'ancien trait disait, et le perdre serait perdre une information
            // que quelqu'un avait saisie.
            $cut = $this->detector->detect($variant->getName(), $variant->getCategory())
                ?? ($wasSliced ? FoodCut::Pieces : null);

            $identity = $variant->identity()->withCut($cut);

            if ($identity->hash() === $variant->getIdentityHash()) {
                continue;
            }

            $io->text(sprintf(
                '%s : %s → %s%s',
                $variant->getCulinaryLabel(),
                $variant->getCut()?->label() ?? 'aucune découpe',
                $cut?->label() ?? 'aucune découpe',
                $wasSliced ? ' (reprise de « coupé »)' : '',
            ));

            if (!$dryRun) {
                try {
                    $this->resolver->applyDetectedCut($variant, $cut);
                } catch (StockException $exception) {
                    $collisions[] = sprintf('%s — %s', $variant->getCulinaryLabel(), $exception->getMessage());

                    continue;
                }
            }

            ++$changed;
        }

        foreach ($collisions as $collision) {
            $io->warning($collision);
        }

        $io->success(sprintf(
            '%d aliment(s) %s, %d correction(s) humaine(s) préservée(s), %d conflit(s), 0 lot modifié.',
            $changed,
            $dryRun ? 'recevraient une découpe' : 'ont reçu leur découpe',
            $kept,
            count($collisions),
        ));

        return Command::SUCCESS;
    }

    /**
     * Les variantes dont la colonne `traits` contient encore « sliced ».
     *
     * Lues en SQL et non par l'entité : {@see \App\Enum\ProductTrait::fromValues()}
     * ignore délibérément les valeurs inconnues — ce qui évite tout plantage sur les
     * données existantes, mais rend le trait invisible depuis PHP. C'est justement
     * ici qu'il faut le voir.
     *
     * @return array<string, true>
     */
    private function variantsStillCarryingTheSlicedTrait(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM household_product_variant WHERE jsonb_exists(traits::jsonb, :trait)',
            ['trait' => 'sliced'],
        );

        return array_fill_keys(array_map(strval(...), $ids), true);
    }
}
