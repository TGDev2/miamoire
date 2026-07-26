<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\HouseholdRepository;
use App\Service\HouseholdAccessKey;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Délivre un code d'accès aux foyers créés avant l'ouverture de l'API.
 *
 * La commande AFFICHE les codes : c'est le seul instant où ils sont lisibles,
 * la base n'en garde qu'une empreinte. Les noter avant de fermer le terminal.
 *
 * Les foyers qui ont déjà un code ne sont jamais touchés — le régénérer
 * déconnecterait les téléphones qui l'utilisent.
 */
#[AsCommand(
    name: 'app:households:issue-keys',
    description: 'Délivre un code d\'accès aux foyers qui n\'en ont pas encore',
)]
class IssueHouseholdKeysCommand extends Command
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly HouseholdAccessKey $accessKeys,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pending = $this->households->findWithoutAccessKey();

        if ([] === $pending) {
            $io->success('Tous les foyers ont déjà un code d\'accès.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($pending as $household) {
            $code = $this->accessKeys->generate();
            $household->setAccessKeyHash($this->accessKeys->hash($code));
            $rows[] = [$household->getName(), $code];
        }

        $this->entityManager->flush();

        $io->table(['Foyer', 'Code d\'accès'], $rows);
        $io->warning('Notez ces codes maintenant : ils ne sont plus affichables ensuite.');

        return Command::SUCCESS;
    }
}
