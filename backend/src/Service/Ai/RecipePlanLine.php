<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\RecipePlanStatus;

/** Le statut d'un ingrédient précis dans le plan de préparation d'une recette. */
final readonly class RecipePlanLine
{
    public function __construct(
        public string $key,
        public string $label,
        public RecipePlanStatus $status,
        public ?string $detail = null,
    ) {
    }
}
