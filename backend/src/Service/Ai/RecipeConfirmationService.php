<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockOperation;
use App\Enum\RecipePlanStatus;
use App\Enum\StockOperationType;
use App\Exception\StockConflictException;
use App\Exception\StockException;
use App\Repository\HouseholdProductVariantRepository;
use App\Service\Ai\Safety\DietaryGuardFactory;
use App\Service\Stock\ConsumptionLine;
use App\Service\Stock\FefoAllocator;
use App\Service\Stock\FefoPlan;
use App\Service\Stock\ProposalConsolidator;
use App\Service\Stock\StockOperationGuard;
use App\Service\Stock\StockService;

/**
 * Applique une proposition de consommation APRÈS confirmation explicite de l'utilisateur.
 *
 * C'est la seule porte par laquelle une recette peut toucher au stock. L'idempotence,
 * l'atomicité et le rejeu sont portés par `StockOperationGuard` (partagé avec les
 * mutations de stock ordinaires) ; cette classe se concentre sur ce qui lui est
 * propre :
 *
 * 1. **Atomicité du plan.** Les lots sont verrouillés, FEFO est recalculé À
 *    L'INTÉRIEUR de la transaction, et un plan devenu incomplet n'écrit rien du
 *    tout — la vérification précède toujours l'application.
 * 2. **Honnêteté.** Les lignes en double sont fusionnées avant planification, si
 *    bien que la quantité annoncée comme appliquée est celle qui a réellement été
 *    retirée — le plafonnement silencieux ne peut plus mentir.
 * 3. **Sûreté alimentaire.** Les exclusions du foyer sont revérifiées ici : une
 *    proposition ancienne, ou forgée, ne peut pas déduire un aliment exclu.
 */
class RecipeConfirmationService
{
    public function __construct(
        private readonly FefoAllocator $allocator,
        private readonly StockService $stockService,
        private readonly ProposalConsolidator $consolidator,
        private readonly HouseholdProductVariantRepository $variants,
        private readonly StockOperationGuard $operationGuard,
        private readonly DietaryGuardFactory $guards,
        private readonly RecipePlanService $planner,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $proposal
     * @param array<string, mixed>       $recipe    le document revérifié — celui enregistré,
     *                                              ou celui tout juste normalisé
     *
     * @return array<string, mixed>
     */
    public function confirm(
        Household $household,
        Member $author,
        array $proposal,
        ?string $recipeName,
        string $operationKey,
        array $recipe,
    ): array {
        $lines = $this->consolidator->consolidate($proposal);
        $requestHash = $this->consolidator->hash($lines, $recipeName);

        /** @var list<array{line: ConsumptionLine, variant: HouseholdProductVariant}> $targets */
        $targets = $this->resolveTargets($household, $lines);
        $this->assertAllowed($household, $recipe);

        return $this->operationGuard->run(
            $household,
            $operationKey,
            StockOperationType::RecipeConfirmation,
            $requestHash,
            $author,
            $this->stockService,
            fn (StockOperation $operation): array => $this->applyWithin($operation, $targets, $author, $recipeName),
        );
    }

    /**
     * Le cœur transactionnel.
     *
     * Tout est planifié — et vérifié complet — AVANT la moindre écriture : une
     * fois la boucle de consommation commencée, plus rien ne doit pouvoir lever
     * d'exception, sous peine de faire valider une déduction partielle par
     * `StockOperationGuard`, qui traite tout rejet survenant ici comme un rejet
     * propre plutôt que comme une écriture à moitié faite.
     *
     * @param list<array{line: ConsumptionLine, variant: HouseholdProductVariant}> $targets
     *
     * @return array<string, mixed>
     *
     * @throws StockConflictException si le plan n'est plus réalisable
     */
    private function applyWithin(
        StockOperation $operation,
        array $targets,
        Member $author,
        ?string $recipeName,
    ): array {
        /** @var list<array{line: ConsumptionLine, variant: HouseholdProductVariant, plan: FefoPlan}> $plans */
        $plans = [];
        $shortages = [];

        foreach ($targets as $target) {
            $plan = $this->allocator->planLocked(
                $target['variant'],
                $target['line']->quantity,
                $target['line']->unit,
            );

            if (!$plan->isComplete()) {
                $shortages[] = [
                    'key' => $target['variant']->getSlug(),
                    'label' => $target['variant']->getCulinaryLabel(),
                    'requested' => $plan->requested,
                    'available' => $plan->allocated,
                    'unit' => $plan->unit->value,
                ];
                continue;
            }

            $plans[] = ['line' => $target['line'], 'variant' => $target['variant'], 'plan' => $plan];
        }

        // Tout ou rien : une recette à moitié déduite laisse un stock faux et un
        // utilisateur qui ne sait pas ce qu'il lui reste.
        if ([] !== $shortages) {
            throw new StockConflictException(
                'Le stock a changé depuis la proposition : rien n\'a été retiré. Voici ce qui est encore disponible.',
                ['proposal' => $shortages],
            );
        }

        $reason = null !== $recipeName && '' !== $recipeName
            ? sprintf('Recette : %s', $recipeName)
            : 'Recette confirmée';

        foreach ($plans as $entry) {
            foreach ($entry['plan']->allocations as $allocation) {
                $this->stockService->consume(
                    $allocation->item,
                    $allocation->quantity,
                    $allocation->unit,
                    $author,
                    $reason,
                    $operation,
                );
            }
        }

        return $this->describe($operation, $plans, $recipeName);
    }

    /**
     * @param list<array{line: ConsumptionLine, variant: HouseholdProductVariant, plan: FefoPlan}> $plans
     *
     * @return array<string, mixed>
     */
    private function describe(StockOperation $operation, array $plans, ?string $recipeName): array
    {
        return [
            'recipeName' => $recipeName,
            'operationKey' => $operation->getOperationKey(),
            'replayed' => false,
            'lines' => array_map(
                static function (array $entry): array {
                    $plan = $entry['plan'];

                    return [
                        'key' => $entry['variant']->getSlug(),
                        'label' => $entry['variant']->getCulinaryLabel(),
                        'requested' => $plan->requested,
                        'applied' => $plan->allocated,
                        'shortfall' => $plan->shortfall,
                        'unit' => $plan->unit->value,
                        // Prévient que la déduction s'appuie sur une quantité
                        // approximative : elle mérite d'être vérifiée après le repas.
                        'quantityWasEstimated' => $plan->drawsOnEstimatedQuantity(),
                        'lots' => array_map(
                            static fn ($allocation): array => [
                                'stockItemId' => (string) $allocation->item->getId(),
                                'quantity' => $allocation->quantity,
                                'unit' => $allocation->unit->value,
                                'expirationDate' => $allocation->item->getExpirationDate()?->format('Y-m-d'),
                                'expirationIsEstimated' => $allocation->item->isExpirationEstimated(),
                            ],
                            $plan->allocations,
                        ),
                    ];
                },
                $plans,
            ),
        ];
    }

    /**
     * @param list<ConsumptionLine> $lines
     *
     * @return list<array{line: ConsumptionLine, variant: HouseholdProductVariant}>
     */
    private function resolveTargets(Household $household, array $lines): array
    {
        $targets = [];
        $errors = [];

        foreach ($lines as $line) {
            $variant = $this->variants->findOneBySlug($household, $line->key);

            if (null === $variant) {
                $errors[] = sprintf('« %s » ne correspond à aucun aliment de votre inventaire.', $line->label);
                continue;
            }

            if ($variant->getUnitFamily() !== $line->unit->family()) {
                $errors[] = sprintf(
                    '« %s » se compte en %s : une demande en %s ne s\'y applique pas.',
                    $variant->getCulinaryLabel(),
                    mb_strtolower($variant->getUnitFamily()->label()),
                    $line->unit->label(),
                );
                continue;
            }

            $targets[] = ['line' => $line, 'variant' => $variant];
        }

        if ([] !== $errors) {
            throw new StockException(implode(' ', $errors));
        }

        return $targets;
    }

    /**
     * Dernier rempart alimentaire, exécuté juste avant l'écriture.
     *
     * Le filtrage à la génération ne suffit pas : rien n'empêche une application
     * de confirmer une proposition reçue avant qu'une allergie ne soit déclarée,
     * ou une recette enregistrée des mois plus tôt dont un produit a changé de
     * composition depuis. Le plan de préparation revérifie donc le document
     * complet — exclusions lexicales ET composition structurée des produits —
     * pas seulement le libellé des variantes visées par ce retrait.
     *
     * @param array<string, mixed> $recipe
     */
    private function assertAllowed(Household $household, array $recipe): void
    {
        $guard = $this->guards->for($household);
        $plan = $this->planner->plan($guard, $household->getStrictExclusionLabels(), $household, $recipe);

        if (RecipePlanStatus::Incompatible === $plan->status || RecipePlanStatus::SafetyUnknown === $plan->status) {
            throw new StockException(sprintf(
                'Retrait refusé : %s',
                $plan->reason ?? 'cette recette ne peut plus être confirmée en toute sécurité.',
            ));
        }
    }
}
