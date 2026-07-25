<?php

declare(strict_types=1);

namespace App\Entity;

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

    /** @var list<string> Exclusions communes au foyer, appliquées aux recettes générées. */
    #[ORM\Column(type: 'json')]
    private array $dietaryExclusions = [];

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

    /** @return list<string> */
    public function getDietaryExclusions(): array
    {
        return $this->dietaryExclusions;
    }

    /** @param list<string> $exclusions */
    public function setDietaryExclusions(array $exclusions): void
    {
        $this->dietaryExclusions = array_values(array_unique(array_map('trim', $exclusions)));
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
