<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HouseholdRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: HouseholdRepository::class)]
#[ORM\Table(name: 'household')]
class Household
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'household')]
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, User> */
    public function getMembers(): Collection
    {
        return $this->members;
    }
}
