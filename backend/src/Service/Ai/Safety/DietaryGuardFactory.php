<?php

declare(strict_types=1);

namespace App\Service\Ai\Safety;

use App\Entity\Household;
use App\Service\TextNormalizer;

/**
 * Construit le garde alimentaire d'une demande.
 *
 * Les exclusions du foyer s'appliquent toujours ; celles saisies dans l'écran
 * « Cuisiner » les complètent, sans jamais pouvoir les lever. Un invité allergique
 * peut ajouter une contrainte, personne ne peut retirer celle du foyer.
 */
class DietaryGuardFactory
{
    public function __construct(private readonly TextNormalizer $normalizer)
    {
    }

    /** @param list<string> $additional */
    public function for(Household $household, array $additional = []): DietaryGuard
    {
        return new DietaryGuard(
            $this->normalizer,
            array_values(array_unique(array_merge($household->getDietaryExclusions(), $additional))),
        );
    }
}
