<?php

declare(strict_types=1);

namespace App\Service\Shopping;

use App\Entity\HouseholdProductVariant;
use App\Enum\ShoppingAdviceConfidence;
use App\Enum\ShoppingAdviceKind;

/**
 * Une suggestion du conseiller avant les courses pour une variante.
 *
 * `headline` et `reasoning` sont déjà composés côté serveur, chiffres inclus :
 * contrairement aux motifs d'`UrgencyReason` (des libellés fixes), une suggestion
 * de cette fonctionnalité n'a de sens qu'avec les quantités et délais réels qui la
 * justifient — c'est exactement ce que `tasks/evolutions.md` appelle
 * « le raisonnement ».
 */
final readonly class ShoppingAdvisorCandidate
{
    public function __construct(
        public HouseholdProductVariant $variant,
        public ShoppingAdviceKind $kind,
        public ShoppingAdviceConfidence $confidence,
        public string $headline,
        public string $reasoning,
    ) {
    }
}
