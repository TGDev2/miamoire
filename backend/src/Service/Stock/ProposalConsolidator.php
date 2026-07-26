<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Enum\Unit;
use App\Exception\StockException;
use App\Service\UnitConverter;

/**
 * Valide et fusionne les lignes d'une proposition de consommation.
 *
 * Un modèle peut très bien écrire deux fois le même ingrédient — « 200 g de
 * tomates » pour la sauce, « 100 g de tomates » pour la garniture. Planifiées
 * séparément, ces deux lignes réservaient chacune le même lot : le total prévu
 * dépassait le stock réel, et le plafonnement silencieux à l'écriture faisait
 * annoncer plus que ce qui avait été retiré.
 *
 * Consolider d'abord, planifier ensuite : c'est ce qui rend le plan honnête.
 */
class ProposalConsolidator
{
    public function __construct(private readonly UnitConverter $converter)
    {
    }

    /**
     * @param list<array<string, mixed>> $proposal
     *
     * @return list<ConsumptionLine>
     */
    public function consolidate(array $proposal): array
    {
        if ([] === $proposal) {
            throw new StockException('La proposition de consommation est vide.');
        }

        /** @var array<string, ConsumptionLine> $merged */
        $merged = [];
        $errors = [];

        foreach ($proposal as $index => $line) {
            $key = is_string($line['key'] ?? null) ? trim($line['key']) : '';
            $label = is_string($line['label'] ?? null) && '' !== trim($line['label']) ? trim($line['label']) : $key;
            $unit = Unit::tryFrom(is_string($line['unit'] ?? null) ? $line['unit'] : '');
            $quantity = is_numeric($line['quantity'] ?? null) ? (float) $line['quantity'] : 0.0;

            if ('' === $key) {
                $errors[] = sprintf('Ligne %d : ingrédient non identifié.', $index + 1);
                continue;
            }
            if (null === $unit) {
                $errors[] = sprintf('Ligne %d (%s) : unité inconnue.', $index + 1, $label);
                continue;
            }
            if ($quantity <= 0) {
                $errors[] = sprintf('Ligne %d (%s) : quantité invalide.', $index + 1, $label);
                continue;
            }

            if (!isset($merged[$key])) {
                $merged[$key] = new ConsumptionLine($key, $label, round($quantity, 3), $unit);
                continue;
            }

            $existing = $merged[$key];

            // Deux unités inconvertibles pour le même ingrédient ne se fusionnent
            // pas : additionner 2 pièces et 300 g demanderait un poids unitaire
            // qu'on n'a pas. On refuse plutôt que de deviner.
            if (!$this->converter->canConvert($unit, $existing->unit)) {
                $errors[] = sprintf(
                    'Ligne %d (%s) : la proposition mélange « %s » et « %s » pour le même ingrédient.',
                    $index + 1,
                    $label,
                    $existing->unit->label(),
                    $unit->label(),
                );
                continue;
            }

            $merged[$key] = new ConsumptionLine(
                $existing->key,
                $existing->label,
                round($existing->quantity + $this->converter->convert($quantity, $unit, $existing->unit), 3),
                $existing->unit,
            );
        }

        if ([] !== $errors) {
            throw new StockException(implode(' ', $errors));
        }

        return array_values($merged);
    }

    /**
     * Empreinte stable d'une proposition consolidée.
     *
     * Rejouer la même clé d'opération avec un contenu différent n'est pas un rejeu :
     * c'est une confusion, et renvoyer la réponse de l'autre geste serait pire que
     * de refuser.
     *
     * @param list<ConsumptionLine> $lines
     */
    public function hash(array $lines, ?string $recipeName): string
    {
        $signatures = array_map(static fn (ConsumptionLine $line) => $line->signature(), $lines);
        sort($signatures);

        return hash('sha256', ($recipeName ?? '').'|'.implode('|', $signatures));
    }
}
