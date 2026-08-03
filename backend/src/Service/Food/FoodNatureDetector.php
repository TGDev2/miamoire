<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Enum\FoodNature;
use App\Enum\ProductTrait;
use App\Service\TextNormalizer;

/**
 * Déduit la nature d'un aliment de son libellé, de son état, et du fait qu'il ait
 * été scanné ou saisi à la main.
 *
 * ## Pourquoi le code-barres tranche avant le lexique
 *
 * La première version répondait « frais » dès que le nom nommait un aliment frais,
 * en réservant le code-barres au dernier recours : une barquette de tomates du
 * primeur en porte un, et reste une tomate. Passée sur un vrai inventaire, la règle
 * a rangé du côté frais un double concentré de tomates, une pulpe de tomates en dés,
 * une mangue au sirop, des poissons panés et des galettes de pommes de terre.
 *
 * Le raisonnement était faux, et sa correction est plus simple que lui : **un
 * code-barres EST un emballage, et un emballage porte une date imprimée.** Or c'est
 * exactement ce que « frais » veut dire ici — pas d'emballage qui protège, donc pas
 * de date à lire, donc une date à estimer. Une barquette scannée a sa date sur
 * l'étiquette : elle n'a rien à faire du côté frais.
 *
 * Ce qui reste au lexique est précisément ce que l'application voulait servir : les
 * courgettes du marché, le filet de pommes de terre en vrac, la côte de porc du
 * boucher, le reste de gratin — tout ce qu'on saisit à la main, faute de code-barres.
 *
 * Volontairement conservateur pour le reste, comme {@see \App\Service\ProductTraitDetector} :
 * en cas de doute on répond « conditionné », qui est le comportement d'avant cette
 * notion. La correction reste à un appui, et la variante s'en souvient.
 */
class FoodNatureDetector
{
    /**
     * Un procédé de conservation range définitivement l'aliment du côté conditionné.
     *
     * `cooked` et `precooked` n'en sont volontairement pas : un reste de gratin est
     * cuit ET frais — c'est même l'aliment le plus périssable du réfrigérateur.
     *
     * @var list<ProductTrait>
     */
    private const PRESERVING_TRAITS = [
        ProductTrait::Canned,
        ProductTrait::Frozen,
        ProductTrait::Dried,
        ProductTrait::ReadyToEat,
    ];

    /**
     * Catégories qui déclarent un procédé de conservation.
     *
     * En français ET en anglais : les catégories d'Open Food Facts arrivent dans la
     * taxonomie anglaise (« Canned tomatoes », « Frozen leeks »), et une liste
     * purement française ne reconnaissait donc à peu près rien.
     */
    private const PRESERVED_CATEGORIES = [
        'conserve', 'appertise', 'surgel', 'congel', 'lyophilis', 'deshydrat', 'sirop',
        'canned', 'frozen', 'dried', 'dehydrated', 'shelf stable', 'syrup',
    ];

    /**
     * Rayons dont tout sort frais — appliqués aux seuls aliments saisis à la main,
     * puisqu'un aliment scanné est déjà tranché par son code-barres.
     */
    private const FRESH_CATEGORIES = [
        'legume', 'fruit', 'primeur', 'herbe aromatique', 'viande', 'boucherie',
        'volaille', 'poisson', 'poissonnerie', 'maree', 'boulangerie', 'traiteur',
        'vegetable', 'meat', 'poultry', 'fish', 'seafood', 'bakery', 'bread',
    ];

    public function __construct(
        private readonly FreshFoodLexicon $lexicon,
        private readonly TextNormalizer $normalizer,
    ) {
    }

    /**
     * @param list<ProductTrait> $traits
     * @param bool               $scanned l'aliment vient d'une fiche à code-barres
     */
    public function detect(
        string $name,
        ?string $category = null,
        array $traits = [],
        bool $scanned = false,
    ): FoodNature {
        foreach ($traits as $trait) {
            if (in_array($trait, self::PRESERVING_TRAITS, true)) {
                return FoodNature::Packaged;
            }
        }

        // Un code-barres décrit un emballage, et un emballage porte sa date : rien
        // n'est à estimer, donc rien n'est « frais » au sens où ce mot sert ici.
        if ($scanned) {
            return FoodNature::Packaged;
        }

        $normalizedCategory = null !== $category ? $this->normalizer->toWords($category) : '';

        foreach (self::PRESERVED_CATEGORIES as $term) {
            if (str_contains($normalizedCategory, $term)) {
                return FoodNature::Packaged;
            }
        }

        if (null !== $this->lexicon->find($name)) {
            return FoodNature::Fresh;
        }

        foreach (self::FRESH_CATEGORIES as $term) {
            if (str_contains($normalizedCategory, $term)) {
                return FoodNature::Fresh;
            }
        }

        // Ce qu'on ne reconnaît pas reste conditionné : c'est le comportement
        // qu'avait l'application avant cette notion, donc zéro régression.
        return FoodNature::Packaged;
    }
}
