<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Unit;
use App\Repository\ShoppingListItemRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une ligne de la liste de courses du foyer — pas de « liste » séparée : un
 * foyer en a une, comme il a un stock.
 *
 * `variant` est résolue dès l'ajout via {@see \App\Service\Product\VariantResolver},
 * avant tout achat : c'est cette même variante que le scan retrouvera au
 * retour de courses (voir `HouseholdProductVariantRepository::findOneByProduct`),
 * ce qui permet le rapprochement sans comparaison de texte.
 */
#[ORM\Entity(repositoryClass: ShoppingListItemRepository::class)]
#[ORM\Table(name: 'shopping_list_item')]
#[ORM\Index(columns: ['household_id', 'checked'], name: 'idx_shopping_list_household_checked')]
class ShoppingListItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\ManyToOne(targetEntity: HouseholdProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HouseholdProductVariant $variant = null;

    #[ORM\Column(length: 255)]
    private string $label;

    /** Un rappel nu — « acheter du pain » — reste valide sans quantité précise. */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $quantity = null;

    /** Jamais nulle : nécessaire à l'identité de la variante résolue. */
    #[ORM\Column(type: 'string', length: 16, enumType: Unit::class)]
    private Unit $unit;

    #[ORM\Column(type: 'boolean')]
    private bool $checked = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $addedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Household $household,
        string $label,
        Unit $unit,
        ?HouseholdProductVariant $variant,
        ?float $quantity,
        ?Member $addedBy,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->label = $label;
        $this->unit = $unit;
        $this->variant = $variant;
        $this->quantity = $quantity;
        $this->addedBy = $addedBy;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
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

    public function getVariant(): ?HouseholdProductVariant
    {
        return $this->variant;
    }

    /** After two variants of the same food are merged, see VariantResolver::changeCut(). */
    public function setVariant(HouseholdProductVariant $variant): void
    {
        $this->variant = $variant;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function setQuantity(?float $quantity): void
    {
        $this->quantity = $quantity;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    public function setChecked(bool $checked): void
    {
        $this->checked = $checked;
        $this->checkedAt = $checked ? new \DateTimeImmutable() : null;
    }

    public function getAddedBy(): ?Member
    {
        return $this->addedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
