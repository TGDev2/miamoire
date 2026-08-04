<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * La saison, déduite du mois.
 *
 * Le générateur de recettes ne recevait aucune date. Il lisait `useBy: 2026-08-06`
 * sans pouvoir dire si c'était demain ou dans un an — l'urgence lui était
 * pré-mâchée par un booléen — et il proposait un pot-au-feu en août aussi
 * volontiers qu'un gaspacho en janvier.
 *
 * Le découpage est **météorologique** et non astronomique : l'été commence le
 * 1ᵉʳ juin, pas le 21. C'est celui de la cuisine et des étals, et il évite de
 * faire dépendre le registre d'un plat d'un solstice à trois jours près.
 *
 * `guidance()` ne prescrit pas un plat, il donne un registre. L'inventaire reste
 * souverain : une courge encore en stock en juillet se cuisine — voir la règle
 * correspondante du prompt.
 */
enum Season: string
{
    case Winter = 'winter';
    case Spring = 'spring';
    case Summer = 'summer';
    case Autumn = 'autumn';

    public static function fromMonth(int $month): self
    {
        return match ($month) {
            12, 1, 2 => self::Winter,
            3, 4, 5 => self::Spring,
            6, 7, 8 => self::Summer,
            9, 10, 11 => self::Autumn,
            default => throw new \InvalidArgumentException(sprintf('Mois hors de 1-12 : %d.', $month)),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Winter => 'hiver',
            self::Spring => 'printemps',
            self::Summer => 'été',
            self::Autumn => 'automne',
        };
    }

    /** Le registre culinaire de la saison, transmis tel quel au modèle. */
    public function guidance(): string
    {
        return match ($this) {
            self::Winter => 'les plats chauds et longs : soupes, veloutés, gratins, mijotés, plats au four, légumes racines et courges, agrumes en dessert. Une salade composée froide n\'est pas un dîner de janvier',
            self::Spring => 'les cuissons courtes et les premières verdures : asperges, petits pois, radis, herbes fraîches, jeunes légumes. On quitte le mijoté sans être encore aux crudités',
            self::Summer => 'les plats froids ou vite saisis : salades composées, crudités, grillades, papillotes, tartes aux légumes d\'été, fruits crus. Rien qui demande deux heures de four quand il fait 30 °C',
            self::Autumn => 'le retour du four et de la cocotte : courges, champignons, poireaux, tartes salées et gratins, sans encore les plats les plus lourds de l\'hiver',
        };
    }
}
