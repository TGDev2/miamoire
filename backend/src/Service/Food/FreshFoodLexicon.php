<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Enum\Unit;

/**
 * Les aliments qui n'ont pas d'emballage pour les protéger — et comment on les compte.
 *
 * Ce lexique répond aux deux seules questions que la durée de conservation ne
 * couvrait pas : **est-ce frais**, et **dans quelle unité ça se saisit
 * naturellement**. Une courgette se compte, des champignons se pèsent, un reste
 * de gratin se compte en portions ; aucun des trois ne se décrit par un nombre
 * d'exemplaires et une contenance.
 *
 * ## Ce lexique est un sous-ensemble STRICT de ShelfLifeEstimator::FOODS
 *
 * Deux raisons, et la seconde est la plus importante :
 *
 * - un aliment frais doit toujours pouvoir recevoir une date estimée, donc avoir
 *   une durée de conservation propre. `FreshFoodLexiconTest` interdit la dérive ;
 * - les termes doivent être identiques des deux côtés, sinon un même libellé
 *   serait reconnu par l'un et pas par l'autre.
 *
 * ## Ce qui n'y figure PAS, et pourquoi
 *
 * `creme`, `lait`, `yaourt`, `fromage`, `beurre`, `oeuf`, `jambon`, `lardon`,
 * `saucisson`, `compote`, `pates fraiches` sont dans `FOODS` mais délibérément
 * absents d'ici : ce sont des produits **conditionnés**, vendus sous emballage
 * avec une date imprimée. En particulier rien ne se déclenche sur le mot
 * « fraîche » : « crème fraîche » reste un produit conditionné (leçon n° 2 —
 * une liste de termes se vérifie aliment par aliment, jamais en bloc).
 */
final class FreshFoodLexicon
{
    /**
     * Poids indicatif d'une pièce, en grammes, quand l'aliment se compte.
     *
     * @var array<string, array{unit: Unit, weight?: int}>
     */
    private const FRESH = [
        // Légumes qui se comptent
        'salade' => ['unit' => Unit::Piece, 'weight' => 200],
        'laitue' => ['unit' => Unit::Piece, 'weight' => 300],
        'courgette' => ['unit' => Unit::Piece, 'weight' => 250],
        'aubergine' => ['unit' => Unit::Piece, 'weight' => 300],
        'concombre' => ['unit' => Unit::Piece, 'weight' => 350],
        'poivron' => ['unit' => Unit::Piece, 'weight' => 160],
        'tomate' => ['unit' => Unit::Piece, 'weight' => 120],
        'brocoli' => ['unit' => Unit::Piece, 'weight' => 500],
        'chou fleur' => ['unit' => Unit::Piece, 'weight' => 800],
        'chou' => ['unit' => Unit::Piece, 'weight' => 1000],
        'celeri' => ['unit' => Unit::Piece, 'weight' => 700],
        'poireau' => ['unit' => Unit::Piece, 'weight' => 150],
        'carotte' => ['unit' => Unit::Piece, 'weight' => 120],
        'navet' => ['unit' => Unit::Piece, 'weight' => 150],
        'betterave' => ['unit' => Unit::Piece, 'weight' => 200],
        'courge' => ['unit' => Unit::Piece, 'weight' => 1200],
        'potiron' => ['unit' => Unit::Piece, 'weight' => 2000],
        'butternut' => ['unit' => Unit::Piece, 'weight' => 900],
        'oignon' => ['unit' => Unit::Piece, 'weight' => 150],
        'echalote' => ['unit' => Unit::Piece, 'weight' => 30],
        'ail' => ['unit' => Unit::Piece, 'weight' => 60],
        // Vendus en botte : une botte est bien l'objet qu'on compte.
        'radis' => ['unit' => Unit::Piece, 'weight' => 250],
        'asperge' => ['unit' => Unit::Piece, 'weight' => 500],
        'persil' => ['unit' => Unit::Piece, 'weight' => 30],
        'coriandre' => ['unit' => Unit::Piece, 'weight' => 30],
        'basilic' => ['unit' => Unit::Piece, 'weight' => 30],
        'ciboulette' => ['unit' => Unit::Piece, 'weight' => 30],
        'menthe' => ['unit' => Unit::Piece, 'weight' => 30],

        // Légumes qui se pèsent
        'mache' => ['unit' => Unit::Gram],
        'roquette' => ['unit' => Unit::Gram],
        'epinard' => ['unit' => Unit::Gram],
        'champignon' => ['unit' => Unit::Gram],
        'petit pois' => ['unit' => Unit::Gram],
        'haricot vert' => ['unit' => Unit::Gram],
        'pomme de terre' => ['unit' => Unit::Gram],
        'patate douce' => ['unit' => Unit::Gram],

        // Fruits qui se comptent
        'banane' => ['unit' => Unit::Piece, 'weight' => 120],
        'pomme' => ['unit' => Unit::Piece, 'weight' => 150],
        'poire' => ['unit' => Unit::Piece, 'weight' => 170],
        'peche' => ['unit' => Unit::Piece, 'weight' => 150],
        'nectarine' => ['unit' => Unit::Piece, 'weight' => 140],
        'abricot' => ['unit' => Unit::Piece, 'weight' => 50],
        'prune' => ['unit' => Unit::Piece, 'weight' => 50],
        'orange' => ['unit' => Unit::Piece, 'weight' => 200],
        'clementine' => ['unit' => Unit::Piece, 'weight' => 80],
        'mandarine' => ['unit' => Unit::Piece, 'weight' => 80],
        'citron' => ['unit' => Unit::Piece, 'weight' => 100],
        'pamplemousse' => ['unit' => Unit::Piece, 'weight' => 400],
        'kiwi' => ['unit' => Unit::Piece, 'weight' => 90],
        'ananas' => ['unit' => Unit::Piece, 'weight' => 1200],
        'mangue' => ['unit' => Unit::Piece, 'weight' => 350],
        'avocat' => ['unit' => Unit::Piece, 'weight' => 200],
        'figue' => ['unit' => Unit::Piece, 'weight' => 50],
        'melon' => ['unit' => Unit::Piece, 'weight' => 1000],
        'pasteque' => ['unit' => Unit::Piece, 'weight' => 4000],

        // Fruits qui se pèsent
        'raisin' => ['unit' => Unit::Gram],
        'fraise' => ['unit' => Unit::Gram],
        'framboise' => ['unit' => Unit::Gram],
        'mure' => ['unit' => Unit::Gram],
        'myrtille' => ['unit' => Unit::Gram],
        'cerise' => ['unit' => Unit::Gram],

        // Viandes et poissons achetés au détail — aucune date imprimée à la coupe
        'viande hachee' => ['unit' => Unit::Gram],
        'steak hache' => ['unit' => Unit::Piece, 'weight' => 125],
        'boeuf' => ['unit' => Unit::Gram],
        'veau' => ['unit' => Unit::Gram],
        'agneau' => ['unit' => Unit::Gram],
        'porc' => ['unit' => Unit::Gram],
        'poulet' => ['unit' => Unit::Gram],
        'dinde' => ['unit' => Unit::Gram],
        'volaille' => ['unit' => Unit::Gram],
        'poisson' => ['unit' => Unit::Gram],
        'saumon' => ['unit' => Unit::Gram],
        'cabillaud' => ['unit' => Unit::Gram],
        'crevette' => ['unit' => Unit::Gram],
        'moule' => ['unit' => Unit::Gram],

        // Boulangerie : une baguette ne porte pas de date
        'pain' => ['unit' => Unit::Piece, 'weight' => 250],

        // Préparations maison — le cas le plus frais qui soit
        'reste' => ['unit' => Unit::Portion],
        'gratin' => ['unit' => Unit::Portion],
        'salade composee' => ['unit' => Unit::Portion],
        'soupe' => ['unit' => Unit::Milliliter],
    ];

    public function __construct(private readonly FoodTermMatcher $matcher)
    {
    }

    /** `null` quand le libellé ne désigne aucun aliment frais connu. */
    public function find(string $label): ?FreshFood
    {
        $match = $this->matcher->match($label, self::FRESH, [], PreparedDish::TERMS);

        if (null === $match) {
            return null;
        }

        return new FreshFood(
            $match['term'],
            $match['value']['unit'],
            $match['value']['weight'] ?? null,
        );
    }

    /**
     * Les termes déclarés frais — lus par le test qui interdit la dérive avec
     * {@see \App\Service\Freshness\ShelfLifeEstimator::knownTerms()}.
     *
     * @return list<string>
     */
    public static function terms(): array
    {
        return array_map('strval', array_keys(self::FRESH));
    }
}
