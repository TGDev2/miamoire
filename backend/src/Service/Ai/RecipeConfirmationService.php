<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Entity\Household;
use App\Entity\User;
use App\Enum\Unit;
use App\Exception\StockException;
use App\Service\Stock\FefoAllocator;
use App\Service\Stock\StockService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applique une proposition de consommation APRÈS confirmation explicite de l'utilisateur.
 *
 * C'est la seule porte par laquelle une recette peut toucher au stock, et chaque
 * ligne est revalidée contre l'inventaire réel : une clé inventée ou une quantité
 * mal interprétée par le modèle est rejetée avant toute écriture.
 */
class RecipeConfirmationService
{
    public function __construct(
        private readonly FefoAllocator $allocator,
        private readonly StockService $stockService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array{key?: mixed, label?: mixed, quantity?: mixed, unit?: mixed}> $proposal
     *
     * @return array{recipeName: ?string, lines: list<array<string, mixed>>}
     */
    public function confirm(
        Household $household,
        User $author,
        array $proposal,
        ?string $recipeName = null,
    ): array {
        if ([] === $proposal) {
            throw new StockException('La proposition de consommation est vide.');
        }

        $plans = [];
        $errors = [];

        foreach ($proposal as $index => $line) {
            $key = is_string($line['key'] ?? null) ? trim($line['key']) : '';
            $unit = Unit::tryFrom(is_string($line['unit'] ?? null) ? $line['unit'] : '');
            $quantity = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : 0.0;

            if ('' === $key) {
                $errors[] = sprintf('Ligne %d : ingrédient non identifié.', $index + 1);
                continue;
            }
            if (null === $unit) {
                $errors[] = sprintf('Ligne %d (%s) : unité inconnue.', $index + 1, $key);
                continue;
            }
            if ($quantity <= 0) {
                $errors[] = sprintf('Ligne %d (%s) : quantité invalide.', $index + 1, $key);
                continue;
            }

            $plan = $this->allocator->plan($household, $key, $quantity, $unit);

            if ([] === $plan->allocations) {
                $errors[] = sprintf(
                    'Aucun lot disponible pour « %s » dans une unité compatible.',
                    is_string($line['label'] ?? null) ? $line['label'] : $key,
                );
                continue;
            }

            $plans[] = [
                'key' => $key,
                'label' => is_string($line['label'] ?? null) ? $line['label'] : $key,
                'plan' => $plan,
            ];
        }

        if ([] !== $errors) {
            throw new StockException(implode(' ', $errors));
        }

        $reason = null !== $recipeName && '' !== $recipeName
            ? sprintf('Recette : %s', $recipeName)
            : 'Recette confirmée';

        $this->entityManager->wrapInTransaction(function () use ($plans, $author, $reason): void {
            foreach ($plans as $entry) {
                foreach ($entry['plan']->allocations as $allocation) {
                    $this->stockService->consume(
                        $allocation->item,
                        $allocation->quantity,
                        $allocation->unit,
                        $author,
                        $reason,
                    );
                }
            }
        });

        return [
            'recipeName' => $recipeName,
            'lines' => array_map(
                static fn (array $entry): array => [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'requested' => $entry['plan']->requested,
                    'applied' => $entry['plan']->allocated,
                    'shortfall' => $entry['plan']->shortfall,
                    'unit' => $entry['plan']->unit->value,
                    'lots' => array_map(
                        static fn ($allocation): array => [
                            'stockItemId' => (string) $allocation->item->getId(),
                            'quantity' => $allocation->quantity,
                            'unit' => $allocation->unit->value,
                            'expirationDate' => $allocation->item->getExpirationDate()?->format('Y-m-d'),
                        ],
                        $entry['plan']->allocations,
                    ),
                ],
                $plans,
            ),
        ];
    }
}
