<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Anthropic\Client;
use App\Exception\StockException;
use App\Service\Ai\Generator\ClaudeRecipeGenerator;
use App\Service\Ai\Generator\GeminiRecipeGenerator;
use App\Service\Ai\Generator\RecipeGeneratorFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class RecipeGeneratorFactoryTest extends TestCase
{
    public function testSelectsGeminiByDefault(): void
    {
        $generator = $this->factory('gemini', geminiKey: 'AIza-test')->create();

        self::assertSame('Google Gemini', $generator->label());
    }

    public function testSelectsClaudeWhenRequested(): void
    {
        $generator = $this->factory('claude', anthropicKey: 'sk-ant-test')->create();

        self::assertSame('Claude', $generator->label());
    }

    /** Une valeur vide ne doit pas casser l'application : on retombe sur le gratuit. */
    public function testFallsBackToGeminiWhenProviderIsEmpty(): void
    {
        $generator = $this->factory('', geminiKey: 'AIza-test')->create();

        self::assertSame('Google Gemini', $generator->label());
    }

    public function testProviderIsCaseInsensitive(): void
    {
        self::assertSame('Claude', $this->factory('  CLAUDE ', anthropicKey: 'sk-ant-test')->create()->label());
    }

    public function testUnknownProviderIsRejected(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessageMatches('/inconnu/');

        $this->factory('chatgpt')->create();
    }

    /** Sans clé, l'utilisateur doit lire quoi renseigner, pas une erreur technique. */
    public function testMissingKeyNamesTheEnvironmentVariable(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessageMatches('/GEMINI_API_KEY/');

        $this->factory('gemini')->create();
    }

    public function testMissingClaudeKeyNamesItsOwnVariable(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessageMatches('/ANTHROPIC_API_KEY/');

        $this->factory('claude')->create();
    }

    private function factory(string $provider, string $geminiKey = '', string $anthropicKey = ''): RecipeGeneratorFactory
    {
        return new RecipeGeneratorFactory(
            new GeminiRecipeGenerator(
                $this->createMock(HttpClientInterface::class),
                new NullLogger(),
                $geminiKey,
                'gemini-3.6-flash',
            ),
            new ClaudeRecipeGenerator(
                new Client(apiKey: $anthropicKey),
                new NullLogger(),
                'medium',
                $anthropicKey,
            ),
            $provider,
        );
    }
}
