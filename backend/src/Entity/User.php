<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'Cette adresse e-mail est déjà utilisée.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\ManyToOne(targetEntity: Household::class, inversedBy: 'members')]
    #[ORM\JoinColumn(nullable: false)]
    private Household $household;

    /** @var list<string> Régimes et exclusions alimentaires réutilisés par le générateur de recettes. */
    #[ORM\Column(type: 'json')]
    private array $dietaryExclusions = [];

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $name, Household $household)
    {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower($email);
        $this->name = $name;
        $this->household = $household;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): void
    {
        $this->password = $password;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function setHousehold(Household $household): void
    {
        $this->household = $household;
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

    public function eraseCredentials(): void
    {
    }
}
