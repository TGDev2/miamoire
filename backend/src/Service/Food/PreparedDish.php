<?php

declare(strict_types=1);

namespace App\Service\Food;

/**
 * Les termes qui nomment un plat, et non un ingrédient.
 *
 * « Soupe de potiron » contient deux aliments connus. La règle habituelle — le
 * terme le plus long gagne — y désignait `potiron`, et la soupe héritait alors des
 * soixante jours de placard d'une courge entière. Une soupe maison se garde trois
 * jours au réfrigérateur : l'écart n'est pas une nuance, c'est un aliment avarié
 * servi à table.
 *
 * La règle qui répare cela est culinaire, pas technique : **un plat se nomme par sa
 * forme, l'ingrédient qui suit ne dit que ce dont il est fait.** Une soupe de
 * potiron est une soupe ; un reste de poulet est un reste. Ces termes-là l'emportent
 * donc quelle que soit leur longueur.
 *
 * La liste est volontairement courte et vérifiée un à un (leçon n° 2) : n'y entre
 * qu'un mot qui, seul, désigne déjà une préparation achevée. « Pâte » ou « purée »
 * n'y sont pas — ils nomment aussi bien un ingrédient.
 */
final class PreparedDish
{
    /**
     * Appliqués par chaque table à ses propres termes : ce qui n'y figure pas est
     * simplement ignoré.
     *
     * @var list<string>
     */
    public const TERMS = ['reste', 'gratin', 'soupe', 'compote', 'salade composee'];
}
