<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\StockException;
use App\Service\Ai\Generator\GeminiRecipeGenerator;
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

    /** @param array<string, mixed> $body */
    private function generator(int $status, array $body): GeminiRecipeGenerator
    {
        $client = new MockHttpClient(new MockResponse(
            json_encode($body, \JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        ));

        return new GeminiRecipeGenerator($client, new NullLogger(), 'test-key', 'gemini-3.6-flash');
    }
}
