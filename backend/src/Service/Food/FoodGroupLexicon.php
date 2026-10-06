<?php

declare(strict_types=1);

namespace App\Service\Food;

use App\Enum\FoodGroup;

/**
 * Les aliments courants dont le groupe ne se discute pas — de quoi démentir le
 * modèle quand il range du riz parmi les protéines.
 *
 * Ce n'est pas un classement de tout ce qui se mange, et il ne doit pas le
 * devenir : il ne sert qu'à **contredire**. Un aliment absent d'ici n'est pas
 * suspect, il est inconnu, et le modèle reste cru sur parole (leçon n° 14 — un
 * contrôle qui écarte des recettes conformes finit par être désactivé). D'où
 * les absences délibérées :
 *
 * - `quinoa`, `mais`, `avoine` : rangés différemment selon les usages ;
 * - `haricot` seul : vert, c'est un légume, rouge ou blanc, une légumineuse ;
 * - `pate` : « pâte feuilletée » et « pâté » s'écrivent pareil une fois
 *   normalisés. Seul `pates` (pluriel, au mot près) désigne les pâtes.
 *
 * Les légumineuses portent deux groupes, féculent et protéine, comme le dit la
 * consigne au modèle ({@see FoodGroup::definition()}) : la contredire ici
 * écarterait un dahl de lentilles pour avoir suivi les instructions.
 */
final class FoodGroupLexicon
{
    private const STARCH = [FoodGroup::Starch];
    private const VEGETABLE = [FoodGroup::Vegetable];
    private const PROTEIN = [FoodGroup::Protein];
    private const LEGUME = [FoodGroup::Starch, FoodGroup::Protein];

    /** @var array<string, list<FoodGroup>> */
    private const FOODS = [
        // Féculents
        'riz' => self::STARCH,
        'spaghetti' => self::STARCH,
        'penne' => self::STARCH,
        'tagliatelle' => self::STARCH,
        'macaroni' => self::STARCH,
        'coquillette' => self::STARCH,
        'fusilli' => self::STARCH,
        'lasagne' => self::STARCH,
        'nouille' => self::STARCH,
        'vermicelle' => self::STARCH,
        'gnocchi' => self::STARCH,
        'pain' => self::STARCH,
        'baguette' => self::STARCH,
        'semoule' => self::STARCH,
        'couscous' => self::STARCH,
        'boulgour' => self::STARCH,
        'polenta' => self::STARCH,
        'farine' => self::STARCH,
        'pomme de terre' => self::STARCH,
        'patate douce' => self::STARCH,
        // Légumineuses
        'lentille' => self::LEGUME,
        'pois chiche' => self::LEGUME,
        'pois casse' => self::LEGUME,
        'haricot rouge' => self::LEGUME,
        'haricot blanc' => self::LEGUME,
        'flageolet' => self::LEGUME,
        'feve' => self::LEGUME,
        // Légumes
        'courgette' => self::VEGETABLE,
        'tomate' => self::VEGETABLE,
        'carotte' => self::VEGETABLE,
        'poivron' => self::VEGETABLE,
        'aubergine' => self::VEGETABLE,
        'oignon' => self::VEGETABLE,
        'poireau' => self::VEGETABLE,
        'epinard' => self::VEGETABLE,
        'brocoli' => self::VEGETABLE,
        'chou' => self::VEGETABLE,
        'chou fleur' => self::VEGETABLE,
        'champignon' => self::VEGETABLE,
        'haricot vert' => self::VEGETABLE,
        'petit pois' => self::VEGETABLE,
        'salade' => self::VEGETABLE,
        'laitue' => self::VEGETABLE,
        'concombre' => self::VEGETABLE,
        'courge' => self::VEGETABLE,
        'potiron' => self::VEGETABLE,
        'butternut' => self::VEGETABLE,
        'navet' => self::VEGETABLE,
        'radis' => self::VEGETABLE,
        'betterave' => self::VEGETABLE,
        'celeri' => self::VEGETABLE,
        'fenouil' => self::VEGETABLE,
        'asperge' => self::VEGETABLE,
        'artichaut' => self::VEGETABLE,
        'endive' => self::VEGETABLE,
        // Protéines
        'poulet' => self::PROTEIN,
        'dinde' => self::PROTEIN,
        'canard' => self::PROTEIN,
        'boeuf' => self::PROTEIN,
        'veau' => self::PROTEIN,
        'porc' => self::PROTEIN,
        'agneau' => self::PROTEIN,
        'jambon' => self::PROTEIN,
        'lardon' => self::PROTEIN,
        'saucisse' => self::PROTEIN,
        'viande' => self::PROTEIN,
        'poisson' => self::PROTEIN,
        'saumon' => self::PROTEIN,
        'thon' => self::PROTEIN,
        'cabillaud' => self::PROTEIN,
        'colin' => self::PROTEIN,
        'merlu' => self::PROTEIN,
        'sardine' => self::PROTEIN,
        'maquereau' => self::PROTEIN,
        'crevette' => self::PROTEIN,
        'moule' => self::PROTEIN,
        'oeuf' => self::PROTEIN,
        'tofu' => self::PROTEIN,
        'tempeh' => self::PROTEIN,
        'fromage' => self::PROTEIN,
        'emmental' => self::PROTEIN,
        'comte' => self::PROTEIN,
        'mozzarella' => self::PROTEIN,
        'parmesan' => self::PROTEIN,
        'feta' => self::PROTEIN,
    ];

    /**
     * Au mot près : `pate` tolérant au pluriel reconnaîtrait aussi le pâté et
     * la pâte feuilletée.
     *
     * @var array<string, list<FoodGroup>>
     */
    private const EXACT = [
        'pates' => self::STARCH,
    ];

    public function __construct(private readonly FoodTermMatcher $matcher)
    {
    }

    /**
     * Les groupes d'un aliment, ou `null` quand le lexique ne le connaît pas —
     * ce qui ne prouve rien contre lui.
     *
     * @return list<FoodGroup>|null
     */
    public function groupsOf(string $label): ?array
    {
        $match = $this->matcher->match($label, self::FOODS, self::EXACT);

        return $match['value'] ?? null;
    }
}
