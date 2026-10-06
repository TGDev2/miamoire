<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Les composantes qu'on peut exiger d'un plat avant la génération de recettes.
 *
 * Aucune donnée du stock ne range un aliment dans ces groupes — `category` est
 * un texte libre venu d'Open Food Facts. C'est donc le modèle qui classe, à
 * partir de `definition()` : elle est la seule chose qu'il saura du groupe.
 */
enum FoodGroup: string
{
    case Starch = 'starch';
    case Vegetable = 'vegetable';
    case Protein = 'protein';

    public function label(): string
    {
        return match ($this) {
            self::Starch => 'Féculent',
            self::Vegetable => 'Légume',
            self::Protein => 'Protéine',
        };
    }

    /**
     * Ce que le groupe recouvre, dit au modèle.
     *
     * La pomme de terre est un féculent, pas un légume, et les légumineuses
     * comptent des deux côtés féculent/protéine : sans ces deux précisions, une
     * purée passe pour « le légume » et un dahl est écarté faute de protéine.
     */
    public function definition(): string
    {
        return match ($this) {
            self::Starch => 'un féculent (pâtes, riz, pommes de terre, pain, semoule, blé, quinoa, polenta, légumineuses…)',
            self::Vegetable => 'un légume (frais, surgelé ou en conserve ; la pomme de terre n\'en est pas un)',
            self::Protein => 'une protéine (viande, volaille, poisson, fruits de mer, œufs, tofu, tempeh, légumineuses, fromage…)',
        };
    }

    /**
     * Les groupes connus parmi `$values`, sans doublon, dans l'ordre reçu.
     *
     * Une valeur inconnue est écartée plutôt que refusée : comme pour
     * l'équipement, une application plus récente que le serveur ne doit pas
     * empêcher de cuisiner.
     *
     * @param list<string> $values
     *
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        $groups = [];

        foreach ($values as $value) {
            $case = self::tryFrom($value);

            if (null !== $case && !in_array($case, $groups, true)) {
                $groups[] = $case;
            }
        }

        return $groups;
    }
}
