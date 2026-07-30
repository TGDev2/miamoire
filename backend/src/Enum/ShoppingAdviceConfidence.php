<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Le niveau de confiance affiché avec chaque suggestion — jamais caché,
 * conformément à `tasks/evolutions.md` (Évolution 3) : l'utilisateur doit pouvoir
 * juger une prévision, pas seulement la lire.
 *
 * `Certaine` : constatée à partir de faits présents (péremption, plan de repas,
 * comparaison à une habitude d'achat déjà enregistrée) — aucune prévision.
 * `Elevee`/`Moyenne` : dérivée d'un rythme de consommation estimé
 * ({@see ConsumptionConfidence}) — jamais émise si l'historique est trop mince,
 * voir la doc de `ConsumptionRateEstimator`.
 */
enum ShoppingAdviceConfidence: string
{
    case Certaine = 'certaine';
    case Elevee = 'elevee';
    case Moyenne = 'moyenne';

    public function label(): string
    {
        return match ($this) {
            self::Certaine => 'Confiance certaine',
            self::Elevee => 'Confiance élevée',
            self::Moyenne => 'Confiance moyenne',
        };
    }
}
