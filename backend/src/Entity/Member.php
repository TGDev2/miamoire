<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MemberRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une personne du foyer, identifiée par son seul prénom.
 *
 * Il ne s'agit pas d'un compte : aucun mot de passe, aucune session. Le prénom
 * sert uniquement à savoir qui a enregistré ou consommé un produit dans le
 * journal des mouvements.
 */
#[ORM\Entity(repositoryClass: MemberRepository::class)]
#[ORM\Table(name: 'household_member')]
#[ORM\UniqueConstraint(name: 'uniq_member_household_name', columns: ['household_id', 'first_name'])]
class Member
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\Column(length: 80)]
    private string $firstName;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Household $household, string $firstName)
    {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->firstName = $firstName;
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

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
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
