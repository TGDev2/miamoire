<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Le stock a changé entre la proposition et sa confirmation.
 *
 * Ce n'est pas une erreur de saisie mais un conflit : quelqu'un d'autre — ou le
 * même utilisateur depuis un autre téléphone — a consommé entre-temps. Le contexte
 * transporte la proposition recalculée, pour que l'application montre ce qui est
 * encore possible au lieu d'un message d'échec sec.
 */
class StockConflictException extends StockException
{
    /** @param array<string, mixed> $context */
    public function __construct(string $message, private readonly array $context = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function getContext(): array
    {
        return $this->context;
    }
}
