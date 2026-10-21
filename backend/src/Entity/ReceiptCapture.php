<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ReceiptCaptureRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A receipt the household had read — not the image, which is never kept,
 * but enough of it to recognise the same receipt a second time: the shop,
 * the date, the total, folded into a fingerprint.
 *
 * A receipt photographed twice (two members, a doubt, a retake) would put
 * the whole trip in the stock twice; the only defence that costs nothing is
 * remembering what was already read.
 */
#[ORM\Entity(repositoryClass: ReceiptCaptureRepository::class)]
#[ORM\Table(name: 'receipt_capture')]
#[ORM\Index(columns: ['household_id', 'fingerprint'], name: 'idx_receipt_capture_fingerprint')]
class ReceiptCapture
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\Column(length: 64)]
    private string $fingerprint;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $store;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $receiptDate;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $total;

    #[ORM\Column(type: 'integer')]
    private int $lineCount;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $readAt;

    public function __construct(
        Household $household,
        string $fingerprint,
        ?string $store,
        ?\DateTimeImmutable $receiptDate,
        ?float $total,
        int $lineCount,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->fingerprint = $fingerprint;
        $this->store = null !== $store ? mb_substr($store, 0, 120) : null;
        $this->receiptDate = $receiptDate;
        $this->total = $total;
        $this->lineCount = $lineCount;
        $this->readAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function getStore(): ?string
    {
        return $this->store;
    }

    public function getReceiptDate(): ?\DateTimeImmutable
    {
        return $this->receiptDate;
    }

    public function getTotal(): ?float
    {
        return $this->total;
    }

    public function getLineCount(): int
    {
        return $this->lineCount;
    }

    public function getReadAt(): \DateTimeImmutable
    {
        return $this->readAt;
    }
}
