<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReceiptAliasRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * What a receipt line means in this household: "LAIT 1/2 ECR 6X1L" at this
 * shop is the household's "Lait" — or is not food at all.
 *
 * Learnt only from a human: the pair (shop, printed label) is remembered when
 * the person confirms the line, never from the model's own guess. From the
 * next trip on, the same label resolves alone; the habits of the food it
 * names (package, place) do the rest. An `ignored` alias is a decision too —
 * batteries are not stock — and is kept so the question is not asked twice.
 */
#[ORM\Entity(repositoryClass: ReceiptAliasRepository::class)]
#[ORM\Table(name: 'receipt_alias')]
#[ORM\UniqueConstraint(name: 'uniq_receipt_alias', columns: ['household_id', 'store_key', 'label_key'])]
class ReceiptAlias
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    /** The shop, normalised; empty when the receipt did not name one. */
    #[ORM\Column(length: 120)]
    private string $storeKey;

    /** The printed label, normalised — what is matched. */
    #[ORM\Column(length: 255)]
    private string $labelKey;

    /** The printed label as last seen — what is shown. */
    #[ORM\Column(length: 255)]
    private string $rawLabel;

    #[ORM\ManyToOne(targetEntity: HouseholdProductVariant::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?HouseholdProductVariant $variant = null;

    #[ORM\Column(type: 'boolean')]
    private bool $ignored = false;

    #[ORM\Column(type: 'integer')]
    private int $confirmedCount = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(Household $household, string $storeKey, string $labelKey, string $rawLabel)
    {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->storeKey = $storeKey;
        $this->labelKey = $labelKey;
        $this->rawLabel = $rawLabel;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }

    /** A confirmation replaces what was learnt before: the correction is the new observation. */
    public function learn(?HouseholdProductVariant $variant, bool $ignored, string $rawLabel): void
    {
        $this->variant = $ignored ? null : $variant;
        $this->ignored = $ignored;
        $this->rawLabel = $rawLabel;
        ++$this->confirmedCount;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getStoreKey(): string
    {
        return $this->storeKey;
    }

    public function getLabelKey(): string
    {
        return $this->labelKey;
    }

    public function getRawLabel(): string
    {
        return $this->rawLabel;
    }

    public function getVariant(): ?HouseholdProductVariant
    {
        return $this->variant;
    }

    public function isIgnored(): bool
    {
        return $this->ignored;
    }

    /** Usable when it still names a food, or says to skip the line. */
    public function isUsable(): bool
    {
        return $this->ignored || null !== $this->variant;
    }

    public function getConfirmedCount(): int
    {
        return $this->confirmedCount;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
