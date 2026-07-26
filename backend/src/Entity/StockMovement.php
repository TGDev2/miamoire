<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MovementType;
use App\Enum\Unit;
use App\Repository\StockMovementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Journal des variations de stock.
 *
 * On ne remplace jamais silencieusement « 1 000 g » par « 700 g » : on enregistre
 * que 300 g ont été consommés. Cela rend l'historique auditable et les corrections
 * possibles.
 *
 * `quantity` est un delta SIGNÉ appliqué à la quantité restante :
 * négatif pour une consommation ou une suppression, positif pour un ajout,
 * de l'un ou l'autre signe pour une correction.
 */
#[ORM\Entity(repositoryClass: StockMovementRepository::class)]
#[ORM\Table(name: 'stock_movement')]
#[ORM\Index(columns: ['stock_item_id', 'created_at'], name: 'idx_movement_item_date')]
class StockMovement
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: StockItem::class, inversedBy: 'movements')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private StockItem $stockItem;

    #[ORM\Column(type: 'string', length: 32, enumType: MovementType::class)]
    private MovementType $type;

    #[ORM\Column(type: 'float')]
    private float $quantity;

    #[ORM\Column(type: 'string', length: 16, enumType: Unit::class)]
    private Unit $unit;

    /** Quantité restante après application du mouvement — évite de rejouer tout l'historique. */
    #[ORM\Column(type: 'float')]
    private float $remainingAfter;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $reason = null;

    /**
     * L'opération qui a produit ce mouvement.
     *
     * Une recette confirmée déduit plusieurs lots ; sans ce lien, rien ne disait
     * que ces mouvements appartenaient au même geste — ni comment les annuler
     * ensemble. `SET NULL` : purger les vieilles opérations ne doit pas amputer
     * le journal.
     */
    #[ORM\ManyToOne(targetEntity: StockOperation::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?StockOperation $operation = null;

    /** Qui a fait le geste. Nullable : un membre supprimé ne doit pas effacer l'historique. */
    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        StockItem $stockItem,
        MovementType $type,
        float $quantity,
        Unit $unit,
        float $remainingAfter,
        ?string $reason = null,
        ?Member $createdBy = null,
        ?StockOperation $operation = null,
    ) {
        $this->id = Uuid::v7();
        $this->stockItem = $stockItem;
        $this->type = $type;
        $this->quantity = round($quantity, 3);
        $this->unit = $unit;
        $this->remainingAfter = round($remainingAfter, 3);
        $this->reason = $reason;
        $this->createdBy = $createdBy;
        $this->operation = $operation;
        $this->createdAt = new \DateTimeImmutable();

        $stockItem->addMovement($this);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStockItem(): StockItem
    {
        return $this->stockItem;
    }

    public function getType(): MovementType
    {
        return $this->type;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getUnit(): Unit
    {
        return $this->unit;
    }

    public function getRemainingAfter(): float
    {
        return $this->remainingAfter;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getCreatedBy(): ?Member
    {
        return $this->createdBy;
    }

    public function getOperation(): ?StockOperation
    {
        return $this->operation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
