<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * La forme physique d'un aliment — ce qu'un cuisinier voit en ouvrant le frigo.
 *
 * ## Pourquoi cet axe existe
 *
 * Le générateur de recettes a écrit une recette d'escalopes avec du blanc de
 * poulet **en tranches**. Ce n'était pas une hallucination : les deux portaient
 * la clé `blanc-de-poulet` et le libellé « Blanc de poulet ». Rien, dans tout le
 * modèle, ne distinguait une forme d'une autre — et c'est pourtant la forme qui
 * décide de la technique : une escalope se saisit 3 minutes par face, de l'émincé
 * saute 2 minutes à feu vif, un blanc entier cuit 20 minutes à cœur.
 *
 * L'identité culinaire portait déjà trois axes — de quoi s'agit-il
 * ({@see \App\Service\IngredientKeyGenerator}), que lui a-t-on fait
 * ({@see ProductTrait}), a-t-il une date imprimée ({@see FoodNature}). Celui-ci
 * est le quatrième, et le seul qui manquait à la cuisson.
 *
 * ## Ce n'est pas un trait
 *
 * `ProductTrait::Sliced` occupait cette place et n'y répondait pas : il disait
 * qu'on avait coupé, jamais **en quoi**, et son `isForm()` valant `false`, il ne
 * nommait même pas la variante. La découpe l'a absorbé — voir
 * {@see \App\Command\DetectFoodCutCommand}.
 *
 * Comme pour les traits, la connaissance culinaire tient dans l'enum : ajouter
 * une découpe suffit à l'enseigner au modèle, sans toucher au prompt.
 */
enum FoodCut: string
{
    case Whole = 'whole';
    case Escalope = 'escalope';
    case Steak = 'steak';
    case BoneIn = 'bone_in';
    case Strips = 'strips';
    case Slices = 'slices';
    case Cubes = 'cubes';
    case Pieces = 'pieces';
    case Ground = 'ground';
    case Grated = 'grated';
    case Puree = 'puree';

    /** Lu tel quel dans « Blanc de poulet (escalopes) » : il doit tenir entre parenthèses. */
    public function label(): string
    {
        return match ($this) {
            self::Whole => 'Entier',
            self::Escalope => 'Escalopes',
            self::Steak => 'Pavé / côte',
            self::BoneIn => 'Avec os',
            self::Strips => 'Émincé',
            self::Slices => 'En tranches',
            self::Cubes => 'En dés',
            self::Pieces => 'En morceaux',
            self::Ground => 'Haché',
            self::Grated => 'Râpé',
            self::Puree => 'En purée',
        };
    }

    /** Fragment lisible de l'identifiant : `blanc-de-poulet-escalope`. */
    public function slug(): string
    {
        return match ($this) {
            self::Whole => 'entier',
            self::Escalope => 'escalope',
            self::Steak => 'pave',
            self::BoneIn => 'avec-os',
            self::Strips => 'emince',
            self::Slices => 'tranches',
            self::Cubes => 'des',
            self::Pieces => 'morceaux',
            self::Ground => 'hache',
            self::Grated => 'rape',
            self::Puree => 'puree',
        };
    }

    /** Ce que le générateur de recettes doit en déduire — la cuisson, pas le nom. */
    public function instruction(): string
    {
        return match ($this) {
            // « Thon entier » d'une conserve est entier ET déjà cuit : la forme dit
            // qu'il n'est pas émietté, les `traits` disent s'il reste à cuire. Une
            // instruction qui imposerait ici une cuisson longue les contredirait.
            self::Whole => 'pièce entière, non détaillée, par opposition à un aliment déjà débité : c\'est à la recette de prévoir la découpe si elle en a besoin, et de compter une cuisson à cœur lorsque l\'aliment est encore cru',
            self::Escalope => 'tranche large et fine, environ 1 cm, taillée dans la largeur : saisie 3 à 4 minutes par face à la poêle, au grill ou à la plancha, sans aucune découpe préalable — ne la traite JAMAIS comme un morceau entier à cuire vingt minutes',
            self::Steak => 'morceau épais, au moins 2 cm, avec ou sans os : saisie vive puis cuisson douce, ou four ; c\'est la forme qui convient au grill et au barbecue',
            self::BoneIn => 'morceau avec os — pilon, cuisse, manchon, côtelette : compte 35 à 45 minutes au four ou en cocotte, la chair près de l\'os met plus longtemps que le reste',
            self::Strips => 'déjà taillé en lanières fines : cuisson vive et très courte, 2 à 3 minutes à feu fort, wok ou poêlée — c\'est la forme reçue, pas un morceau entier à détailler',
            self::Slices => 'déjà tranché, 3 à 8 mm — tranches ou rondelles : cuisson rapide, quelques minutes par face, ou emploi à cru ; ne demande jamais de le trancher',
            self::Cubes => 'déjà taillé en cubes : sauté, brochettes, mijoté ou ragoût selon la recette ; ne demande pas de le détailler',
            self::Pieces => 'déjà détaillé en morceaux, sans forme régulière : ne demande pas de le couper, et compte la cuisson de morceaux de 2 à 3 cm',
            self::Ground => 'haché : se façonne — boulettes, farce, galettes — ou se fait rissoler à la poêle en l\'émiettant ; ne se grille jamais en pièce entière',
            self::Grated => 'déjà râpé : s\'incorpore, se saupoudre ou gratine directement, aucune découpe ni aucun mixage',
            self::Puree => 'déjà réduit en purée ou en pulpe : s\'incorpore tel quel, aucun mixage à prévoir',
        };
    }

    /**
     * Poids indicatif d'une pièce, en grammes — `null` quand la forme ne le
     * détermine pas.
     *
     * Quand un stock se compte en pièces, la pièce comptée EST la découpe : une
     * escalope, une tranche. Ce poids prime donc sur celui de
     * {@see \App\Service\Food\FreshFoodLexicon}, qui décrit l'aliment entier —
     * sans quoi le modèle écrirait « 4 blancs de poulet » devant six tranches.
     */
    public function approxPieceWeight(): ?int
    {
        return match ($this) {
            self::Escalope => 150,
            self::Steak => 180,
            self::BoneIn => 150,
            self::Slices => 30,
            self::Whole, self::Strips, self::Cubes,
            self::Pieces, self::Ground, self::Grated, self::Puree => null,
        };
    }

    /**
     * @param list<self> $cuts
     *
     * @return list<string>
     */
    public static function toValues(array $cuts): array
    {
        return array_values(array_unique(array_map(static fn (self $cut) => $cut->value, $cuts)));
    }
}
