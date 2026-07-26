<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\HouseholdAccessKey;
use PHPUnit\Framework\TestCase;

/**
 * Ce code remplace les mots de passe : une faiblesse ici ouvrirait tous les stocks.
 */
class HouseholdAccessKeyTest extends TestCase
{
    private HouseholdAccessKey $keys;

    protected function setUp(): void
    {
        $this->keys = new HouseholdAccessKey();
    }

    public function testGeneratedCodeIsReadableAndValid(): void
    {
        $code = $this->keys->generate();

        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{4}){4}$/', $code);
        self::assertTrue($this->keys->looksValid($code));
    }

    /**
     * Un code se dicte au téléphone. De chaque paire confondable une seule lettre
     * est retirée : 0 (contre O), 1 et I, B (contre 8), S (contre 5). Garder 8 et 5
     * est donc volontaire — leur sosie a disparu.
     */
    public function testAlphabetAvoidsVisuallyAmbiguousCharacters(): void
    {
        $joined = '';
        for ($i = 0; $i < 40; ++$i) {
            $joined .= $this->keys->generate();
        }

        foreach (['O', '0', 'I', '1', 'B', 'S'] as $ambiguous) {
            self::assertStringNotContainsString($ambiguous, $joined);
        }
    }

    public function testTwoCodesAreNeverTheSame(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; ++$i) {
            $codes[] = $this->keys->generate();
        }

        self::assertCount(200, array_unique($codes));
    }

    /** La base ne doit jamais contenir le code lui-même. */
    public function testHashNeverContainsTheCode(): void
    {
        $code = $this->keys->generate();
        $hash = $this->keys->hash($code);

        self::assertSame(64, strlen($hash));
        self::assertStringNotContainsString($this->keys->normalize($code), $hash);
    }

    public function testMatchingAcceptsTheCodeAndRejectsEverythingElse(): void
    {
        $code = $this->keys->generate();
        $hash = $this->keys->hash($code);

        self::assertTrue($this->keys->matches($code, $hash));
        self::assertFalse($this->keys->matches($this->keys->generate(), $hash));
        self::assertFalse($this->keys->matches('', $hash));
    }

    /** Ce qu'un humain produit en recopiant depuis un SMS doit fonctionner. */
    public function testMatchingToleratesCaseSpacesAndDashes(): void
    {
        $code = $this->keys->generate();
        $hash = $this->keys->hash($code);

        self::assertTrue($this->keys->matches(strtolower($code), $hash));
        self::assertTrue($this->keys->matches(str_replace('-', '', $code), $hash));
        self::assertTrue($this->keys->matches(' '.str_replace('-', ' ', $code).' ', $hash));
    }

    /** Une saisie tronquée est écartée avant toute lecture en base. */
    public function testTruncatedOrForeignCodesAreNotEvenValidLooking(): void
    {
        self::assertFalse($this->keys->looksValid('K7M2'));
        self::assertFalse($this->keys->looksValid(''));
        self::assertFalse($this->keys->looksValid('K7M2-9QXR-4TVB-8NHD-3JPC-EXTRA'));
        // « 0 » et « I » n'appartiennent pas à l'alphabet : la saisie est fautive.
        self::assertFalse($this->keys->looksValid('K7M2-9QXR-4TVB-8NHD-30IC'));
    }
}
