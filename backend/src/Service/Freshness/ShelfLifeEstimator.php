<?php

declare(strict_types=1);

namespace App\Service\Freshness;

use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
use App\Service\Food\FoodTermMatcher;
use App\Service\Food\PreparedDish;
use App\Service\TextNormalizer;

/**
 * Combien de temps cet aliment se garde-t-il, là où il est rangé ?
 *
 * Une salade, un filet de pommes de terre, un reste de gratin n'ont aucune date
 * imprimée. Jusqu'ici, ces aliments entraient sans date : ils n'apparaissaient
 * dans aucune alerte, ne remontaient jamais en priorité, et pourrissaient dans le
 * bac à légumes sans que rien ne le signale — précisément le gaspillage que
 * l'application existe pour éviter.
 *
 * On estime donc, avec deux garde-fous :
 *
 * - l'estimation est **marquée comme telle** (`expirationIsEstimated`) et
 *   l'application ne l'affiche jamais comme une date lue sur un emballage ;
 * - elle est **volontairement courte**. Se tromper court fait vérifier un aliment
 *   encore bon ; se tromper long fait manger un aliment qui ne l'est plus.
 *
 * Le rangement prime sur l'aliment : un légume au congélateur se garde des mois,
 * quel que soit le légume.
 */
class ShelfLifeEstimator
{
    /**
     * Durées usuelles, en jours, par aliment puis par emplacement.
     *
     * Les termes sont reconnus par {@see \App\Service\Food\FoodTermMatcher} : au
     * pluriel tolérant (`tomate` reconnaît « tomates »), et le terme le plus long
     * l'emporte, pour que « pomme de terre » ne soit jamais traité comme « pomme ».
     *
     * @var array<string, array<value-of<StorageLocation>, int>>
     */
    private const FOODS = [
        // Légumes feuilles et fragiles
        'salade' => ['fridge' => 4, 'pantry' => 1],
        'laitue' => ['fridge' => 4, 'pantry' => 1],
        'mache' => ['fridge' => 3],
        'roquette' => ['fridge' => 3],
        'epinard' => ['fridge' => 3],
        'persil' => ['fridge' => 5],
        'coriandre' => ['fridge' => 5],
        'basilic' => ['fridge' => 4],
        'ciboulette' => ['fridge' => 5],
        'menthe' => ['fridge' => 5],
        'champignon' => ['fridge' => 5],
        'asperge' => ['fridge' => 4],
        'petit pois' => ['fridge' => 3],
        'haricot vert' => ['fridge' => 5],
        'brocoli' => ['fridge' => 5],
        'chou fleur' => ['fridge' => 7],
        'chou' => ['fridge' => 14, 'pantry' => 5],
        'courgette' => ['fridge' => 7, 'pantry' => 4],
        'aubergine' => ['fridge' => 7, 'pantry' => 4],
        'concombre' => ['fridge' => 7],
        'poivron' => ['fridge' => 10, 'pantry' => 4],
        'tomate' => ['fridge' => 7, 'pantry' => 5],
        'radis' => ['fridge' => 7],
        'celeri' => ['fridge' => 10],
        'poireau' => ['fridge' => 10, 'pantry' => 4],
        'carotte' => ['fridge' => 21, 'pantry' => 7],
        'navet' => ['fridge' => 14],
        'betterave' => ['fridge' => 14],
        'courge' => ['pantry' => 60, 'fridge' => 21],
        'potiron' => ['pantry' => 60, 'fridge' => 21],
        'butternut' => ['pantry' => 60, 'fridge' => 21],

        // Légumes de garde
        'pomme de terre' => ['pantry' => 30, 'fridge' => 21],
        'patate douce' => ['pantry' => 21, 'fridge' => 14],
        'oignon' => ['pantry' => 45, 'fridge' => 21],
        'echalote' => ['pantry' => 45],
        'ail' => ['pantry' => 90],

        // Fruits
        'banane' => ['pantry' => 5, 'fridge' => 7],
        'pomme' => ['fridge' => 30, 'pantry' => 10],
        'poire' => ['fridge' => 10, 'pantry' => 4],
        'peche' => ['fridge' => 5, 'pantry' => 3],
        'nectarine' => ['fridge' => 5, 'pantry' => 3],
        'abricot' => ['fridge' => 5, 'pantry' => 3],
        'prune' => ['fridge' => 7, 'pantry' => 3],
        'raisin' => ['fridge' => 7],
        'fraise' => ['fridge' => 3],
        'framboise' => ['fridge' => 2],
        'mure' => ['fridge' => 2],
        'myrtille' => ['fridge' => 5],
        'cerise' => ['fridge' => 5],
        'melon' => ['fridge' => 5, 'pantry' => 4],
        'pasteque' => ['fridge' => 5, 'pantry' => 4],
        'orange' => ['fridge' => 21, 'pantry' => 10],
        'clementine' => ['fridge' => 14, 'pantry' => 7],
        'mandarine' => ['fridge' => 14, 'pantry' => 7],
        'citron' => ['fridge' => 21, 'pantry' => 7],
        'pamplemousse' => ['fridge' => 21, 'pantry' => 10],
        'kiwi' => ['fridge' => 14, 'pantry' => 5],
        'ananas' => ['fridge' => 5, 'pantry' => 3],
        'mangue' => ['fridge' => 6, 'pantry' => 4],
        'avocat' => ['fridge' => 7, 'pantry' => 4],
        'figue' => ['fridge' => 3],

        // Produits animaux
        'viande hachee' => ['fridge' => 1],
        'steak hache' => ['fridge' => 1],
        'boeuf' => ['fridge' => 3],
        'veau' => ['fridge' => 3],
        'agneau' => ['fridge' => 3],
        'porc' => ['fridge' => 3],
        'poulet' => ['fridge' => 2],
        'dinde' => ['fridge' => 2],
        'volaille' => ['fridge' => 2],
        'poisson' => ['fridge' => 2],
        'saumon' => ['fridge' => 2],
        'cabillaud' => ['fridge' => 2],
        'crevette' => ['fridge' => 1],
        'moule' => ['fridge' => 1],
        'jambon' => ['fridge' => 4],
        'saucisson' => ['fridge' => 21, 'pantry' => 14],
        'lardon' => ['fridge' => 5],
        'oeuf' => ['fridge' => 28, 'pantry' => 21],
        'lait' => ['fridge' => 5],
        'yaourt' => ['fridge' => 14],
        'fromage' => ['fridge' => 14],
        'beurre' => ['fridge' => 30],
        'creme' => ['fridge' => 5],

        // Restes et préparations maison
        'reste' => ['fridge' => 3],
        'gratin' => ['fridge' => 3],
        'soupe' => ['fridge' => 3],
        'compote' => ['fridge' => 5],
        'salade composee' => ['fridge' => 2],

        // Épicerie
        'pain' => ['pantry' => 3, 'fridge' => 5],
        'riz' => ['pantry' => 365],
        'farine' => ['pantry' => 365],
        'lentille' => ['pantry' => 365],
        'pois chiche' => ['pantry' => 365],
        'semoule' => ['pantry' => 365],
        'sucre' => ['pantry' => 730],
        'sel' => ['pantry' => 730],
        'huile' => ['pantry' => 365],
        'miel' => ['pantry' => 730],
    ];

    /**
     * Termes cherchés au mot près, sans tolérance de pluriel.
     *
     * « pâtes » et « pâté » se normalisent tous deux en `pate` : une tolérance de
     * pluriel donnerait un an de conservation à une terrine de campagne. Ces
     * termes-là se listent en toutes lettres (leçon n° 2), et l'emportent à
     * longueur égale sur un terme tolérant.
     *
     * @var array<string, array<value-of<StorageLocation>, int>>
     */
    private const EXACT_FOODS = [
        'pates' => ['pantry' => 365],
        'pate' => ['fridge' => 4],
        'pates fraiches' => ['fridge' => 5],
    ];

    /** @var array<value-of<ProductTrait>, int> */
    private const TRAIT_DAYS = [
        'canned' => 730,
        'dried' => 365,
        'frozen' => 180,
    ];

    /** @var array<string, int> */
    private const CATEGORY_DAYS = [
        'conserve' => 730,
        'surgele' => 180,
        'legume' => 7,
        'fruit' => 7,
        'viande' => 3,
        'poisson' => 2,
        'charcuterie' => 5,
        'laitier' => 7,
        'fromage' => 14,
        'boisson' => 180,
        'epicerie' => 365,
        'condiment' => 365,
        'biscuit' => 90,
    ];

    /**
     * Durées par défaut quand rien n'est reconnu.
     *
     * @var array<value-of<StorageLocation>, int>
     */
    private const LOCATION_DAYS = [
        'fridge' => 5,
        'pantry' => 30,
        'freezer' => 180,
    ];

    /**
     * Combien de temps un aliment se garde-t-il UNE FOIS ENTAMÉ ? Toujours plus
     * court que sa durée avant ouverture, et sans rapport avec elle : un lait
     * UHT se garde des mois fermé, quelques jours ouvert.
     *
     * Sous-ensemble volontairement restreint de {@see FOODS} : seuls les
     * aliments dont l'ouverture change réellement la donne y figurent. Les
     * mêmes clés de terme sont réutilisées pour rester cohérent avec
     * `estimate()`.
     *
     * @var array<string, int>
     */
    private const AFTER_OPENING_DAYS = [
        'lait' => 4,
        'creme' => 5,
        'yaourt' => 7,
        'fromage' => 10,
        'beurre' => 21,
        'jambon' => 4,
        'saucisson' => 10,
        'compote' => 4,
        'reste' => 3,
        'gratin' => 3,
        'soupe' => 3,
        'salade composee' => 2,
    ];

    /** @var array<value-of<ProductTrait>, int> */
    private const AFTER_OPENING_TRAIT_DAYS = [
        // Une conserve ouverte ne se conserve plus des années : son contenu
        // rejoint alors un aliment frais ordinaire.
        'canned' => 3,
    ];

    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly FoodTermMatcher $matcher,
    ) {
    }

    /**
     * Les aliments que cette table sait nommer.
     *
     * Exposés pour que {@see \App\Service\Food\FreshFoodLexicon} soit vérifiable :
     * un aliment déclaré frais mais absent d'ici n'aurait aucune durée de
     * conservation propre, donc aucune date — exactement l'angle mort que le
     * traitement du frais existe pour fermer.
     *
     * @return list<string>
     */
    public static function knownTerms(): array
    {
        return array_map('strval', array_keys(self::FOODS + self::EXACT_FOODS));
    }

    /** @param list<ProductTrait> $traits */
    public function estimate(
        string $name,
        ?string $category = null,
        array $traits = [],
        StorageLocation $location = StorageLocation::Pantry,
    ): ShelfLifeEstimate {
        $food = $this->matcher->match($name, self::FOODS, self::EXACT_FOODS, PreparedDish::TERMS);

        // Le congélateur normalise tout : un légume surgelé se garde des mois,
        // qu'il s'agisse d'épinards ou de haricots. L'aliment ne reprend la main
        // que s'il déclare explicitement une durée de congélation.
        if (StorageLocation::Freezer === $location && (null === $food || !isset($food['value']['freezer']))) {
            return ShelfLifeEstimate::fromLocation(self::LOCATION_DAYS['freezer'], $location);
        }

        if (null !== $food) {
            return ShelfLifeEstimate::fromFood(
                $this->daysFor($food['value'], $location),
                $food['term'],
                $location,
            );
        }

        foreach ($traits as $trait) {
            if (isset(self::TRAIT_DAYS[$trait->value])) {
                return ShelfLifeEstimate::fromTrait(
                    self::TRAIT_DAYS[$trait->value],
                    mb_strtolower($trait->label()),
                    $location,
                );
            }
        }

        if (null !== $category) {
            $normalizedCategory = $this->normalizer->toWords($category);

            foreach (self::CATEGORY_DAYS as $term => $days) {
                if (str_contains($normalizedCategory, $term)) {
                    return ShelfLifeEstimate::fromCategory($days, $term, $location);
                }
            }
        }

        return ShelfLifeEstimate::fromLocation(self::LOCATION_DAYS[$location->value], $location);
    }

    /**
     * Durée de conservation après ouverture — distincte de `estimate()` et sans
     * son repli sur l'emplacement : ne pas savoir combien de temps un yaourt
     * ouvert se garde n'autorise pas à inventer une réponse. `null` doit rester
     * lisible comme « inconnu », jamais comme « illimité ».
     *
     * @param list<ProductTrait> $traits
     */
    public function estimateAfterOpening(
        string $name,
        ?string $category = null,
        array $traits = [],
        StorageLocation $location = StorageLocation::Fridge,
    ): ?ShelfLifeEstimate {
        $best = $this->matcher->match($name, self::AFTER_OPENING_DAYS, [], PreparedDish::TERMS);

        if (null !== $best) {
            return ShelfLifeEstimate::fromFood($best['value'], $best['term'], $location);
        }

        foreach ($traits as $trait) {
            if (isset(self::AFTER_OPENING_TRAIT_DAYS[$trait->value])) {
                return ShelfLifeEstimate::fromTrait(
                    self::AFTER_OPENING_TRAIT_DAYS[$trait->value],
                    mb_strtolower($trait->label()),
                    $location,
                );
            }
        }

        return null;
    }

    /**
     * L'emplacement demandé, sinon la durée la plus courte déclarée par l'aliment.
     *
     * Prendre la plus courte est délibéré : ranger un aliment ailleurs que là où on
     * l'attend ne doit pas allonger sa durée de conservation par accident.
     *
     * @param array<string, int> $days
     */
    private function daysFor(array $days, StorageLocation $location): int
    {
        return $days[$location->value] ?? min($days);
    }
}
