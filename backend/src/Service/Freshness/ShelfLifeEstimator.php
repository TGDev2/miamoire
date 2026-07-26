<?php

declare(strict_types=1);

namespace App\Service\Freshness;

use App\Enum\ProductTrait;
use App\Enum\StorageLocation;
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
     * Les termes sont cherchés au pluriel tolérant (`tomate` reconnaît « tomates ») ;
     * le terme le plus long l'emporte, pour que « pomme de terre » ne soit jamais
     * traité comme « pomme ».
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
     * termes-là se listent en toutes lettres (leçon n° 2).
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

    public function __construct(private readonly TextNormalizer $normalizer)
    {
    }

    /** @param list<ProductTrait> $traits */
    public function estimate(
        string $name,
        ?string $category = null,
        array $traits = [],
        StorageLocation $location = StorageLocation::Pantry,
    ): ShelfLifeEstimate {
        $haystack = $this->normalizer->toWords($name);

        $food = $this->matchFood($haystack);

        // Le congélateur normalise tout : un légume surgelé se garde des mois,
        // qu'il s'agisse d'épinards ou de haricots. L'aliment ne reprend la main
        // que s'il déclare explicitement une durée de congélation.
        if (StorageLocation::Freezer === $location && (null === $food || !isset($food['days']['freezer']))) {
            return ShelfLifeEstimate::fromLocation(self::LOCATION_DAYS['freezer'], $location);
        }

        if (null !== $food) {
            return ShelfLifeEstimate::fromFood(
                $this->daysFor($food['days'], $location),
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
     * Le terme le plus long gagne : « pomme de terre » n'est pas une « pomme ».
     *
     * @return array{term: string, days: array<string, int>}|null
     */
    private function matchFood(string $haystack): ?array
    {
        $best = null;

        foreach (self::EXACT_FOODS as $term => $days) {
            if (1 === preg_match('/\b'.preg_quote($term, '/').'\b/', $haystack)
                && (null === $best || mb_strlen($term) > mb_strlen($best['term']))) {
                $best = ['term' => $term, 'days' => $days];
            }
        }

        foreach (self::FOODS as $term => $days) {
            if (1 === preg_match($this->pluralTolerantPattern($term), $haystack)
                && (null === $best || mb_strlen($term) > mb_strlen($best['term']))) {
                $best = ['term' => $term, 'days' => $days];
            }
        }

        return $best;
    }

    /** « pomme de terre » doit reconnaître « pommes de terre » sans reconnaître « pommade ». */
    private function pluralTolerantPattern(string $term): string
    {
        $words = array_map(
            static fn (string $word): string => mb_strlen($word) > 3
                ? preg_quote($word, '/').'(?:s|x)?'
                : preg_quote($word, '/'),
            explode(' ', $term),
        );

        return '/\b'.implode('\s+', $words).'\b/';
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
