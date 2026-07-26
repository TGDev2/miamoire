<?php

declare(strict_types=1);

namespace App\Service\Ai\Safety;

/** Ce qui a été trouvé, où, et au titre de quelle exclusion. */
final readonly class DietaryViolation
{
    public function __construct(
        /** L'exclusion demandée par le foyer, telle qu'elle s'affiche. */
        public string $exclusion,
        /** Le terme effectivement rencontré — « beurre de cacahuète », pas « arachide ». */
        public string $matched,
        /** Où : « étape 3 », « ingrédient manquant », « proposition de consommation ». */
        public string $location,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s (« %s » dans %s)', $this->exclusion, $this->matched, $this->location);
    }
}
