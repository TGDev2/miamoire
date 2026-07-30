<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MealSlot;
use App\Exception\StockException;
use App\Repository\MealPlanEntryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un repas à venir dans le plan court du foyer — pas un calendrier : une
 * liste courte et jetable, plafonnée côté service (voir `MealPlanService`).
 *
 * Contient EXACTEMENT un des trois : une recette enregistrée, une proposition
 * IA jamais enregistrée (conservée telle quelle en JSON, comme `SavedRecipe`),
 * ou un simple libellé libre. Le constructeur est privé et les trois fabriques
 * nommées ci-dessous sont l'unique point d'entrée : l'invariant est tenu à la
 * construction, pas par convention.
 *
 * Aucune quantité n'est jamais déduite ici — voir `MealPlanService` pour le
 * calcul de réservation, purement en lecture, et `RecipeConfirmationService`
 * pour le remplacement de la réservation par une consommation réelle.
 */
#[ORM\Entity(repositoryClass: MealPlanEntryRepository::class)]
#[ORM\Table(name: 'meal_plan_entry')]
#[ORM\Index(columns: ['household_id', 'date'], name: 'idx_meal_plan_entry_household_date')]
class MealPlanEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'string', length: 16, enumType: MealSlot::class)]
    private MealSlot $slot;

    /**
     * Recette gardée dans « Mes recettes ». Supprimer la recette enregistrée
     * supprime aussi l'entrée en cascade : sans nom ni document de repli, une
     * entrée qui perdrait sa seule recette deviendrait un 4ᵉ état invalide
     * (aucun des trois champs de contenu renseigné) sur une liste censée
     * rester courte et jetable — un choix délibéré, pas un oubli.
     */
    #[ORM\ManyToOne(targetEntity: SavedRecipe::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?SavedRecipe $savedRecipe = null;

    /** Proposition IA jamais enregistrée, normalisée comme le serait une `SavedRecipe`. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $recipeSnapshot = null;

    /** Repas libre — « Resto ce soir » — sans aucune ligne de consommation. */
    #[ORM\Column(length: 200, nullable: true)]
    private ?string $label = null;

    /** Qui a ajouté ce repas au plan. Facultatif, purement informatif. */
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $addedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Household $household, \DateTimeImmutable $date, MealSlot $slot, ?Member $addedBy)
    {
        $this->id = Uuid::v7();
        $this->household = $household;
        // Toujours ramené à minuit : la colonne est un DATE sans heure, mais
        // tant que l'entité reste en mémoire (accès par la carte d'identité
        // Doctrine, sans relecture depuis la base), une heure résiduelle
        // fausserait la comparaison chronologique de `MealPlanService` — deux
        // repas du même jour se retrouveraient départagés par l'heure de leur
        // création plutôt que par leur créneau.
        $this->date = $date->setTime(0, 0, 0);
        $this->slot = $slot;
        $this->addedBy = $addedBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public static function forSavedRecipe(
        Household $household,
        \DateTimeImmutable $date,
        MealSlot $slot,
        SavedRecipe $recipe,
        ?Member $addedBy = null,
    ): self {
        $entry = new self($household, $date, $slot, $addedBy);
        $entry->savedRecipe = $recipe;

        return $entry;
    }

    /** @param array<string, mixed> $normalizedRecipe déjà passé par `RecipeNormalizer::normalize()` */
    public static function forSnapshot(
        Household $household,
        \DateTimeImmutable $date,
        MealSlot $slot,
        array $normalizedRecipe,
        ?Member $addedBy = null,
    ): self {
        $entry = new self($household, $date, $slot, $addedBy);
        $entry->recipeSnapshot = $normalizedRecipe;

        return $entry;
    }

    public static function forFreeform(
        Household $household,
        \DateTimeImmutable $date,
        MealSlot $slot,
        string $label,
        ?Member $addedBy = null,
    ): self {
        $label = trim($label);
        if ('' === $label) {
            throw new StockException('Indiquez un intitulé pour un repas libre.');
        }

        $entry = new self($household, $date, $slot, $addedBy);
        $entry->label = mb_substr($label, 0, 200);

        return $entry;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getSlot(): MealSlot
    {
        return $this->slot;
    }

    public function getSavedRecipe(): ?SavedRecipe
    {
        return $this->savedRecipe;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getAddedBy(): ?Member
    {
        return $this->addedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Le document de recette à jour, quelle que soit son origine — `null` pour un repas libre. */
    public function recipeDocument(): ?array
    {
        return $this->recipeSnapshot ?? $this->savedRecipe?->getRecipe();
    }

    public function displayName(): string
    {
        if (null !== $this->label) {
            return $this->label;
        }

        $recipe = $this->recipeDocument();

        return null !== $recipe && is_string($recipe['recipeName'] ?? null)
            ? $recipe['recipeName']
            : 'Repas';
    }

    /** @return 'saved_recipe'|'snapshot'|'freeform' */
    public function kind(): string
    {
        return match (true) {
            null !== $this->savedRecipe => 'saved_recipe',
            null !== $this->recipeSnapshot => 'snapshot',
            default => 'freeform',
        };
    }

    public function reschedule(\DateTimeImmutable $date, MealSlot $slot): void
    {
        $this->date = $date->setTime(0, 0, 0);
        $this->slot = $slot;
    }

    public function belongsTo(Household $household): bool
    {
        return $this->household->getId()->equals($household->getId());
    }
}
