<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedRecipeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une recette que le foyer a décidé de garder.
 *
 * Les propositions vivaient jusqu'ici dans la mémoire de l'application : quitter
 * l'écran suffisait à perdre une recette qui plaisait. Elle est donc conservée
 * **côté serveur**, comme le stock : le foyer entier y accède, et changer de
 * téléphone ne l'efface pas.
 *
 * La recette est stockée telle quelle, en JSON. Ce n'est pas de la paresse : une
 * recette est un document — étapes, substitutions, proposition de déduction —
 * dont aucune partie n'est jamais interrogée séparément. La normaliser en tables
 * coûterait cinq jointures pour n'autoriser aucune requête qu'on veuille écrire.
 * Ce que la base garantit ici, c'est l'unicité (voir `fingerprint`), pas la forme :
 * celle-là est tenue par `RecipeNormalizer`, à l'entrée.
 */
#[ORM\Entity(repositoryClass: SavedRecipeRepository::class)]
#[ORM\Table(name: 'saved_recipe')]
#[ORM\UniqueConstraint(name: 'uniq_saved_recipe_fingerprint', columns: ['household_id', 'fingerprint'])]
#[ORM\Index(columns: ['household_id', 'created_at'], name: 'idx_saved_recipe_household_created')]
class SavedRecipe
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    /** Recopié hors du JSON : c'est le seul champ sur lequel on trie et on cherche. */
    #[ORM\Column(length: 200)]
    private string $name;

    /**
     * Empreinte du contenu normalisé.
     *
     * Portée par un index unique par foyer : appuyer deux fois sur « Enregistrer »,
     * ou revenir sur la même proposition le lendemain, ne crée pas de doublon.
     * L'invariant est tenu par la base et non par une vérification préalable, qui
     * laisserait passer deux téléphones appuyant au même instant.
     */
    #[ORM\Column(length: 64)]
    private string $fingerprint;

    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')]
    private array $recipe;

    /**
     * La forme de `RecipeNormalizer::normalize()` au moment de l'enregistrement.
     *
     * Le JSON n'est jamais migré rétroactivement (voir la classe) : ce numéro dit
     * simplement à quelle forme il faut s'attendre en le relisant, pour qu'un futur
     * changement de forme sache quelles recettes il concerne.
     */
    #[ORM\Column(type: 'smallint')]
    private int $schemaVersion;

    /** Qui l'a enregistrée. Le membre peut partir, la recette reste au foyer. */
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $savedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, mixed> $recipe */
    public function __construct(
        Household $household,
        string $name,
        array $recipe,
        string $fingerprint,
        int $schemaVersion,
        ?Member $savedBy = null,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->name = mb_substr($name, 0, 200);
        $this->recipe = $recipe;
        $this->fingerprint = $fingerprint;
        $this->schemaVersion = $schemaVersion;
        $this->savedBy = $savedBy;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @return array<string, mixed> */
    public function getRecipe(): array
    {
        return $this->recipe;
    }

    public function getSchemaVersion(): int
    {
        return $this->schemaVersion;
    }

    public function getSavedBy(): ?Member
    {
        return $this->savedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function belongsTo(Household $household): bool
    {
        return $this->household->getId()->equals($household->getId());
    }
}
