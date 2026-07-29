<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ExclusionSeverity;
use App\Repository\HouseholdRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un foyer : le périmètre d'isolation du stock.
 *
 * Chaque StockItem appartient à un foyer et un seul. Deux foyers ne partagent
 * jamais ni inventaire, ni mouvements, ni membres — seule la table Product,
 * qui ne contient aucune quantité, leur est commune.
 */
#[ORM\Entity(repositoryClass: HouseholdRepository::class)]
#[ORM\Table(name: 'household')]
class Household
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name;

    /**
     * Empreinte SHA-256 du code d'accès — jamais le code lui-même.
     *
     * Nullable uniquement pour les foyers créés avant l'ouverture de l'API sur
     * Internet. `RequestContext` les refuse tant que `app:households:issue-keys`
     * ne leur a pas délivré de code : une valeur absente ne doit jamais valoir
     * autorisation.
     */
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $accessKeyHash = null;

    /**
     * Exclusions communes au foyer, appliquées aux recettes générées.
     *
     * @var list<array{label: string, severity: string}>
     */
    #[ORM\Column(type: 'json')]
    private array $dietaryExclusions = [];

    /**
     * Catégories définitivement écartées de « Vérifier mon stock ».
     *
     * Le foyer peut juger qu'une catégorie ne mérite jamais cette vérification
     * rapide (le vrac, les surgelés qu'on ne regarde jamais…) : la file ne doit
     * plus jamais y piocher, pas seulement pour la session en cours.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $mutedReviewCategories = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, Member> */
    #[ORM\OneToMany(targetEntity: Member::class, mappedBy: 'household', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['firstName' => 'ASC'])]
    private Collection $members;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->members = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAccessKeyHash(): ?string
    {
        return $this->accessKeyHash;
    }

    public function setAccessKeyHash(string $accessKeyHash): void
    {
        $this->accessKeyHash = $accessKeyHash;
    }

    public function hasAccessKey(): bool
    {
        return null !== $this->accessKeyHash && '' !== $this->accessKeyHash;
    }

    /** @return list<array{label: string, severity: string}> */
    public function getDietaryExclusions(): array
    {
        return $this->dietaryExclusions;
    }

    /**
     * Les labels seuls, pour le contrôle lexical qui ne distingue pas la sévérité.
     *
     * @return list<string>
     */
    public function getDietaryExclusionLabels(): array
    {
        return array_column($this->dietaryExclusions, 'label');
    }

    /**
     * Les exclusions à traiter en allergie stricte — les seules pour lesquelles
     * une composition produit inconnue doit empêcher de présenter une recette
     * comme sûre.
     *
     * @return list<string>
     */
    public function getStrictExclusionLabels(): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry['label'],
            array_filter(
                $this->dietaryExclusions,
                static fn (array $entry): bool => ExclusionSeverity::Allergy->value === $entry['severity'],
            ),
        ));
    }

    /**
     * @param list<array{label: string, severity: string}> $exclusions
     */
    public function setDietaryExclusions(array $exclusions): void
    {
        $seen = [];
        $entries = [];

        foreach ($exclusions as $exclusion) {
            $label = trim((string) ($exclusion['label'] ?? ''));
            if ('' === $label || isset($seen[$label])) {
                continue;
            }

            // Une sévérité absente ou invalide retombe sur le défaut le plus sûr :
            // traiter une exclusion mal qualifiée en allergie stricte plutôt que
            // de la traiter en simple préférence.
            $severity = ExclusionSeverity::tryFrom((string) ($exclusion['severity'] ?? '')) ?? ExclusionSeverity::Allergy;

            $seen[$label] = true;
            $entries[] = ['label' => $label, 'severity' => $severity->value];
        }

        $this->dietaryExclusions = $entries;
    }

    /** @return list<string> */
    public function getMutedReviewCategories(): array
    {
        return $this->mutedReviewCategories;
    }

    /** @param list<string> $categories */
    public function setMutedReviewCategories(array $categories): void
    {
        $seen = [];
        $entries = [];

        foreach ($categories as $category) {
            $trimmed = trim((string) $category);
            if ('' === $trimmed || isset($seen[$trimmed])) {
                continue;
            }

            $seen[$trimmed] = true;
            $entries[] = $trimmed;
        }

        $this->mutedReviewCategories = $entries;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Member> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(Member $member): void
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
        }
    }
}
