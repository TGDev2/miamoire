<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StockOperationStatus;
use App\Enum\StockOperationType;
use App\Repository\StockOperationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Une mutation de stock identifiable, rejouable sans effet supplémentaire.
 *
 * Le mobile confirmait une recette avec son seul nom : une réponse réseau perdue,
 * suivie d'une nouvelle tentative, déduisait deux fois. Rien dans la requête ne
 * permettait au serveur de reconnaître qu'il s'agissait du même geste.
 *
 * L'opération porte donc une clé choisie par le client. La contrainte unique
 * `(foyer, clé)` fait le reste : la seconde tentative n'écrit pas, elle relit la
 * réponse figée de la première. C'est la base — et non une convention applicative —
 * qui garantit qu'un geste unique reste unique.
 */
#[ORM\Entity(repositoryClass: StockOperationRepository::class)]
#[ORM\Table(name: 'stock_operation')]
#[ORM\UniqueConstraint(name: 'uniq_operation_key', columns: ['household_id', 'operation_key'])]
class StockOperation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    /** Clé d'idempotence choisie par le client, unique dans le foyer. */
    #[ORM\Column(length: 64)]
    private string $operationKey;

    #[ORM\Column(type: 'string', length: 32, enumType: StockOperationType::class)]
    private StockOperationType $type;

    /**
     * Empreinte de la requête.
     *
     * Rejouer la même clé avec un contenu différent n'est pas un rejeu, c'est une
     * confusion : on la refuse plutôt que de renvoyer la réponse d'un autre geste.
     */
    #[ORM\Column(length: 64)]
    private string $requestHash;

    #[ORM\Column(type: 'string', length: 16, enumType: StockOperationStatus::class)]
    private StockOperationStatus $status;

    /** La réponse rendue à la première exécution, restituée telle quelle aux suivantes. */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $response = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $failureReason = null;

    #[ORM\ManyToOne(targetEntity: Member::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Member $createdBy = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * Empêche deux relances concurrentes de la même clé périmée de gagner
     * toutes les deux : Doctrine ajoute `WHERE version = :attendue` à l'UPDATE
     * de `retry()` et lève `OptimisticLockException` si une autre l'a emporté
     * entre-temps — sans quoi le stock pouvait être débité deux fois pour une
     * seule opération, exactement ce que la clé d'opération devait empêcher.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $version = 1;

    public function __construct(
        Household $household,
        string $operationKey,
        StockOperationType $type,
        string $requestHash,
        ?Member $createdBy = null,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->operationKey = $operationKey;
        $this->type = $type;
        $this->requestHash = $requestHash;
        $this->createdBy = $createdBy;
        $this->status = StockOperationStatus::Pending;
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

    public function getOperationKey(): string
    {
        return $this->operationKey;
    }

    public function getType(): StockOperationType
    {
        return $this->type;
    }

    public function getRequestHash(): string
    {
        return $this->requestHash;
    }

    public function matches(string $requestHash): bool
    {
        return hash_equals($this->requestHash, $requestHash);
    }

    public function getStatus(): StockOperationStatus
    {
        return $this->status;
    }

    /** @return array<string, mixed>|null */
    public function getResponse(): ?array
    {
        return $this->response;
    }

    /** @param array<string, mixed> $response */
    public function succeed(array $response): void
    {
        $this->status = StockOperationStatus::Succeeded;
        $this->response = $response;
        $this->failureReason = null;
        $this->completedAt = new \DateTimeImmutable();
    }

    /**
     * Un échec est réinscriptible : la même clé peut être retentée, puisque par
     * construction aucune écriture n'a survécu à la transaction annulée.
     */
    public function fail(string $reason): void
    {
        $this->status = StockOperationStatus::Failed;
        $this->failureReason = $reason;
        $this->completedAt = new \DateTimeImmutable();
    }

    /**
     * Remise en attente : une nouvelle tentative sur une opération échouée.
     *
     * Ce n'est pas la seule protection : `$version` est vérifiée par Doctrine
     * au flush qui suit cet appel.
     */
    public function retry(string $requestHash): void
    {
        $this->status = StockOperationStatus::Pending;
        $this->requestHash = $requestHash;
        $this->response = null;
        $this->failureReason = null;
        $this->completedAt = null;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getCreatedBy(): ?Member
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }
}
