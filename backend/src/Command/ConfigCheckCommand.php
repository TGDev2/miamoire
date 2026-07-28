<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refuse de démarrer un conteneur de production mal configuré plutôt que de
 * laisser la panne se découvrir plus tard, en silence (leçon nº 10,
 * `tasks/lessons.md`). Appelée par `docker/entrypoint.sh` avant les migrations :
 * une base jointe avec le mauvais secret ne vaut pas la peine d'être migrée.
 *
 * N'agit qu'en production : en développement et en test, `APP_SECRET`, le CORS
 * permissif et l'absence de `TRUSTED_PROXIES` sont des choix assumés, pas des
 * oublis.
 */
#[AsCommand(
    name: 'app:config:check',
    description: 'Vérifie la configuration de production avant démarrage',
)]
class ConfigCheckCommand extends Command
{
    // Valeur commitée dans backend/.env : un secret que tout le dépôt connaît
    // n'en est plus un dès qu'il signe des sessions en production.
    private const REPOSITORY_APP_SECRET = 'c75c1a10ecc91baab6bd93691d8156124753bcb909c7a0cb282f38ae3bfdbe66';

    public function __construct(
        private readonly string $environment,
        private readonly bool $hasDatabaseUrl,
        private readonly string $appSecret,
        private readonly string $corsAllowOrigin,
        private readonly string $trustedProxies,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' !== $this->environment) {
            $io->comment(sprintf('Environnement "%s" : contrôle réservé à la production.', $this->environment));

            return Command::SUCCESS;
        }

        $fatal = [];

        if (!$this->hasDatabaseUrl) {
            $fatal[] = 'DATABASE_URL est absente : aucune base à joindre.';
        }

        if (self::REPOSITORY_APP_SECRET === $this->appSecret) {
            $fatal[] = 'APP_SECRET est celui commité dans le dépôt : générer un secret propre à ce déploiement (openssl rand -hex 32).';
        }

        if ($this->isPermissiveCorsOrigin($this->corsAllowOrigin)) {
            $io->warning(sprintf(
                'CORS_ALLOW_ORIGIN ("%s") autorise toute origine. Sans risque tant que l\'authentification reste par en-tête (X-Household-Key), à restreindre si un client web apparaît.',
                $this->corsAllowOrigin,
            ));
        }

        if ('' === trim($this->trustedProxies)) {
            $io->warning('TRUSTED_PROXIES est vide : la limitation de débit comptera tous les visiteurs comme un seul derrière le répartiteur de l\'hébergeur. Valeur usuelle : REMOTE_ADDR.');
        }

        if ([] !== $fatal) {
            foreach ($fatal as $message) {
                $io->error($message);
            }

            return Command::FAILURE;
        }

        $io->success('Configuration de production valide.');

        return Command::SUCCESS;
    }

    /**
     * Reproduit le matching de nelmio/cors-bundle
     * (`preg_match('{'.$pattern.'}i', $origin)`) contre une origine
     * imprévisible : si elle matche, le motif accepte n'importe quoi, quel que
     * soit le texte exact utilisé (`^.*$`, `.*`, ...).
     */
    private function isPermissiveCorsOrigin(string $pattern): bool
    {
        if ('' === trim($pattern)) {
            return false;
        }

        $probe = 'https://'.bin2hex(random_bytes(8)).'.invalid';

        return 1 === @preg_match('{'.$pattern.'}i', $probe);
    }
}
