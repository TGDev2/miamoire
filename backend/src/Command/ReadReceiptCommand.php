<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Household;
use App\Repository\HouseholdRepository;
use App\Service\GroceryReturn\ReceiptReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Reads real receipts without the phone: what the model transcribed, what
 * the resolver made of it, and which questions the household would be
 * asked. The way to score the reading on one's own shops before trusting
 * it weekly.
 *
 *     php bin/console app:receipt:read photos/ticket.jpg [photos/ticket-2.jpg]
 */
#[AsCommand(
    name: 'app:receipt:read',
    description: 'Lit une ou plusieurs photos de ticket de caisse comme le ferait l\'application',
)]
class ReadReceiptCommand extends Command
{
    public function __construct(
        private readonly ReceiptReader $reader,
        private readonly HouseholdRepository $households,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('photos', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Une à trois photos (JPEG, PNG ou WebP)')
            ->addOption('household', null, InputOption::VALUE_REQUIRED, 'Identifiant du foyer dont les habitudes et libellés appris servent à la lecture ; le premier foyer sinon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $household = $this->household($input->getOption('household'));
        if (null === $household) {
            $io->error('Aucun foyer : créez-en un, ou passez --household=<id>.');

            return Command::FAILURE;
        }

        $images = [];
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        /** @var list<string> $photos */
        $photos = $input->getArgument('photos');
        foreach ($photos as $path) {
            if (!is_file($path)) {
                $io->error(sprintf('Photo introuvable : %s', $path));

                return Command::FAILURE;
            }
            $bytes = (string) file_get_contents($path);
            $images[] = ['mediaType' => (string) $finfo->file($path), 'data' => base64_encode($bytes)];
        }

        $start = microtime(true);
        $reading = $this->reader->read($household, $images);
        $seconds = microtime(true) - $start;

        $io->title(sprintf(
            '%s · %s · total %s · %d lignes, %d écartées · %.1f s',
            $reading->store ?? 'enseigne illisible',
            $reading->receiptDate?->format('Y-m-d') ?? 'date illisible',
            null !== $reading->total ? number_format($reading->total, 2, ',', ' ').' €' : 'illisible',
            count($reading->lines),
            count($reading->ignored),
            $seconds,
        ));

        if (null !== $reading->duplicateOf) {
            $io->warning(sprintf('Ce ticket avait déjà été lu le %s.', $reading->duplicateOf->getReadAt()->format('d/m/Y H:i')));
        }

        $rows = [];
        $questions = 0;
        foreach ($reading->lines as $entry) {
            $line = $entry['line'];
            if ('none' !== $line->decision->value) {
                ++$questions;
            }
            $rows[] = [
                $entry['rawLabel'],
                $line->name,
                sprintf('%d × %s %s', $line->numberOfPackages, null !== $line->quantityPerPackage ? rtrim(rtrim(number_format($line->quantityPerPackage, 3, '.', ''), '0'), '.') : '?', $line->unit->value),
                $line->storageLocation->value.' ('.$line->storageProvenance.')',
                null !== $line->expirationDate ? $line->expirationDate->format('Y-m-d').($line->expirationIsEstimated ? ' ~' : '') : '—',
                null !== $line->variant ? 'oui' : 'non',
                $line->decision->label() ?? '',
            ];
        }
        $io->table(['Libellé imprimé', 'Aliment', 'Quantité', 'Rangement', 'Date', 'Connu', 'Question'], $rows);

        if ([] !== $reading->ignored) {
            $io->section('Écartées (pas de la nourriture)');
            $io->listing(array_map(
                static fn (array $entry): string => $entry['rawLabel'].($entry['learned'] ? ' (appris)' : ''),
                $reading->ignored,
            ));
        }

        $io->success(sprintf('%d questions pour %d lignes.', $questions, count($reading->lines)));

        return Command::SUCCESS;
    }

    private function household(?string $id): ?Household
    {
        if (null !== $id && '' !== $id) {
            return Uuid::isValid($id) ? $this->households->find(Uuid::fromString($id)) : null;
        }

        return $this->households->findOneBy([], ['createdAt' => 'ASC']);
    }
}
