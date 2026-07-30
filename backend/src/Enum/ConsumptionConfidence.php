<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Fiabilité d'un débit de consommation estimé par `ConsumptionRateEstimator`.
 *
 * `Low` existe mais n'est volontairement jamais traduite en suggestion visible
 * (voir `ShoppingAdvisorSelector`) : `tasks/evolutions.md` prévient qu'une
 * mauvaise prédiction coûte plus qu'une absence de prédiction. Elle reste
 * calculée pour rester auditable, pas pour être montrée.
 */
enum ConsumptionConfidence: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
