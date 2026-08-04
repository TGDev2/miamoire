<?php

declare(strict_types=1);

namespace App\Service\Inventory;

use App\Enum\Unit;

/**
 * Le conditionnement d'une ligne d'inventaire : combien d'exemplaires restent,
 * et ce que contient chacun.
 *
 * Il existe pour qu'on puisse dire « écoule une boîte » au lieu de « écoule
 * 400 g » — un geste que l'utilisateur fait en ouvrant son placard, pas en
 * pesant. La contenance est **nominale** : un exemplaire entamé compte pour un
 * exemplaire et garde la contenance de ses pareils. Ce qu'il en reste
 * réellement est une autre question, tranchée au moment de confronter la
 * demande au stock ({@see \App\Service\Ai\ResolvedPriority}) — sans quoi la
 * même boîte vaudrait 400 g au placard et 250 g dans l'écran suivant.
 *
 * `null` sur une ligne veut dire « la question n'a pas de réponse ici » : soit
 * l'aliment se compte déjà à la pièce, soit ses lots ont des contenances
 * différentes et « un exemplaire » ne désigne alors plus rien de précis.
 */
final readonly class InventoryPackaging
{
    public function __construct(
        /** Exemplaires restants, celui qui est entamé compris. */
        public int $count,
        /** Contenance d'un exemplaire, exprimée dans {@see $unit}. */
        public float $size,
        /** L'unité de la ligne — jamais l'unité de base, pour rester lisible. */
        public Unit $unit,
    ) {
    }

    /** Ce que pèsent `$packages` exemplaires, dans {@see $unit}. */
    public function totalFor(int $packages): float
    {
        return round($packages * $this->size, 3);
    }
}
