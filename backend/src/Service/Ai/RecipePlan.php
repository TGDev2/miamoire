<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\RecipePlanStatus;

/**
 * L'état réel d'une recette, tel que recalculé À L'INSTANT de l'appel.
 *
 * Jamais persisté tel quel : une recette enregistrée ne conserve que le
 * document qui a produit ce plan, pas le plan lui-même, qui serait périmé dès
 * le prochain changement de stock, d'exclusion ou de fiche produit.
 */
final readonly class RecipePlan
{
    /**
     * @param list<RecipePlanLine> $lines
     * @param list<string>         $checkedExclusions exclusions strictes réellement
     *                                                 confrontées à la composition des produits
     */
    public function __construct(
        public RecipePlanStatus $status,
        public ?string $reason,
        public array $lines,
        public array $checkedExclusions,
        public \DateTimeImmutable $evaluatedAt,
    ) {
    }
}
