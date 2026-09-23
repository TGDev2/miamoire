<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Anthropic\Client;
use App\Exception\StockException;
use App\Service\Ai\Generator\ClaudeRecipeGenerator;
use App\Service\Ai\Generator\FallbackRecipeGenerator;
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

    /** Une seule tentative n'a rien à enchaîner : pas d'emballage inutile. */
    public function testASingleModelIsNotWrappedInAChain(): void
    {
        $generator = $this->factory('gemini', geminiKey: 'AIza-test')->create();

        self::assertInstanceOf(GeminiRecipeGenerator::class, $generator);
    }

    /**
     * Le cœur du secours gratuit : les quotas du palier gratuit se comptent par
     * modèle, donc un second modèle suffit à sortir de l'impasse.
     */
    public function testSeveralModelsBecomeAChain(): void
    {
        $factory = $this->factory(
            'gemini',
            geminiKey: 'AIza-test',
            model: 'gemini-3.6-flash,gemini-3.5-flash-lite',
        );

        self::assertInstanceOf(FallbackRecipeGenerator::class, $factory->create());
    }

    public function testProvidersChainAfterTheirModels(): void
    {
        $factory = $this->factory(
            'gemini,claude',
            geminiKey: 'AIza-test',
            anthropicKey: 'sk-ant-test',
        );

        $chain = $factory->create();

        self::assertInstanceOf(FallbackRecipeGenerator::class, $chain);
        self::assertSame('Google Gemini', $chain->label(), 'Le principal reste en tête.');
    }

    /** Nommer Claude en secours ne doit rien casser tant que sa clé est absente. */
    public function testAProviderWithoutItsKeyIsDroppedFromTheChain(): void
    {
        $generator = $this->factory('gemini,claude', geminiKey: 'AIza-test')->create();

        self::assertInstanceOf(GeminiRecipeGenerator::class, $generator);
    }

    public function testTheChainSkipsStraightToTheProviderThatHasAKey(): void
    {
        $generator = $this->factory('gemini,claude', anthropicKey: 'sk-ant-test')->create();

        self::assertSame('Claude', $generator->label());
    }

    public function testDuplicateProvidersAreCollapsed(): void
    {
        $generator = $this->factory('gemini, gemini ', geminiKey: 'AIza-test')->create();

        self::assertInstanceOf(GeminiRecipeGenerator::class, $generator);
    }

    /** Rien de configuré : le message nomme le fournisseur principal, pas le dernier. */
    public function testNothingConfiguredNamesTheFirstProvider(): void
    {
        $this->expectException(StockException::class);
        $this->expectExceptionMessageMatches('/GEMINI_API_KEY/');

        $this->factory('gemini,claude')->create();
    }

    private function factory(
        string $provider,
        string $geminiKey = '',
        string $anthropicKey = '',
        string $model = 'gemini-3.6-flash',
    ): RecipeGeneratorFactory {
        return new RecipeGeneratorFactory(
            new GeminiRecipeGenerator(
                $this->createMock(HttpClientInterface::class),
                new NullLogger(),
                $geminiKey,
                $model,
            ),
            new ClaudeRecipeGenerator(
                new Client(apiKey: $anthropicKey),
                new NullLogger(),
                'medium',
                $anthropicKey,
            ),
            new NullLogger(),
            $provider,
        );
    }
}
