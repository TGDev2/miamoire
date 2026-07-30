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
 *
 * Le lot pointe vers une variante du foyer, jamais vers le catalogue partagé :
 * corriger un lot ne peut plus changer ce que voient les autres foyers.
 *
 * ## Le lot et ses exemplaires
 *
 * Un lot de trois sachets de pâtes de 500 g n'est pas 1,5 kg de pâtes en vrac.
 * Prélever 250 g y ouvre **un** sachet ; les deux autres restent fermés. Le lot
 * porte donc deux informations de conditionnement :
 *
 * - `packageSize` — la contenance d'un exemplaire ;
 * - `openPackageQuantity` — ce qu'il reste dans celui qui est entamé, `null`
 *   quand aucun ne l'est.
 *
 * Tout le reste s'en déduit (voir {@see getPackages()}), parce que les
 * exemplaires d'un lot sont homogènes par construction : même variante, même
 * date de péremption, même contenance, même achat. Un seul exemplaire peut être
 * entamé à la fois — ce n'est pas une approximation du modèle mais la règle
 * anti-gaspillage que l'application défend : on finit le paquet ouvert avant
 * d'en ouvrir un autre.
 */
#[ORM\Entity(repositoryClass: StockItemRepository::class)]
#[ORM\Table(name: 'stock_item')]
#[ORM\Index(columns: ['household_id', 'expiration_date'], name: 'idx_stock_household_expiration')]
#[ORM\Index(columns: ['variant_id', 'expiration_date'], name: 'idx_stock_variant_expiration')]
#[ORM\HasLifecycleCallbacks]
class StockItem
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Household::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Household $household;

    #[ORM\ManyToOne(targetEntity: HouseholdProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', nullable: false)]
    private HouseholdProductVariant $variant;

    /** Quantité totale au moment de l'ajout (nbExemplaires x contenance). */
    #[ORM\Column(type: 'float')]
    private float $initialQuantity;

    #[ORM\Column(type: 'float')]
    private float $remainingQuantity;

    #[ORM\Column(type: 'string', length: 16, enumType: Unit::class)]
    private Unit $unit;

    #[ORM\Column(type: 'integer')]
    private int $numberOfPackages = 1;

    /**
     * Contenance d'un exemplaire — un sachet, une bouteille, une boîte.
     *
     * Elle était jusqu'ici recalculée à la volée par `initialQuantity /
     * numberOfPackages`, donc fausse dès la première correction de quantité :
     * « retirer un exemplaire » d'un lot corrigé retirait n'importe quoi.
     */
    #[ORM\Column(type: 'float')]
    private float $packageSize;

    /**
     * Ce qu'il reste dans l'exemplaire entamé ; `null` quand aucun ne l'est.
     *
     * C'est cette colonne, et non `openedAt`, qui dit si le lot est ouvert :
     * un lot peut très bien être « entamé » avec deux sachets encore scellés.
     */
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $openPackageQuantity = null;

    #[ORM\Column(type: 'string', length: 32, enumType: StorageLocation::class)]
    private StorageLocation $storageLocation;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $purchaseDate = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $expirationDate = null;

    /**
     * La date a été déduite d'une durée de conservation, pas lue sur un emballage.
     *
     * Les fruits et légumes frais, le vrac, un reste de plat n'ont aucune date
     * imprimée. Leur en inventer une sans le dire ferait passer une estimation
     * pour une certitude — et une salade « périmée » depuis un jour serait jetée
     * alors qu'elle est parfaitement bonne. On estime, et on l'écrit.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $expirationIsEstimated = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $openedAt = null;

    /**
     * Vrai seulement quand l'exemplaire entamé a été explicitement refermé sans
     * être fini — jamais vrai quand `openPackageQuantity` est `null`.
     *
     * « Refermer » est un geste physique (on remet le couvercle), pas une
     * correction : il ne doit ni effacer `openPackageQuantity` ni `openedAt`.
     * Sans ce champ, un exemplaire refermé redevenait indiscernable d'un
     * exemplaire jamais ouvert — {@see resealOpenPackage()}.
     */
    #[ORM\Column(type: 'boolean')]
    private bool $packageResealed = false;

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

    /**
     * Écarté de l'inventaire, mais toujours là.
     *
     * Un `DELETE` physique effaçait le lot ET ses mouvements en cascade : le
     * journal disparaissait précisément dans le cas qu'il devait auditer — celui
     * où quelqu'un supprime une ligne. Archiver conserve l'historique et rend le
     * geste réversible.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    /**
     * Reporté depuis « Aujourd'hui » : ce lot ne doit pas réapparaître dans la
     * sélection avant cette date. `null` tant que personne ne l'a reporté.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $remindAfter = null;

    /**
     * Date à laquelle le lot est passé au congélateur. `null` tant qu'il n'a
     * jamais été congelé — voir {@see \App\Service\Stock\StockService::freeze()}.
     */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $frozenAt = null;

    /** @var Collection<int, StockMovement> */
    #[ORM\OneToMany(targetEntity: StockMovement::class, mappedBy: 'stockItem', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    private Collection $movements;

    /**
     * Incrémentée par Doctrine à chaque écriture ; c'est elle que le mobile
     * renvoie en `expectedRevision` pour une correction absolue. Une correction
     * fondée sur une lecture périmée est ainsi refusée avec un 409 plutôt que
     * d'écraser silencieusement un changement qu'elle n'a jamais vu.
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    private int $revision = 1;

    public function __construct(
        Household $household,
        HouseholdProductVariant $variant,
        float $quantity,
        Unit $unit,
        StorageLocation $storageLocation,
    ) {
        $this->id = Uuid::v7();
        $this->household = $household;
        $this->variant = $variant;
        $this->initialQuantity = $quantity;
        $this->remainingQuantity = $quantity;
        // Un lot d'un seul exemplaire tant que l'appelant n'en décrit pas d'autres.
        $this->packageSize = $quantity;
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

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getHousehold(): Household
    {
        return $this->household;
    }

    public function getVariant(): HouseholdProductVariant
    {
        return $this->variant;
    }

    /**
     * Réaffecte le lot à une autre variante — après un renommage, ou après une
     * correction d'unité qui change de famille.
     */
    public function setVariant(HouseholdProductVariant $variant): void
    {
        $this->variant = $variant;
    }

    /** La fiche canonique, quand elle existe : image, code-barres, conditionnement. */
    public function getProduct(): ?Product
    {
        return $this->variant->getProduct();
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

    /**
     * Fixe la quantité restante du lot entier.
     *
     * Point de passage unique de toutes les corrections, elle porte donc
     * l'invariant du conditionnement : un exemplaire entamé ne peut pas contenir
     * plus que le lot dont il fait partie. Sans ce plafonnement, corriger un lot
     * de 1,5 kg à 100 g laisserait un sachet ouvert prétendant en contenir 250.
     */
    public function setRemainingQuantity(float $remainingQuantity): void
    {
        $this->remainingQuantity = max(0.0, round($remainingQuantity, 3));

        if (null === $this->openPackageQuantity) {
            return;
        }

        $this->openPackageQuantity = round(min($this->openPackageQuantity, $this->remainingQuantity), 3);

        if ($this->openPackageQuantity <= 0.0001) {
            $this->closeOpenPackage();
        }
    }

    /**
     * Retire une quantité en suivant les exemplaires un par un.
     *
     * Seule porte par laquelle une quantité quitte un lot, et c'est ce qui rend
     * la règle d'ouverture automatique impossible à contourner : prélever sur un
     * lot dont aucun exemplaire n'est entamé en ouvre un. Le défaut corrigé était
     * exactement là — la quantité partait, le produit restait « fermé ».
     *
     * Quand l'exemplaire entamé est épuisé, le suivant s'ouvre : retirer 700 g
     * d'un lot de trois sachets de 500 g en vide un et en entame un second, ce
     * qu'un simple total n'aurait pas su représenter.
     *
     * @return float la quantité réellement retirée, bornée par ce qui restait
     */
    public function withdraw(float $quantity): float
    {
        $applied = round(min($quantity, $this->remainingQuantity), 3);

        if ($applied <= 0.0001) {
            return 0.0;
        }

        $left = $applied;

        while ($left > 0.0001) {
            if (null === $this->openPackageQuantity) {
                $this->openNextPackage();
            }

            $taken = min($left, $this->openPackageQuantity);

            $this->openPackageQuantity = round($this->openPackageQuantity - $taken, 3);
            $this->remainingQuantity = max(0.0, round($this->remainingQuantity - $taken, 3));
            $left = round($left - $taken, 3);

            // Exemplaire terminé : plus rien n'est ouvert tant qu'on n'y touche pas.
            if ($this->openPackageQuantity <= 0.0001) {
                $this->closeOpenPackage();
            }
        }

        return $applied;
    }

    /**
     * Ré-exprime le lot dans une autre unité de la même famille.
     *
     * Un facteur unique appliqué à tout ce que le lot compte : le total, la
     * contenance d'un exemplaire et l'exemplaire entamé. Convertir le total seul
     * laissait la contenance en grammes sur un lot passé en kilos — un sachet
     * de « 500 kg », et un décompte d'exemplaires devenu absurde.
     */
    public function rescale(float $factor): void
    {
        $this->initialQuantity = round($this->initialQuantity * $factor, 3);
        $this->packageSize = round($this->packageSize * $factor, 3);

        if (null !== $this->openPackageQuantity) {
            $this->openPackageQuantity = round($this->openPackageQuantity * $factor, 3);
        }

        $this->setRemainingQuantity($this->remainingQuantity * $factor);
    }

    /**
     * Redécrit le conditionnement quand plus rien de l'ancien n'a de sens.
     *
     * Le cas est celui d'une unité corrigée entre deux familles : aucune
     * conversion n'existe, la quantité est redonnée à la main — la contenance
     * d'un exemplaire ne survit pas davantage. Le lot redevient un exemplaire
     * unique, entamé s'il l'était.
     */
    public function repackageAsSingle(float $quantity): void
    {
        $wasOpened = $this->isOpened();

        $this->closeOpenPackage();
        $this->numberOfPackages = 1;
        $this->initialQuantity = round($quantity, 3);
        $this->packageSize = $this->initialQuantity;
        $this->setRemainingQuantity($quantity);

        if ($wasOpened) {
            $this->openNextPackage();
        }
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

    public function getPackageSize(): float
    {
        return $this->packageSize;
    }

    /** Une contenance nulle rendrait le décompte des exemplaires insoluble. */
    public function setPackageSize(float $packageSize): void
    {
        $this->packageSize = $packageSize > 0 ? round($packageSize, 3) : $this->remainingQuantity;
    }

    public function getOpenPackageQuantity(): ?float
    {
        return $this->openPackageQuantity;
    }

    /**
     * Les exemplaires du lot, dans l'ordre où ils seront consommés.
     *
     * Rien n'est stocké ligne à ligne : les exemplaires d'un lot sont homogènes,
     * donc entièrement décrits par le total, la contenance et ce qu'il reste dans
     * celui qui est entamé. Le dernier exemplaire peut être partiel sans être
     * ouvert — un réapprovisionnement de 300 g sur des sachets de 500 g.
     *
     * @return list<array{position: int, remaining: float, opened: bool}>
     */
    public function getPackages(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $packages = [];
        $sealedQuantity = round($this->remainingQuantity - ($this->openPackageQuantity ?? 0.0), 3);

        if (null !== $this->openPackageQuantity) {
            $packages[] = ['remaining' => $this->openPackageQuantity, 'opened' => true];
        }

        $size = $this->packageSize > 0 ? $this->packageSize : $this->remainingQuantity;

        while ($sealedQuantity > 0.0001) {
            $take = min($size, $sealedQuantity);
            $packages[] = ['remaining' => round($take, 3), 'opened' => false];
            $sealedQuantity = round($sealedQuantity - $take, 3);
        }

        return array_values(array_map(
            static fn (int $position, array $package): array => ['position' => $position + 1] + $package,
            array_keys($packages),
            $packages,
        ));
    }

    /** Combien d'exemplaires restent scellés — deux sachets sur trois. */
    public function getSealedPackageCount(): int
    {
        return count(array_filter($this->getPackages(), static fn (array $package) => !$package['opened']));
    }

    /**
     * Entame un exemplaire : celui en cours s'il y en a un (y compris refermé),
     * sinon le suivant.
     *
     * Geste manuel (« marquer comme entamé ») autant qu'effet du retrait d'une
     * quantité. Un exemplaire refermé n'est pas rouvert de zéro : sa quantité et
     * sa date de première ouverture (`openedAt`) sont conservées, seul l'état
     * « refermé » est levé — d'où l'idempotence : entamer deux fois n'ouvre
     * qu'un exemplaire et ne réinitialise jamais sa date d'ouverture.
     */
    public function openNextPackage(): void
    {
        if ($this->isEmpty()) {
            return;
        }

        if (null !== $this->openPackageQuantity) {
            $this->packageResealed = false;

            return;
        }

        $size = $this->packageSize > 0 ? $this->packageSize : $this->remainingQuantity;

        $this->openPackageQuantity = round(min($size, $this->remainingQuantity), 3);
        $this->openedAt = new \DateTimeImmutable();
        $this->packageResealed = false;
    }

    /**
     * Referme l'exemplaire entamé sans effacer son histoire : c'est un geste
     * physique (on remet le couvercle), pas une correction. `openPackageQuantity`
     * et `openedAt` survivent — l'exemplaire reste le plus dégradé du lot et
     * garde sa priorité FEFO. N'a d'effet que s'il y a un exemplaire entamé.
     */
    public function resealOpenPackage(): void
    {
        if (null === $this->openPackageQuantity) {
            return;
        }

        $this->packageResealed = true;
    }

    /**
     * Remise à zéro complète : plus aucun exemplaire entamé, comme s'il n'avait
     * jamais existé. Réservée aux cas où l'exemplaire cesse réellement d'exister
     * — épuisement (voir {@see withdraw()}, {@see setRemainingQuantity()}) ou
     * redéfinition du conditionnement ({@see repackageAsSingle()}) — et à la
     * correction explicite d'une erreur de saisie côté service. Ce n'est PAS le
     * geste « refermer », qui doit garder la trace : voir {@see resealOpenPackage()}.
     */
    public function closeOpenPackage(): void
    {
        $this->openPackageQuantity = null;
        $this->openedAt = null;
        $this->packageResealed = false;
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

    /**
     * Une date lue sur l'emballage, ou saisie à la main : une certitude.
     *
     * Elle lève donc toujours le marqueur d'estimation — corriger une date déduite
     * par une date réelle est justement le geste qui doit faire cesser le doute.
     */
    public function setExpirationDate(?\DateTimeImmutable $expirationDate): void
    {
        $this->expirationDate = $expirationDate;
        $this->expirationIsEstimated = false;
    }

    public function isExpirationEstimated(): bool
    {
        return $this->expirationIsEstimated;
    }

    /** Une date lue sur l'emballage prime toujours sur une durée de conservation déduite. */
    public function setEstimatedExpirationDate(?\DateTimeImmutable $expirationDate): void
    {
        $this->expirationDate = $expirationDate;
        $this->expirationIsEstimated = null !== $expirationDate;
    }

    public function getOpenedAt(): ?\DateTimeImmutable
    {
        return $this->openedAt;
    }

    /**
     * Un lot est entamé quand l'un de ses exemplaires l'est — pas quand il a été
     * ouvert un jour. Trois sachets dont le premier a été fini ne sont plus
     * « entamés » : il reste deux paquets scellés.
     */
    public function isOpened(): bool
    {
        return null !== $this->openPackageQuantity;
    }

    /**
     * Entamé, mais refermé sans être fini. Toujours faux tant qu'aucun
     * exemplaire n'est entamé — invariant garanti par {@see closeOpenPackage()}
     * et {@see resealOpenPackage()}, pas revérifié ici par défense inutile.
     */
    public function isPackageResealed(): bool
    {
        return $this->packageResealed;
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

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function archive(): void
    {
        $this->archivedAt ??= new \DateTimeImmutable();
    }

    public function restore(): void
    {
        $this->archivedAt = null;
    }

    public function getRemindAfter(): ?\DateTimeImmutable
    {
        return $this->remindAfter;
    }

    public function setRemindAfter(?\DateTimeImmutable $remindAfter): void
    {
        $this->remindAfter = $remindAfter;
    }

    public function getFrozenAt(): ?\DateTimeImmutable
    {
        return $this->frozenAt;
    }

    public function setFrozenAt(?\DateTimeImmutable $frozenAt): void
    {
        $this->frozenAt = $frozenAt;
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

    /**
     * Jours avant la date imprimée ou estimée — pas l'échéance effective.
     *
     * Un exemplaire entamé peut se dégrader plus vite que cette date ne le dit :
     * c'est {@see \App\Service\Freshness\EffectiveUseByCalculator} qui rend le
     * jugement réellement actionnable (urgence, statut), en tenant compte de la
     * durée de conservation après ouverture. Cette méthode-ci ne renvoie qu'un
     * fait brut, jamais un jugement.
     */
    public function getDaysUntilExpiration(): ?int
    {
        if (null === $this->expirationDate) {
            return null;
        }

        $today = new \DateTimeImmutable('today');

        return (int) $today->diff($this->expirationDate)->format('%r%a');
    }
}
