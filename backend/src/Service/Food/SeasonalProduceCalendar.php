<?php

declare(strict_types=1);

namespace App\Service\Food;

/**
 * Les fruits et légumes de saison en France, mois par mois.
 *
 * Sert deux choses, et une seule des deux touche au stock :
 *
 * - le **registre** des recettes proposées — une ratatouille en août, un gratin
 *   de courge en novembre ;
 * - surtout, les **courses** : quand le foyer autorise quelques ingrédients
 *   manquants, ceux-ci doivent venir d'ici. Faire acheter une fraise en décembre
 *   est une mauvaise suggestion, et c'est la seule que l'application peut éviter
 *   sans rien savoir des prix.
 *
 * Ce que cette table ne fait PAS : décider de ce qu'on cuisine. Un aliment déjà
 * en stock se cuisine, en saison ou non — le garde-fou est écrit dans le prompt,
 * parce qu'une suggestion malheureuse se rattrape, un aliment jeté non.
 *
 * Termes au singulier et sans article, comme {@see FreshFoodLexicon}, pour rester
 * lisibles par un humain autant que par le modèle.
 */
final class SeasonalProduceCalendar
{
    /** @var array<int, array{legumes: list<string>, fruits: list<string>}> */
    private const MONTHS = [
        1 => [
            'legumes' => ['poireau', 'carotte', 'chou', 'chou de Bruxelles', 'chou-fleur', 'endive', 'panais', 'navet', 'betterave', 'céleri-rave', 'courge', 'potiron', 'topinambour', 'salsifis', 'mâche', 'épinard', 'oignon', 'pomme de terre'],
            'fruits' => ['orange', 'clémentine', 'mandarine', 'pamplemousse', 'citron', 'kiwi', 'poire', 'pomme'],
        ],
        2 => [
            'legumes' => ['poireau', 'carotte', 'chou', 'chou-fleur', 'endive', 'panais', 'navet', 'betterave', 'céleri-rave', 'courge', 'topinambour', 'salsifis', 'mâche', 'épinard', 'oignon', 'pomme de terre'],
            'fruits' => ['orange', 'clémentine', 'pamplemousse', 'citron', 'kiwi', 'poire', 'pomme'],
        ],
        3 => [
            'legumes' => ['poireau', 'carotte', 'chou', 'chou-fleur', 'endive', 'navet', 'betterave', 'épinard', 'blette', 'mâche', 'cresson', 'radis', 'oignon', 'pomme de terre'],
            'fruits' => ['orange', 'pamplemousse', 'citron', 'kiwi', 'poire', 'pomme'],
        ],
        4 => [
            'legumes' => ['asperge', 'radis', 'épinard', 'blette', 'petit pois', 'artichaut', 'carotte', 'navet', 'poireau', 'chou-fleur', 'laitue', 'cresson', 'oignon nouveau', 'pomme de terre nouvelle'],
            'fruits' => ['rhubarbe', 'fraise', 'pomme', 'kiwi', 'citron'],
        ],
        5 => [
            'legumes' => ['asperge', 'petit pois', 'fève', 'radis', 'artichaut', 'courgette', 'concombre', 'laitue', 'épinard', 'blette', 'carotte', 'navet', 'oignon nouveau', 'ail nouveau', 'pomme de terre nouvelle'],
            'fruits' => ['fraise', 'rhubarbe', 'cerise', 'pomme'],
        ],
        6 => [
            'legumes' => ['courgette', 'aubergine', 'tomate', 'concombre', 'poivron', 'haricot vert', 'petit pois', 'fève', 'artichaut', 'betterave', 'carotte', 'laitue', 'radis', 'blette', 'ail', 'oignon'],
            'fruits' => ['fraise', 'cerise', 'abricot', 'framboise', 'groseille', 'melon', 'pêche', 'rhubarbe'],
        ],
        7 => [
            'legumes' => ['tomate', 'courgette', 'aubergine', 'poivron', 'concombre', 'haricot vert', 'betterave', 'carotte', 'fenouil', 'maïs', 'laitue', 'blette', 'oignon', 'ail', 'échalote'],
            'fruits' => ['abricot', 'pêche', 'nectarine', 'melon', 'pastèque', 'framboise', 'groseille', 'cassis', 'cerise', 'prune', 'myrtille'],
        ],
        8 => [
            'legumes' => ['tomate', 'courgette', 'aubergine', 'poivron', 'concombre', 'haricot vert', 'maïs', 'fenouil', 'betterave', 'carotte', 'blette', 'laitue', 'oignon', 'ail', 'poireau'],
            'fruits' => ['pêche', 'nectarine', 'abricot', 'melon', 'pastèque', 'prune', 'mirabelle', 'figue', 'framboise', 'mûre', 'myrtille', 'raisin'],
        ],
        9 => [
            'legumes' => ['tomate', 'courgette', 'aubergine', 'poivron', 'haricot vert', 'maïs', 'betterave', 'carotte', 'poireau', 'brocoli', 'chou', 'fenouil', 'blette', 'épinard', 'champignon', 'potiron'],
            'fruits' => ['raisin', 'figue', 'prune', 'mirabelle', 'pêche', 'poire', 'pomme', 'mûre', 'noisette', 'noix'],
        ],
        10 => [
            'legumes' => ['potiron', 'courge', 'butternut', 'poireau', 'carotte', 'brocoli', 'chou', 'chou-fleur', 'betterave', 'céleri', 'épinard', 'blette', 'champignon', 'panais', 'navet', 'endive'],
            'fruits' => ['raisin', 'pomme', 'poire', 'coing', 'figue', 'châtaigne', 'noix', 'noisette', 'kiwi'],
        ],
        11 => [
            'legumes' => ['potiron', 'courge', 'butternut', 'poireau', 'carotte', 'chou', 'chou de Bruxelles', 'chou-fleur', 'endive', 'panais', 'navet', 'betterave', 'céleri-rave', 'épinard', 'mâche', 'salsifis', 'topinambour', 'champignon'],
            'fruits' => ['pomme', 'poire', 'coing', 'châtaigne', 'noix', 'clémentine', 'kiwi', 'orange'],
        ],
        12 => [
            'legumes' => ['poireau', 'carotte', 'chou', 'chou de Bruxelles', 'chou-fleur', 'endive', 'panais', 'navet', 'betterave', 'céleri-rave', 'courge', 'potiron', 'topinambour', 'salsifis', 'mâche', 'épinard'],
            'fruits' => ['clémentine', 'mandarine', 'orange', 'pamplemousse', 'citron', 'kiwi', 'poire', 'pomme', 'châtaigne'],
        ],
    ];

    /** @return array{legumes: list<string>, fruits: list<string>} */
    public function forMonth(int $month): array
    {
        return self::MONTHS[$month] ?? throw new \InvalidArgumentException(
            sprintf('Mois hors de 1-12 : %d.', $month),
        );
    }
}
