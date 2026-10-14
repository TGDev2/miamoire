<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Enum\FoodCut;

/**
 * Les aliments dont la forme change la recette — et les formes qu'ils peuvent prendre.
 *
 * Ce lexique répond à deux questions d'un seul geste, et c'est ce qui fait de la
 * découpe un système plutôt qu'un champ de formulaire de plus :
 *
 * - **est-ce que ça compte ici ?** Une farine, un lait, une huile n'ont pas de
 *   découpe : ils renvoient `null`, l'application ne pose aucune question et
 *   n'affiche aucune puce.
 * - **quelles réponses proposer ?** Un blanc de poulet en propose sept, un pain
 *   deux. Onze puces pour chaque aliment seraient une liste, pas une question.
 *
 * Il sert aussi de garde-fou au {@see FoodCutDetector} : « lait entier » ne
 * devient pas une pièce entière, « steak de soja » ne devient pas du haché. Un
 * terme absent d'ici rend le détecteur muet, ce qui est le bon défaut — une
 * découpe fausse égare la cuisson plus sûrement qu'une découpe manquante.
 *
 * Ajouter une famille, c'est une ligne.
 */
final class FoodCutLexicon
{
    /**
     * Toutes les découpes de boucherie, plus celles du service.
     *
     * `Slices` en fait partie : « blanc de poulet en tranches » est précisément
     * le libellé que le générateur avait pris pour une escalope.
     *
     * @var list<FoodCut>
     */
    private const MEAT = [
        FoodCut::Whole, FoodCut::Escalope, FoodCut::Steak, FoodCut::BoneIn,
        FoodCut::Strips, FoodCut::Slices, FoodCut::Cubes, FoodCut::Pieces, FoodCut::Ground,
    ];

    /** @var list<FoodCut> */
    private const FISH = [
        FoodCut::Whole, FoodCut::Steak, FoodCut::Strips, FoodCut::Slices,
        FoodCut::Cubes, FoodCut::Pieces,
    ];

    /** @var list<FoodCut> */
    private const DELI = [FoodCut::Whole, FoodCut::Slices, FoodCut::Cubes, FoodCut::Pieces];

    /** @var list<FoodCut> */
    private const CHEESE = [FoodCut::Whole, FoodCut::Slices, FoodCut::Cubes, FoodCut::Grated];

    /** @var list<FoodCut> */
    private const BREAD = [FoodCut::Whole, FoodCut::Slices];

    /**
     * `Strips` en fait partie, et ce n'est pas une évidence rétrospective : un
     * oignon émincé est la découpe la plus courante de toute la cuisine française.
     * L'oublier faisait retomber « Oignons émincés » sur « en morceaux ».
     *
     * @var list<FoodCut>
     */
    private const VEGETABLE = [
        FoodCut::Whole, FoodCut::Strips, FoodCut::Slices, FoodCut::Cubes,
        FoodCut::Pieces, FoodCut::Grated, FoodCut::Puree,
    ];

    /** @var list<FoodCut> */
    private const FRUIT = [FoodCut::Whole, FoodCut::Slices, FoodCut::Cubes, FoodCut::Puree];

    /**
     * Un plat n'est pas un ingrédient : il n'a pas de découpe, quoi qu'il contienne.
     *
     * Sans cette entrée, « soupe de potiron » retomberait sur `potiron` par la
     * règle du terme le plus long, et l'application demanderait en quels dés on
     * a coupé une soupe. Même raisonnement que {@see PreparedDish}, appliqué ici.
     *
     * @var list<FoodCut>
     */
    private const DISH = [];

    /** @var array<string, list<FoodCut>> */
    private const FOODS = [
        // Plats : reconnus pour être écartés, et prioritaires sur ce qui les compose.
        'reste' => self::DISH,
        'gratin' => self::DISH,
        'soupe' => self::DISH,
        'compote' => self::DISH,
        'salade composee' => self::DISH,

        // Viandes et volailles
        'poulet' => self::MEAT,
        'dinde' => self::MEAT,
        'canard' => self::MEAT,
        'pintade' => self::MEAT,
        'volaille' => self::MEAT,
        'boeuf' => self::MEAT,
        'veau' => self::MEAT,
        'porc' => self::MEAT,
        'agneau' => self::MEAT,
        'lapin' => self::MEAT,

        // Poissons
        'poisson' => self::FISH,
        'saumon' => self::FISH,
        'cabillaud' => self::FISH,
        'colin' => self::FISH,
        'lieu noir' => self::FISH,
        'thon' => self::FISH,
        'truite' => self::FISH,
        'dorade' => self::FISH,
        'merlu' => self::FISH,
        'maquereau' => self::FISH,

        // Charcuterie
        'jambon' => self::DELI,
        'bacon' => self::DELI,
        'lardon' => self::DELI,
        'chorizo' => self::DELI,
        'saucisson' => self::DELI,
        'poitrine fumee' => self::DELI,

        // Fromages
        'fromage' => self::CHEESE,
        'emmental' => self::CHEESE,
        'comte' => self::CHEESE,
        'gruyere' => self::CHEESE,
        'mozzarella' => self::CHEESE,
        'cheddar' => self::CHEESE,
        'parmesan' => self::CHEESE,
        'mimolette' => self::CHEESE,
        'raclette' => self::CHEESE,
        'feta' => self::CHEESE,

        // Pains
        'pain' => self::BREAD,
        'baguette' => self::BREAD,
        'brioche' => self::BREAD,

        // Légumes
        'pomme de terre' => self::VEGETABLE,
        'patate douce' => self::VEGETABLE,
        'carotte' => self::VEGETABLE,
        'courgette' => self::VEGETABLE,
        'aubergine' => self::VEGETABLE,
        'poivron' => self::VEGETABLE,
        'tomate' => self::VEGETABLE,
        'concombre' => self::VEGETABLE,
        'oignon' => self::VEGETABLE,
        'poireau' => self::VEGETABLE,
        'betterave' => self::VEGETABLE,
        'navet' => self::VEGETABLE,
        'panais' => self::VEGETABLE,
        'celeri' => self::VEGETABLE,
        'chou' => self::VEGETABLE,
        'chou fleur' => self::VEGETABLE,
        'brocoli' => self::VEGETABLE,
        'courge' => self::VEGETABLE,
        'potiron' => self::VEGETABLE,
        'butternut' => self::VEGETABLE,
        'champignon' => self::VEGETABLE,
        'fenouil' => self::VEGETABLE,

        // Fruits
        'pomme' => self::FRUIT,
        'poire' => self::FRUIT,
        'ananas' => self::FRUIT,
        'mangue' => self::FRUIT,
        'peche' => self::FRUIT,
        'abricot' => self::FRUIT,
        'banane' => self::FRUIT,
        'melon' => self::FRUIT,
        'pasteque' => self::FRUIT,
    ];

    public function __construct(private readonly FoodTermMatcher $matcher)
    {
    }

    /**
     * Les découpes que cet aliment peut prendre.
     *
     * Trois réponses, et la distinction entre les deux dernières compte :
     *
     * - une liste non vide — voilà les formes à proposer ;
     * - `[]` — **reconnu, et sans découpe possible** : c'est un plat. Un
     *   détecteur doit s'y arrêter net ;
     * - `null` — inconnu. La question ne se pose pas pour autant, mais rien
     *   n'interdit qu'un libellé nomme lui-même sa forme (« Steak haché 5 % MG »
     *   ne nomme aucun aliment connu et reste du haché).
     *
     * Côté interface, les deux derniers cas se confondent : pas de puces.
     *
     * @return list<FoodCut>|null
     */
    public function plausibleCuts(string $label): ?array
    {
        $match = $this->matcher->match($label, self::FOODS, [], PreparedDish::TERMS);

        return null === $match ? null : $match['value'];
    }

    /**
     * A fruit or a vegetable — the foods sold whole unless the label says
     * otherwise, see {@see CutSuggester}.
     */
    public function isProduce(string $label): bool
    {
        return $this->isFamily($label, [self::VEGETABLE, self::FRUIT]);
    }

    /**
     * Meat and fish: the foods whose cut decides the cooking technique — an
     * escalope, strips and a whole breast are three different recipes. The
     * only foods the app asks about. Elsewhere a wrong cut costs one extra
     * "cut it" step, and a term found anywhere in a product name ("Pommes
     * noisettes", "Macaroni - Tomate Mozza") makes the question absurd.
     */
    public function decidesCooking(string $label): bool
    {
        return $this->isFamily($label, [self::MEAT, self::FISH]);
    }

    /** @param list<list<FoodCut>> $families */
    private function isFamily(string $label, array $families): bool
    {
        $match = $this->matcher->match($label, self::FOODS, [], PreparedDish::TERMS);

        return null !== $match && in_array($match['value'], $families, true);
    }

    /** @return list<string> les termes reconnus, lus par les tests de dérive */
    public function terms(): array
    {
        return array_keys(self::FOODS);
    }
}
