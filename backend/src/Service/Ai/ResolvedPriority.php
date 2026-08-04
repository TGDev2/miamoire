<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\Unit;
use App\Service\Inventory\InventoryLine;
use App\Service\UnitConverter;

/**
 * Un aliment imposé, confronté au stock réel.
 *
 * {@see PriorityChoice} dit ce qui a été demandé, cet objet dit ce qui est
 * réellement visé : une ligne d'inventaire, et une quantité facultative
 * exprimée dans l'unité de cette ligne.
 *
 * C'est ici que « une boîte » devient « 400 g », parce que c'est ici seulement
 * qu'on sait ce que pèse une boîte. Et c'est ici que la cible est **plafonnée à
 * ce qui reste** : demander d'écouler 800 g quand il n'en reste que 500 rendrait
 * le contrôle d'après génération impossible à satisfaire, et écarterait les
 * trois recettes pour une faute que l'utilisateur n'a pas commise.
 *
 * Une seule phrase décrit la cible ({@see describe()}), et prompt, motif de
 * rejet et réponse HTTP la reprennent tous les trois : trois écritures d'une
 * même quantité finiraient par se contredire.
 */
final readonly class ResolvedPriority
{
    public function __construct(
        public InventoryLine $line,
        /** Ce qu'il faut écouler, dans {@see $unit} ; `null` si rien n'a été imposé. */
        public ?float $quantity = null,
        public ?Unit $unit = null,
    ) {
    }

    /**
     * Résout une demande contre une ligne d'inventaire.
     *
     * Quand la quantité demandée n'est pas exploitable — un nombre d'exemplaires
     * sur un aliment sans conditionnement connu, une unité d'une autre famille —
     * elle est abandonnée, **mais pas l'aliment** : l'utilisateur a désigné
     * quelque chose à écouler, et ce geste-là reste valable même si le chiffre
     * qui l'accompagnait ne l'est plus.
     */
    public static function resolve(PriorityChoice $choice, InventoryLine $line, UnitConverter $converter): self
    {
        $target = self::target($choice, $line, $converter);

        if (null === $target) {
            return new self($line);
        }

        // Plafonnée au stock : on ne peut pas écouler ce qu'on n'a pas.
        return new self($line, min($target, $line->quantity), $line->unit);
    }

    /** La cible dans l'unité de la ligne, ou `null` si elle n'est pas exploitable. */
    private static function target(PriorityChoice $choice, InventoryLine $line, UnitConverter $converter): ?float
    {
        if (null !== $choice->packages) {
            return null !== $line->packaging
                ? $line->packaging->totalFor($choice->packages)
                : null;
        }

        if (null === $choice->quantity || null === $choice->unit) {
            return null;
        }

        // `UnitConverter` refuse délibérément de franchir les familles d'unité —
        // des grammes ne deviennent pas des litres — et ce refus protège
        // l'inventaire. On le respecte plutôt que de le contourner.
        return $converter->canConvert($choice->unit, $line->unit)
            ? $converter->convert($choice->quantity, $choice->unit, $line->unit)
            : null;
    }

    public function hasTarget(): bool
    {
        return null !== $this->quantity && null !== $this->unit;
    }

    /** « 400 g », « 1.5 kg » — ou `null` quand aucune quantité n'est imposée. */
    public function describe(): ?string
    {
        if (!$this->hasTarget()) {
            return null;
        }

        return self::format($this->quantity, $this->unit);
    }

    /** Une quantité écrite sans zéros inutiles : « 400 g », jamais « 400.000 g ». */
    public static function format(float $quantity, Unit $unit): string
    {
        $rounded = rtrim(rtrim(number_format($quantity, 3, ',', ' '), '0'), ',');

        return sprintf('%s %s', $rounded, $unit->label());
    }
}
