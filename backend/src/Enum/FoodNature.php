<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Un aliment est-il protégé par son emballage, ou se dégrade-t-il déjà ?
 *
 * Tout le modèle de stock était jusqu'ici celui du produit **conditionné** : un lot
 * s'y décrit par un nombre d'exemplaires et une contenance, il est scellé jusqu'à
 * ouverture, et il porte une date imprimée. C'est exactement juste pour un pack de
 * yaourts. C'est faux pour une courgette, à qui l'application demandait pourtant
 * « combien d'exemplaires ? » et « quel poids par exemplaire ? ».
 *
 * La nature répare les trois conséquences de cette confusion :
 *
 * - **la saisie** — un aliment frais se saisit d'un seul chiffre, dans son unité
 *   naturelle, sans conditionnement à décrire ;
 * - **la date** — un aliment frais n'a rien d'imprimé : elle est estimée d'office,
 *   et marquée comme estimée. Sans quoi il entrait sans date, donc invisible pour
 *   les alertes, « Aujourd'hui » et les recettes — l'aliment qui pourrit était
 *   précisément celui que l'application ne voyait pas ;
 * - **les recettes** — une courgette attend une semaine, une conserve deux ans.
 *   Les traiter à égalité, c'est perdre la courgette.
 *
 * Elle ne fait volontairement PAS partie de {@see \App\Service\Product\VariantIdentity} :
 * les traits `canned` / `frozen` / `dried` séparent déjà « tomate » de
 * « tomate-conserve ». C'est un attribut descriptif et corrigeable, comme la
 * catégorie, pas un critère de séparation des stocks.
 */
enum FoodNature: string
{
    /** Se dégrade dès l'achat, sans emballage qui le protège ni date imprimée. */
    case Fresh = 'fresh';

    /** Protégé jusqu'à l'ouverture, avec une date lisible sur l'emballage. */
    case Packaged = 'packaged';

    public function label(): string
    {
        return match ($this) {
            self::Fresh => 'Produit frais',
            self::Packaged => 'Produit conditionné',
        };
    }

    public function isFresh(): bool
    {
        return self::Fresh === $this;
    }
}
