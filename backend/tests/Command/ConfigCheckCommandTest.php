<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ConfigCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class ConfigCheckCommandTest extends TestCase
{
    /** En dehors de la production, des valeurs autrement fatales ne bloquent rien. */
    public function testIgnoredOutsideProduction(): void
    {
        $tester = $this->check(environment: 'dev', hasDatabaseUrl: false, appSecret: self::REPOSITORY_SECRET);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    /** Vérification par mutation (leçon nº 16) : sans DATABASE_URL, le contrôle doit être rouge. */
    public function testFailsWhenDatabaseUrlIsMissing(): void
    {
        $tester = $this->check(hasDatabaseUrl: false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DATABASE_URL', $tester->getDisplay());
    }

    /** Vérification par mutation : le secret commité dans le dépôt doit être rouge en production. */
    public function testFailsWhenAppSecretIsTheRepositorySecret(): void
    {
        $tester = $this->check(appSecret: self::REPOSITORY_SECRET);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('APP_SECRET', $tester->getDisplay());
    }

    public function testFailsOnBothFatalChecksAtOnce(): void
    {
        $tester = $this->check(hasDatabaseUrl: false, appSecret: self::REPOSITORY_SECRET);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('DATABASE_URL', $tester->getDisplay());
        self::assertStringContainsString('APP_SECRET', $tester->getDisplay());
    }

    public function testWarnsButSucceedsOnPermissiveCors(): void
    {
        $tester = $this->check(corsAllowOrigin: '^.*$');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('CORS_ALLOW_ORIGIN', $tester->getDisplay());
    }

    /** Un motif restrictif ne doit jamais être confondu avec un motif permissif. */
    public function testDoesNotWarnOnARestrictiveCorsPattern(): void
    {
        $tester = $this->check(corsAllowOrigin: '^https://app\.exemple\.dev$');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('CORS_ALLOW_ORIGIN', $tester->getDisplay());
    }

    public function testWarnsButSucceedsOnEmptyTrustedProxies(): void
    {
        $tester = $this->check(trustedProxies: '');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('TRUSTED_PROXIES', $tester->getDisplay());
    }

    public function testSucceedsSilentlyOnHealthyConfiguration(): void
    {
        $tester = $this->check();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('CORS_ALLOW_ORIGIN', $tester->getDisplay());
        self::assertStringNotContainsString('TRUSTED_PROXIES', $tester->getDisplay());
    }

    private const REPOSITORY_SECRET = 'c75c1a10ecc91baab6bd93691d8156124753bcb909c7a0cb282f38ae3bfdbe66';

    private function check(
        string $environment = 'prod',
        bool $hasDatabaseUrl = true,
        string $appSecret = 'un-secret-propre-a-ce-deploiement',
        string $corsAllowOrigin = '^https://app\.exemple\.dev$',
        string $trustedProxies = 'REMOTE_ADDR',
    ): CommandTester {
        $command = new ConfigCheckCommand($environment, $hasDatabaseUrl, $appSecret, $corsAllowOrigin, $trustedProxies);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
