<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\IncompatibleUnitException;
use App\Exception\StockException;
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
            $exception instanceof IncompatibleUnitException,
            $exception instanceof StockException => [Response::HTTP_UNPROCESSABLE_ENTITY, $exception->getMessage()],
            $exception instanceof HttpExceptionInterface => [$exception->getStatusCode(), $exception->getMessage()],
            default => [Response::HTTP_INTERNAL_SERVER_ERROR, 'Une erreur interne est survenue.'],
        };

        if ($status >= 500) {
            $this->logger->error('Erreur API non gérée', ['exception' => $exception]);
        }

        $payload = ['error' => $message];

        if ($this->debug && $status >= 500) {
            $payload['detail'] = $exception->getMessage();
            $payload['class'] = $exception::class;
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }
}
