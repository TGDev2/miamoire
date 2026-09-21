<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\IncompatibleUnitException;
use App\Exception\StockConflictException;
use App\Exception\StockException;
use App\Service\OpenFoodFacts\OpenFoodFactsUnavailableException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Toute erreur sous /api ressort en JSON, avec un message exploitable côté mobile. */
#[AsEventListener(event: 'kernel.exception')]
class ApiExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();

        [$status, $message] = match (true) {
            // Un conflit n'est pas une erreur de saisie : le stock a bougé entre
            // la proposition et la confirmation. 409 le dit, 422 le cacherait.
            $exception instanceof StockConflictException => [Response::HTTP_CONFLICT, $exception->getMessage()],
            $exception instanceof IncompatibleUnitException,
            $exception instanceof StockException => [Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getMessage()],
            // Pas un 404 : « produit inconnu » ouvrirait la saisie manuelle d'un
            // produit qu'Open Food Facts connaît peut-être. 503 dit de réessayer.
            $exception instanceof OpenFoodFactsUnavailableException => [
                Response::HTTP_SERVICE_UNAVAILABLE,
                'Open Food Facts ne répond pas. Réessayez dans un instant.',
            ],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), $exception->getMessage()],
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, 'Une erreur interne est survenue.'],
        };

        // Une panne d'Open Food Facts est prévue, et déjà journalisée par son client.
        if ($status >= 500 && !$exception instanceof OpenFoodFactsUnavailableException) {
            $this->logger->error('Erreur API non gérée', ['exception' => $exception]);
        }

        $payload = ['error' => $message];

        // Le contexte d'un conflit porte la proposition recalculée : l'application
        // peut proposer ce qui reste possible plutôt qu'un simple échec.
        if ($exception instanceof StockConflictException) {
            $payload += $exception->getContext();
        }

        if ($this->debug && $status >= 500) {
            $payload['detail'] = $exception->getMessage();
            $payload['class'] = $exception::class;
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }
}
