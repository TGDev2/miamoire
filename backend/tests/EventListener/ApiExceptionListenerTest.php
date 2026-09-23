<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use App\Exception\StockConflictException;
use App\Exception\StockException;
use App\Service\Ai\Generator\RecipeGeneratorUnavailableException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Le `match` de ce listener est ordonné, et l'ordre est porteur de sens : une
 * sous-classe placée après son parent se ferait avaler sans que rien ne le
 * signale. Ces tests sont le filet qui rend cette erreur visible.
 */
class ApiExceptionListenerTest extends TestCase
{
    /**
     * Un quota épuisé n'est pas une erreur de saisie. Le 422 générique le
     * noierait parmi la quarantaine d'autres StockException métier, et
     * l'application ne pourrait plus jamais le distinguer.
     */
    public function testAnExhaustedGeneratorIsUnavailableNotUnprocessable(): void
    {
        $response = $this->respondTo(new RecipeGeneratorUnavailableException('Quota quotidien atteint.'));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $response->getStatusCode());
    }

    public function testAnOrdinaryStockExceptionStaysUnprocessable(): void
    {
        $response = $this->respondTo(new StockException('Quantité invalide.'));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
    }

    /** L'autre sous-classe de StockException : la preuve que l'ordre tient pour les deux. */
    public function testAConflictKeepsItsOwnStatus(): void
    {
        $response = $this->respondTo(new StockConflictException('Le stock a bougé.'));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    /**
     * Le client mobile affiche `payload.error` tel quel, quel que soit le
     * statut : le message doit donc traverser intact jusqu'à l'écran.
     */
    public function testTheMessageReachesTheClientVerbatim(): void
    {
        $message = 'Quota quotidien Gemini atteint : il repart à minuit, heure du Pacifique.';

        $response = $this->respondTo(new RecipeGeneratorUnavailableException($message));

        self::assertSame($message, $this->errorOf($response));
    }

    /** Hors de /api, le listener ne se mêle de rien. */
    public function testNonApiRequestsAreLeftToSymfony(): void
    {
        $event = $this->event(new RecipeGeneratorUnavailableException('Quota atteint.'), '/');

        (new ApiExceptionListener(new NullLogger(), false))($event);

        self::assertNull($event->getResponse());
    }

    private function respondTo(\Throwable $exception): Response
    {
        $event = $this->event($exception);

        (new ApiExceptionListener(new NullLogger(), false))($event);

        $response = $event->getResponse();
        self::assertNotNull($response, 'Toute erreur sous /api doit ressortir en JSON.');

        return $response;
    }

    private function event(\Throwable $exception, string $path = '/api/recipes/suggest'): ExceptionEvent
    {
        return new ExceptionEvent(
            $this->createMock(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );
    }

    private function errorOf(Response $response): string
    {
        /** @var array{error?: string} $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $payload['error'] ?? '';
    }
}
