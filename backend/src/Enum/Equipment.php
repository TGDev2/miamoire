<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ce avec quoi le foyer peut réellement cuisiner.
 *
 * La liste vivait en clair dans {@see \App\Controller\ReferenceController}, seul
 * îlot littéral d'un contrôleur qui ne fait que dérouler des enums, et arrivait
 * au modèle comme une suite de mots sans signification : « barbecue » ne lui
 * apprenait ni la chaleur des braises, ni le temps de chauffe, ni qu'aucune
 * sauce liée ne s'y fait.
 *
 * Chaque équipement porte donc, comme {@see ProductTrait} et {@see FoodCut}, la
 * consigne exacte transmise au générateur — ce qu'il permet, ce qu'il interdit.
 * Seuls les équipements réellement cochés entrent dans le prompt : le décrire
 * quand personne ne s'en sert ne ferait qu'allonger la consigne.
 */
enum Equipment: string
{
    case Stovetop = 'stovetop';
    case Oven = 'oven';
    case Microwave = 'microwave';
    case Blender = 'blender';
    case PressureCooker = 'pressure_cooker';
    case Airfryer = 'airfryer';
    case Barbecue = 'barbecue';
    case Plancha = 'plancha';
    case MultiCooker = 'multi_cooker';

    public function label(): string
    {
        return match ($this) {
            self::Stovetop => 'Plaque / poêle',
            self::Oven => 'Four',
            self::Microwave => 'Micro-ondes',
            self::Blender => 'Mixeur',
            self::PressureCooker => 'Autocuiseur',
            self::Airfryer => 'Air fryer',
            self::Barbecue => 'Barbecue',
            self::Plancha => 'Plancha',
            self::MultiCooker => 'Robot cuiseur',
        };
    }

    /** Ce que le générateur de recettes doit en déduire. */
    public function instruction(): string
    {
        return match ($this) {
            self::Stovetop => 'feu vif ou doux dans une poêle, une sauteuse ou une casserole : saisies, poêlées, sauces, réductions, cuissons à l\'eau. Précise le diamètre quand il compte',
            self::Oven => 'chaleur enveloppante : rôtis, gratins, tartes, papillotes, légumes rôtis. Donne toujours la température en °C et la durée',
            self::Microwave => 'réchauffage et cuisson vapeur express, en quelques minutes : ni coloration, ni croûte, ni caramélisation — n\'en attends jamais un gratin',
            self::Blender => 'mixe et émulsionne : veloutés, coulis, pestos, pâtes à crêpes, smoothies. Précise la texture visée',
            self::PressureCooker => 'sous pression, les cuissons longues sont divisées par trois : légumes secs, pot-au-feu, viandes à braiser, riz. Compte le temps à partir de la mise en pression',
            self::Airfryer => 'air brassé très chaud dans un petit panier : croustillant sans friture, en une fraction du temps du four. À charger en une seule couche, sans excès de matière grasse, et sans pâte liquide',
            self::Barbecue => 'cuisson à la braise ou au gaz, à l\'extérieur, chaleur vive et fumée : viandes en pavés ou en brochettes, poissons entiers ou en papillote, légumes en tranches épaisses, marinades. Compte 20 à 30 minutes de chauffe avant la première cuisson, et distingue le feu direct du feu indirect pour les pièces épaisses. Ni sauce liée, ni pâte, ni bain-marie',
            self::Plancha => 'plaque lisse très chaude, à sec ou à peine huilée : poissons, gambas, viandes fines, légumes en tranches. Saisit et colore en quelques minutes, sans fumée et sans temps de chauffe des braises ; ni sauce, ni cuisson longue',
            self::MultiCooker => 'un appareil qui chauffe, mixe et mijote dans une même cuve : veloutés, risottos, sauces, compotes, cuissons sous pression. Indique le mode, la température et la durée ; ne l\'emploie pas pour ce qui doit colorer, croustiller ou gratiner',
        };
    }

    /**
     * Un équipement d'extérieur, dont la saison décide autant que l'envie.
     *
     * Sert à proposer le barbecue quand il fait beau sans jamais l'interdire en
     * janvier : un foyer qui le coche en plein hiver sait ce qu'il fait.
     */
    public function isOutdoor(): bool
    {
        return match ($this) {
            self::Barbecue, self::Plancha => true,
            default => false,
        };
    }

    /**
     * Les valeurs reçues d'un client, réduites à celles que l'on connaît.
     *
     * Une chaîne inconnue était jusqu'ici recopiée telle quelle dans le prompt :
     * le modèle se voyait autoriser un équipement qui n'existe pas.
     *
     * @param list<string> $values
     *
     * @return list<self>
     */
    public static function fromValues(array $values): array
    {
        $equipment = [];

        foreach ($values as $value) {
            $case = self::tryFrom($value);

            if (null !== $case && !in_array($case, $equipment, true)) {
                $equipment[] = $case;
            }
        }

        return $equipment;
    }

    /**
     * @param list<self> $equipment
     *
     * @return list<string>
     */
    public static function toValues(array $equipment): array
    {
        return array_values(array_unique(array_map(static fn (self $case) => $case->value, $equipment)));
    }
}
