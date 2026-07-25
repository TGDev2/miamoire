<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * L'état réel d'un produit, au-delà de son nom.
 *
 * Des pommes de terre en conserve sont déjà pelées et cuites : sans cette
 * information, le générateur de recettes commence par « Épluchez les pommes de
 * terre ». Chaque trait porte donc, en plus de son libellé, la consigne exacte
 * transmise au modèle — la connaissance culinaire reste à un seul endroit.
 */
enum ProductTrait: string
{
    case Canned = 'canned';
    case Frozen = 'frozen';
    case Dried = 'dried';
    case Peeled = 'peeled';
    case Precooked = 'precooked';
    case Cooked = 'cooked';
    case ReadyToEat = 'ready_to_eat';
    case Sliced = 'sliced';
    case Sweetened = 'sweetened';
    case Unsweetened = 'unsweetened';

    public function label(): string
    {
        return match ($this) {
            self::Canned => 'En conserve',
            self::Frozen => 'Surgelé',
            self::Dried => 'Sec / déshydraté',
            self::Peeled => 'Épluché',
            self::Precooked => 'Précuit',
            self::Cooked => 'Déjà cuit',
            self::ReadyToEat => 'Prêt à consommer',
            self::Sliced => 'Coupé / en morceaux',
            self::Sweetened => 'Sucré',
            self::Unsweetened => 'Nature, non sucré',
        };
    }

    /** Ce que le générateur de recettes doit en déduire. */
    public function instruction(): string
    {
        return match ($this) {
            self::Canned => 'déjà cuit, pelé et paré : ne demande ni épluchage, ni parage, ni cuisson longue — un simple égouttage puis un réchauffage suffisent',
            self::Frozen => 'sortir du congélateur : prévois une décongélation ou une cuisson directe depuis surgelé',
            self::Dried => 'sec : prévois un trempage, une réhydratation ou une cuisson dans un liquide',
            self::Peeled => 'déjà épluché : ne demande jamais de l\'éplucher ni de le peler',
            self::Precooked => 'déjà précuit : un réchauffage ou une finition suffit, pas de cuisson depuis cru',
            self::Cooked => 'déjà cuit : ne le fais pas cuire une seconde fois depuis cru',
            self::ReadyToEat => 'consommable tel quel, sans préparation',
            self::Sliced => 'déjà coupé : ne demande pas de le détailler',
            self::Sweetened => 'déjà sucré : n\'ajoute pas de sucre sans raison',
            self::Unsweetened => 'non sucré : sucre-le si la recette l\'exige',
        };
    }

    /**
     * @param list<string> $values
     *
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        $traits = [];

        foreach ($values as $value) {
            $trait = self::tryFrom($value);

            if (null !== $trait && !in_array($trait, $traits, true)) {
                $traits[] = $trait;
            }
        }

        return $traits;
    }

    /**
     * @param list<self> $traits
     *
     * @return list<string>
     */
    public static function toValues(array $traits): array
    {
        return array_values(array_unique(array_map(static fn (self $trait) => $trait->value, $traits)));
    }
}
