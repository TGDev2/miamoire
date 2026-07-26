<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockOperation;
use App\Enum\StockOperationStatus;
use App\Enum\StockOperationType;
use App\Exception\StockConflictException;
use App\Exception\StockException;
use App\Repository\HouseholdProductVariantRepository;
use App\Repository\StockOperationRepository;
use App\Service\Ai\Safety\DietaryGuardFactory;
use App\Service\Stock\ConsumptionLine;
use App\Service\Stock\FefoAllocator;
use App\Service\Stock\FefoPlan;
use App\Service\Stock\ProposalConsolidator;
use App\Service\Stock\StockService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique une proposition de consommation APRÈS confirmation explicite de l'utilisateur.
 *
 * C'est la seule porte par laquelle une recette peut toucher au stock. Elle tient
 * quatre garanties, et chacune corrige un défaut constaté :
 *
 * 1. **Idempotence.** La requête porte une clé d'opération. Une réponse réseau
 *    perdue suivie d'une nouvelle tentative ne déduit plus deux fois : la seconde
 *    relit la réponse figée de la première.
 * 2. **Atomicité.** Les lots sont verrouillés, FEFO est recalculé À L'INTÉRIEUR de
 *    la transaction, et un plan devenu incomplet n'écrit rien du tout. Auparavant
 *    les plans étaient tous calculés avant la transaction, et une allocation
 *    partielle suffisait à déclencher l'écriture.
 * 3. **Honnêteté.** Les lignes en double sont fusionnées avant planification, si
 *    bien que la quantité annoncée comme appliquée est celle qui a réellement été
 *    retirée — le plafonnement silencieux ne peut plus mentir.
 * 4. **Sûreté alimentaire.** Les exclusions du foyer sont revérifiées ici : une
 *    proposition ancienne, ou forgée, ne peut pas déduire un aliment exclu.
 */
class RecipeConfirmationService
{
    /**
     * Au-delà de ce délai, une opération restée « en cours » est considérée comme
     * abandonnée — processus tué, conteneur redémarré — et sa clé redevient
     * utilisable. Sans cela, un incident bloquerait définitivement une recette.
     */
    private const STALE_PENDING_MINUTES = 2;

    public function __construct(
        private readonly FefoAllocator $allocator,
        private readonly StockService $stockService,
        private readonly ProposalConsolidator $consolidator,
        private readonly HouseholdProductVariantRepository $variants,
        private readonly StockOperationRepository $operations,
        private readonly DietaryGuardFactory $guards,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $proposal
     *
     * @return array<string, mixed>
     */
    public function confirm(
        Household $household,
        Member $author,
        array $proposal,
        ?string $recipeName,
        string $operationKey,
    ): array {
        $key = $this->validateKey($operationKey);
        $lines = $this->consolidator->consolidate($proposal);
        $requestHash = $this->consolidator->hash($lines, $recipeName);

        /** @var list<array{line: ConsumptionLine, variant: HouseholdProductVariant}> $targets */
        $targets = $this->resolveTargets($household, $lines);
        $this->assertAllowed($household, $targets);

        $replay = $this->replayIfKnown($household, $key, $requestHash);
        if (null !== $replay) {
            return $replay;
        }

        $operation = $this->reserve($household, $key, $requestHash, $author);

        $outcome = $this->stockService->transactional(
            fn (): array => $this->applyWithin($operation, $targets, $author, $recipeName),
        );

        if (!$outcome['complete']) {
            $operation->fail('Stock insuffisant au moment de la confirmation.');
            $this->entityManager->flush();

            throw new StockConflictException(
                'Le stock a changé depuis la proposition : rien n\'a été retiré. Voici ce qui est encore disponible.',
                ['proposal' => $outcome['shortages']],
            );
        }

        $response = $outcome['response'];
        $operation->succeed($response);
        $this->entityManager->flush();

        return $response;
    }

    /**
     * Le cœur transactionnel.
     *
     * Ne lève jamais d'exception métier : un `throw` ici fermerait l'EntityManager
     * et empêcherait d'enregistrer l'échec de l'opération. Le manque est donc
     * remonté comme un résultat, et l'appelant décide.
     *
     * @param list<array{line: ConsumptionLine, variant: HouseholdProductVariant}> $targets
     *
     * @return array{complete: bool, response: array<string, mixed>, shortages: list<array<string, mixed>>}
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
            return ['complete' => false, 'response' => [], 'shortages' => $shortages];
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

        return [
            'complete' => true,
            'response' => $this->describe($operation, $plans, $recipeName),
            'shortages' => [],
        ];
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
     * de confirmer une proposition reçue avant qu'une allergie ne soit déclarée.
     *
     * @param list<array{line: ConsumptionLine, variant: HouseholdProductVariant}> $targets
     */
    private function assertAllowed(Household $household, array $targets): void
    {
        $guard = $this->guards->for($household);

        if ($guard->isEmpty()) {
            return;
        }

        foreach ($targets as $target) {
            $violation = $guard->inspect($target['variant']->getCulinaryLabel(), 'la déduction de stock');

            if (null !== $violation) {
                throw new StockException(sprintf(
                    'Retrait refusé : « %s » est exclu par votre foyer (%s).',
                    $target['variant']->getCulinaryLabel(),
                    $violation->exclusion,
                ));
            }
        }
    }

    /**
     * La réponse figée d'une opération déjà menée à bien, s'il y en a une.
     *
     * @return array<string, mixed>|null
     */
    private function replayIfKnown(Household $household, string $key, string $requestHash): ?array
    {
        $existing = $this->operations->findOneByKey($household, $key);

        if (null === $existing) {
            return null;
        }

        // Le contenu ne compte que pour une opération ABOUTIE : c'est la seule qui
        // détient une réponse, et renvoyer celle d'un autre geste serait pire que
        // de refuser. Une opération échouée, elle, n'a rien écrit — sa clé est
        // libre, quel que soit le contenu de la nouvelle tentative.
        if (StockOperationStatus::Succeeded === $existing->getStatus()) {
            if (!$existing->matches($requestHash)) {
                throw new StockException(
                    'Cette confirmation a déjà servi pour une autre recette. Regénérez des propositions.',
                );
            }

            return ['replayed' => true] + ($existing->getResponse() ?? []);
        }

        if (StockOperationStatus::Pending === $existing->getStatus() && !$this->isStale($existing)) {
            throw new StockConflictException(
                'Cette confirmation est déjà en cours de traitement. Patientez quelques instants.',
            );
        }

        // Échouée, ou abandonnée depuis assez longtemps : la clé se réemploie.
        $existing->retry($requestHash);
        $this->entityManager->flush();

        return null;
    }

    private function reserve(
        Household $household,
        string $key,
        string $requestHash,
        Member $author,
    ): StockOperation {
        $existing = $this->operations->findOneByKey($household, $key);

        if (null !== $existing) {
            return $existing;
        }

        $operation = new StockOperation(
            $household,
            $key,
            StockOperationType::RecipeConfirmation,
            $requestHash,
            $author,
        );

        $this->entityManager->persist($operation);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Deux téléphones ont confirmé la même recette au même instant.
            // L'index unique a tranché : celui qui perd n'écrit rien.
            throw new StockConflictException(
                'Cette confirmation est déjà en cours de traitement. Patientez quelques instants.',
            );
        }

        return $operation;
    }

    private function isStale(StockOperation $operation): bool
    {
        return $operation->getCreatedAt()
            < new \DateTimeImmutable(sprintf('-%d minutes', self::STALE_PENDING_MINUTES));
    }

    private function validateKey(string $operationKey): string
    {
        $key = trim($operationKey);

        if (1 !== preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) {
            throw new StockException(
                'Identifiant de confirmation invalide : l\'application doit en fournir un stable.',
            );
        }

        return $key;
    }
}
