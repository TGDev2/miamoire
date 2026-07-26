<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * D'où vient une information portée par une variante de foyer.
 *
 * Quatre sources se disputent le même champ — le catalogue rafraîchi, la détection
 * automatique, la correction locale, le choix explicite de l'utilisateur. Sans
 * priorité écrite quelque part, un rafraîchissement Open Food Facts finirait par
 * écraser une correction faite à la main, et personne ne saurait dire pourquoi.
 *
 * La règle tient en une ligne : ce que quelqu'un a choisi l'emporte sur ce qu'une
 * machine a déduit.
 */
enum DataOrigin: string
{
    /** Déduit du libellé par ProductTraitDetector ou IngredientKeyGenerator. */
    case Detected = 'detected';

    /** Repris de la fiche Open Food Facts. */
    case Catalog = 'catalog';

    /** Saisi ou corrigé explicitement dans le foyer. */
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Detected => 'Déduit automatiquement',
            self::Catalog => 'Fiche Open Food Facts',
            self::User => 'Corrigé dans le foyer',
        };
    }

    private function priority(): int
    {
        return match ($this) {
            self::Detected => 0,
            self::Catalog => 1,
            self::User => 2,
        };
    }

    /** Une donnée d'origine `$this` peut-elle remplacer une donnée d'origine `$existing` ? */
    public function overrides(self $existing): bool
    {
        return $this->priority() >= $existing->priority();
    }
}
