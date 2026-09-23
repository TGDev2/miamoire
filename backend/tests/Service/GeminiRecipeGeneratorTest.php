<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\StockException;
use App\Service\Ai\Generator\GeminiRecipeGenerator;
use App\Service\Ai\Generator\RecipeGeneratorUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * La réponse REST de Gemini n'expose pas `output_text` (c'est une commodité des
 * SDK) et son premier step est un `thought` sans contenu. Ces cas sont figés ici
 * parce qu'ils ont réellement cassé l'intégration.
 */
class GeminiRecipeGeneratorTest extends TestCase
{
    public function testReadsTextFromStepsSkippingTheThoughtStep(): void
    {
        $generator = $this->generator(200, [
            'status' => 'completed',
            'steps' => [
                ['type' => 'thought', 'signature' => 'abc'],
                ['type' => 'model_output', 'content' => [['type' => 'text', 'text' => '{"recipes":[]}']]],
            ],
        ]);

        self::assertSame('{"recipes":[]}', $generator->generate('sys', 'user', []));
    }

    public function testConcatenatesMultipleTextBlocks(): void
    {
        $generator = $this->generator(200, [
            'steps' => [[
                'type' => 'model_output',
                'content' => [
                    ['type' => 'text', 'text' => '{"recipes"'],
                    ['type' => 'text', 'text' => ':[]}'],
                ],
            ]],
        ]);

        self::assertSame('{"recipes":[]}', $generator->generate('sys', 'user', []));
    }

    public function testStillHonoursOutputTextIfTheApiEverProvidesIt(): void
    {
        $generator = $this->generator(200, ['output_text' => '{"recipes":[]}']);

        self::assertSame('{"recipes":[]}', $generator->generate('sys', 'user', []));
    }

    public function testEmptyResponseIsReportedNotSwallowed(): void
    {
        $generator = $this->generator(200, ['status' => 'completed', 'steps' => [['type' => 'thought']]]);

        $this->expectException(StockException::class);
        $generator->generate('sys', 'user', []);
    }

    public function testQuotaExhaustedGivesAnActionableMessage(): void
    {
        $generator = $this->generator(429, ['error' => ['message' => 'Quota exceeded']]);

        $this->expectException(StockException::class);
        $this->expectExceptionMessageMatches('/[Qq]uota/');
        $generator->generate('sys', 'user', []);
    }

    public function testInvalidKeyGivesAnActionableMessage(): void
    {
        $generator = $this->generator(403, ['error' => ['message' => 'Forbidden']]);

        $this->expectException(StockException::class);
        $this->expectExceptionMessageMatches('/GEMINI_API_KEY/');
        $generator->generate('sys', 'user', []);
    }

    public function testUnknownModelPointsAtTheRightSetting(): void
    {
        $generator = $this->generator(400, ['error' => ['message' => 'model not found']]);

        $this->expectException(StockException::class);
        $this->expectExceptionMessageMatches('/GEMINI_MODEL/');
        $generator->generate('sys', 'user', []);
    }

    /**
     * Le partage des rôles : ce qui se contourne ailleurs et ce qui ne se
     * contourne pas. C'est cette distinction qui autorise la chaîne à avancer.
     */
    public function testSaturationIsReportedAsTemporary(): void
    {
        $this->expectException(RecipeGeneratorUnavailableException::class);
        $this->generator(429, ['error' => ['message' => 'Quota exceeded']])->generate('sys', 'user', []);
    }

    public function testServerFailureIsReportedAsTemporary(): void
    {
        $this->expectException(RecipeGeneratorUnavailableException::class);
        $this->generator(503, ['error' => ['message' => 'Service unavailable']])->generate('sys', 'user', []);
    }

    public function testInvalidKeyIsNotTemporary(): void
    {
        $generator = $this->generator(403, ['error' => ['message' => 'Forbidden']]);

        try {
            $generator->generate('sys', 'user', []);
            self::fail('Une clé refusée doit lever une exception.');
        } catch (StockException $exception) {
            // Rejouer une clé révoquée sur un autre modèle ne ferait que retarder
            // le seul message qui aide l'utilisateur.
            self::assertNotInstanceOf(RecipeGeneratorUnavailableException::class, $exception);
        }
    }

    public function testUnknownModelIsNotTemporary(): void
    {
        $generator = $this->generator(400, ['error' => ['message' => 'model not found']]);

        try {
            $generator->generate('sys', 'user', []);
            self::fail('Un modèle inexistant doit lever une exception.');
        } catch (StockException $exception) {
            self::assertNotInstanceOf(RecipeGeneratorUnavailableException::class, $exception);
        }
    }

    /**
     * Le quota du jour et celui de la minute n'appellent pas la même attente.
     *
     * Google joint un `retryDelay` de quelques secondes même à un quota
     * journalier, qui ne repart qu'à minuit : s'y fier ferait réessayer en
     * boucle toute la journée. Seul `quotaId` dit la vérité.
     */
    public function testDailyQuotaSaysWhenItComesBack(): void
    {
        $generator = $this->generator(429, $this->quotaFailure('GenerateRequestsPerDayPerProjectPerModel-FreeTier'));

        $this->expectException(RecipeGeneratorUnavailableException::class);
        $this->expectExceptionMessageMatches('/minuit/');
        $generator->generate('sys', 'user', []);
    }

    public function testPerMinuteQuotaDoesNotClaimTheDayIsOver(): void
    {
        $generator = $this->generator(429, $this->quotaFailure('GenerateRequestsPerMinutePerProjectPerModel-FreeTier'));

        try {
            $generator->generate('sys', 'user', []);
            self::fail('Un 429 doit lever une exception.');
        } catch (RecipeGeneratorUnavailableException $exception) {
            // Annoncer minuit sur une limite par minute ferait attendre des
            // heures là où quelques secondes suffisent.
            self::assertStringNotContainsString('minuit', $exception->getMessage());
        }
    }

    /** `details` est optionnel et non ordonné : sa lecture ne doit jamais casser. */
    public function testMalformedQuotaDetailsFallBackToTheGenericMessage(): void
    {
        $generator = $this->generator(429, ['error' => [
            'message' => 'Quota exceeded',
            'details' => [
                'pas un tableau associatif',
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '34s'],
                ['@type' => 'type.googleapis.com/google.rpc.QuotaFailure', 'violations' => 'cassé'],
            ],
        ]]);

        $this->expectException(RecipeGeneratorUnavailableException::class);
        $this->expectExceptionMessageMatches('/[Qq]uota/');
        $generator->generate('sys', 'user', []);
    }

    public function testModelsAreReadInOrderIgnoringBlanks(): void
    {
        $generator = $this->generator(200, ['output_text' => '{}'], 'gemini-3.6-flash, , gemini-3.5-flash-lite ');

        self::assertSame(['gemini-3.6-flash', 'gemini-3.5-flash-lite'], $generator->models());
    }

    public function testASingleModelStaysASingleAttempt(): void
    {
        self::assertSame(['gemini-3.6-flash'], $this->generator(200, [])->models());
    }

    public function testWithModelNarrowsTheInstanceToThatModel(): void
    {
        $generator = $this->generator(200, ['output_text' => '{}'], 'gemini-3.6-flash,gemini-3.5-flash-lite');

        self::assertSame(['gemini-3.5-flash-lite'], $generator->withModel('gemini-3.5-flash-lite')->models());
    }

    /** Une instance restreinte doit réellement interroger le modèle demandé. */
    public function testTheNarrowedModelIsTheOneSentToTheApi(): void
    {
        $sent = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode($options['body'], true, 512, \JSON_THROW_ON_ERROR)['model'] ?? null;

            return new MockResponse(
                json_encode(['output_text' => '{}'], \JSON_THROW_ON_ERROR),
                ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
            );
        });

        $generator = new GeminiRecipeGenerator($client, new NullLogger(), 'test-key', 'gemini-3.6-flash,gemini-3.5-flash-lite');
        $generator->withModel('gemini-3.5-flash-lite')->generate('sys', 'user', []);

        self::assertSame('gemini-3.5-flash-lite', $sent);
    }

    /**
     * Le corps réel d'un 429, tel que l'API le renvoie : le `quotaId` porte la
     * portée du compteur, et le `retryDelay` ment sur les quotas journaliers.
     *
     * @return array<string, mixed>
     */
    private function quotaFailure(string $quotaId): array
    {
        return ['error' => [
            'code' => 429,
            'status' => 'RESOURCE_EXHAUSTED',
            'message' => 'You exceeded your current quota.',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '34s'],
                [
                    '@type' => 'type.googleapis.com/google.rpc.QuotaFailure',
                    'violations' => [[
                        'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests',
                        'quotaId' => $quotaId,
                        'quotaDimensions' => ['location' => 'global', 'model' => 'gemini-3.6-flash'],
                    ]],
                ],
            ],
        ]];
    }

    /** @param array<string, mixed> $body */
    private function generator(int $status, array $body, string $model = 'gemini-3.6-flash'): GeminiRecipeGenerator
    {
        $client = new MockHttpClient(new MockResponse(
            json_encode($body, \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        ));

        return new GeminiRecipeGenerator($client, new NullLogger(), 'test-key', $model);
    }
}
