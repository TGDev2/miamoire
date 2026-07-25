<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\StockItemRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Un lot réellement présent chez l'utilisateur.
 *
 * Deux bouteilles de lait achetées le même jour mais périmant à deux dates
 * différentes sont deux StockItem distincts : c'est ce qui rend possible
 * la consommation FEFO (le lot qui expire le plus tôt part en premier).
 */
#[ORM\Entity(repositoryClass: StockItemRepository::class)]
#[ORM\Table(name: 'stock_item')]
#[ORM\Index(columns: ['household_id', 'expiration_date'], name: 'idx_stock_household_expiration')]
#[ORM\HasLifecycleCallbacks]
class StockItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Product $product;

    /** Quantité totale au moment de l'ajout (nbExemplaires x contenance). */
    #[ORM\Column(type: 'float')]
    private float $initialQuantity;

    #[ORM\Column(type: 'float')]
    private float $remainingQuantity;

    #[ORM\Column(type: 'string', length: 16, enumType: Unit::class)]
    private Unit $unit;

    #[ORM\Column(type: 'integer')]
    private int $numberOfPackages = 1;

    #[ORM\Column(type: 'string', length: 32, enumType: StorageLocation::class)]
    private StorageLocation $storageLocation;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $purchaseDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expirationDate = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    /** Renseigné quand l'utilisateur a déclaré une quantité approximative plutôt qu'exacte. */
    #[ORM\Column(type: 'boolean')]
    private bool $quantityIsEstimated = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $note = null;

    /** Qui a enregistré ce produit. Dénormalisé ici pour éviter une requête par ligne d'inventaire. */
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $addedBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, StockMovement> */
    #[ORM\OneToMany(targetEntity: StockMovement::class, mappedBy: 'stockItem', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $movements;

    public function __construct(
        Household $household,
        Product $product,
        float $quantity,
        Unit $unit,
        StorageLocation $storageLocation,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->product = $product;
        $this->initialQuantity = $quantity;
        $this->remainingQuantity = $quantity;
        $this->unit = $unit;
        $this->storageLocation = $storageLocation;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->movements = new ArrayCollection();
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

    public function getProduct(): Product
    {
        return $this->product;
    }

    public function setProduct(Product $product): void
    {
        $this->product = $product;
    }

    public function getInitialQuantity(): float
    {
        return $this->initialQuantity;
    }

    public function setInitialQuantity(float $initialQuantity): void
    {
        $this->initialQuantity = $initialQuantity;
    }

    public function getRemainingQuantity(): float
    {
        return $this->remainingQuantity;
    }

    public function setRemainingQuantity(float $remainingQuantity): void
    {
        $this->remainingQuantity = max(0.0, round($remainingQuantity, 3));
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function setUnit(Unit $unit): void
    {
        $this->unit = $unit;
    }

    public function getNumberOfPackages(): int
    {
        return $this->numberOfPackages;
    }

    public function setNumberOfPackages(int $numberOfPackages): void
    {
        $this->numberOfPackages = max(1, $numberOfPackages);
    }

    public function getStorageLocation(): StorageLocation
    {
        return $this->storageLocation;
    }

    public function setStorageLocation(StorageLocation $storageLocation): void
    {
        $this->storageLocation = $storageLocation;
    }

    public function getPurchaseDate(): ?\DateTimeImmutable
    {
        return $this->purchaseDate;
    }

    public function setPurchaseDate(?\DateTimeImmutable $purchaseDate): void
    {
        $this->purchaseDate = $purchaseDate;
    }

    public function getExpirationDate(): ?\DateTimeImmutable
    {
        return $this->expirationDate;
    }

    public function setExpirationDate(?\DateTimeImmutable $expirationDate): void
    {
        $this->expirationDate = $expirationDate;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(?\DateTimeImmutable $openedAt): void
    {
        $this->openedAt = $openedAt;
    }

    public function isOpened(): bool
    {
        return null !== $this->openedAt;
    }

    public function isQuantityEstimated(): bool
    {
        return $this->quantityIsEstimated;
    }

    public function setQuantityIsEstimated(bool $estimated): void
    {
        $this->quantityIsEstimated = $estimated;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getAddedBy(): ?Member
    {
        return $this->addedBy;
    }

    public function setAddedBy(?Member $addedBy): void
    {
        $this->addedBy = $addedBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, StockMovement> */
    public function getMovements(): Collection
    {
        return $this->movements;
    }

    public function addMovement(StockMovement $movement): void
    {
        if (!$this->movements->contains($movement)) {
            $this->movements->add($movement);
        }
    }

    public function isEmpty(): bool
    {
        return $this->remainingQuantity <= 0.0001;
    }

    /** Bientôt terminé : il reste moins de 20 % du lot mais il n'est pas vide. */
    public function isRunningLow(): bool
    {
        if ($this->isEmpty() || $this->initialQuantity <= 0) {
            return false;
        }

        return ($this->remainingQuantity / $this->initialQuantity) < 0.2;
    }

    public function getDaysUntilExpiration(): ?int
    {
        if (null === $this->expirationDate) {
            return null;
        }

        $today = new \DateTimeImmutable('today');

        return (int) $today->diff($this->expirationDate)->format('%r%a');
    }

    public function isExpiringSoon(int $withinDays = 3): bool
    {
        $days = $this->getDaysUntilExpiration();

        return null !== $days && $days <= $withinDays;
    }

    public function isExpired(): bool
    {
        $days = $this->getDaysUntilExpiration();

        return null !== $days && $days < 0;
    }

    /** État synthétique affiché dans la liste d'inventaire. */
    public function getStatus(): string
    {
        return match (true) {
            $this->isEmpty() => 'empty',
            $this->isExpired() => 'expired',
            $this->isExpiringSoon() => 'expiring_soon',
            $this->isRunningLow() => 'running_low',
            $this->isOpened() => 'opened',
            default => 'sealed',
        };
    }
}
