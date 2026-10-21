<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\StockException;
use App\Service\Ai\Generator\FallbackRecipeGenerator;
use App\Service\Ai\Generator\RecipeGeneratorInterface;
use App\Service\Ai\Generator\RecipeGeneratorUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * La chaîne n'a qu'une règle, et c'est elle qu'on fige ici : avancer sur une
 * indisponibilité, s'arrêter sur tout le reste.
 *
 * Confondre les deux est précisément ce qui faisait d'un quota gratuit épuisé
 * une impasse — et, à l'inverse, rejouer une clé révoquée sur chaque modèle ne
 * ferait que retarder le seul message qui aide.
 */
class FallbackRecipeGeneratorTest extends TestCase
{
    public function testReturnsTheFirstAttemptThatAnswers(): void
    {
        $second = $this->attempt('{"recipes":[]}');
        $chain = $this->chain($this->attempt('{"premier":true}'), $second);

        self::assertSame('{"premier":true}', $chain->generate('sys', 'user', []));
        self::assertSame(0, $second->calls, 'Le secours ne doit pas être sollicité si le principal répond.');
    }

    public function testAdvancesPastASaturatedAttempt(): void
    {
        $chain = $this->chain(
            $this->attempt(new RecipeGeneratorUnavailableException('Quota quotidien atteint.')),
            $this->attempt('{"secours":true}'),
        );

        self::assertSame('{"secours":true}', $chain->generate('sys', 'user', []));
    }

    public function testAdvancesAcrossSeveralSaturatedAttempts(): void
    {
        $chain = $this->chain(
            $this->attempt(new RecipeGeneratorUnavailableException('Saturé.')),
            $this->attempt(new RecipeGeneratorUnavailableException('Saturé aussi.')),
            $this->attempt('{"troisieme":true}'),
        );

        self::assertSame('{"troisieme":true}', $chain->generate('sys', 'user', []));
    }

    /** Une clé révoquée ou un modèle inexistant échouera identiquement partout. */
    public function testStopsOnAFailureThatWouldRepeatElsewhere(): void
    {
        $second = $this->attempt('{"jamais":true}');
        $chain = $this->chain($this->attempt(new StockException('Clé invalide.')), $second);

        try {
            $chain->generate('sys', 'user', []);
            self::fail('Une erreur fatale doit interrompre la chaîne.');
        } catch (StockException $exception) {
            self::assertSame('Clé invalide.', $exception->getMessage());
            self::assertSame(0, $second->calls, 'La tentative suivante ne doit pas être jouée.');
        }
    }

    public function testExhaustedChainReportsTheLastCauseAndTheRemainingRecourse(): void
    {
        $chain = $this->chain(
            $this->attempt(new RecipeGeneratorUnavailableException('Quota quotidien atteint.')),
            $this->attempt(new RecipeGeneratorUnavailableException('Quota du secours atteint.')),
        );

        try {
            $chain->generate('sys', 'user', []);
            self::fail('Une chaîne épuisée doit lever une exception.');
        } catch (RecipeGeneratorUnavailableException $exception) {
            $message = $exception->getMessage();

            self::assertStringContainsString('Quota du secours atteint.', $message);
            self::assertStringContainsString('option de secours a échoué', $message);
            self::assertStringContainsString('recettes enregistrées', $message);
            self::assertInstanceOf(RecipeGeneratorUnavailableException::class, $exception->getPrevious());
        }
    }

    /** Sans secours configuré, le message ne doit pas en inventer un. */
    public function testASingleAttemptDoesNotMentionFallbacks(): void
    {
        $chain = $this->chain($this->attempt(new RecipeGeneratorUnavailableException('Quota atteint.')));

        try {
            $chain->generate('sys', 'user', []);
            self::fail('Une chaîne épuisée doit lever une exception.');
        } catch (RecipeGeneratorUnavailableException $exception) {
            self::assertStringContainsString('Quota atteint.', $exception->getMessage());
            self::assertStringNotContainsString('secours', $exception->getMessage());
        }
    }

    /** La sonde de santé interroge la chaîne : elle doit voir le fournisseur principal. */
    public function testIdentityComesFromTheFirstAttempt(): void
    {
        $chain = $this->chain(
            $this->attempt('{}', label: 'Google Gemini', hint: 'GEMINI_API_KEY'),
            $this->attempt('{}', label: 'Claude', hint: 'ANTHROPIC_API_KEY'),
        );

        self::assertSame('Google Gemini', $chain->label());
        self::assertSame('GEMINI_API_KEY', $chain->configurationHint());
        self::assertTrue($chain->isConfigured());
    }

    private function chain(RecipeGeneratorInterface ...$generators): FallbackRecipeGenerator
    {
        return new FallbackRecipeGenerator(array_values($generators), new NullLogger());
    }

    private function attempt(
        string|\Throwable $outcome,
        string $label = 'Test',
        string $hint = 'TEST_API_KEY',
    ): RecipeGeneratorInterface {
        return new class($outcome, $label, $hint) implements RecipeGeneratorInterface {
            public int $calls = 0;

            public function __construct(
                private readonly string|\Throwable $outcome,
                private readonly string $name,
                private readonly string $hint,
            ) {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function label(): string
            {
                return $this->name;
            }

            public function configurationHint(): string
            {
                return $this->hint;
            }

            public function generate(string $systemPrompt, string $userPayload, array $schema, array $images = []): string
            {
                ++$this->calls;

                if ($this->outcome instanceof \Throwable) {
                    throw $this->outcome;
                }

                return $this->outcome;
            }
        };
    }
}
