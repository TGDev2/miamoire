<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Member;
use App\Entity\StockItem;
use App\Entity\StockMovement;
use App\Entity\StockOperation;
use App\Enum\FillLevel;
use App\Enum\MovementType;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Exception\StockConflictException;
use App\Exception\StockException;
use App\Service\Product\VariantResolver;
use App\Service\UnitConverter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'entrée unique pour toute variation de stock.
 *
 * Règle invariante : aucune quantité ne change sans qu'un StockMovement soit écrit.
 *
 * Seconde règle, ajoutée avec les opérations idempotentes : **un service bas niveau
 * ne décide plus seul de la frontière transactionnelle.** Chaque méthode écrivait
 * son propre `flush()`, ce qui rendait impossible d'appliquer une recette entière
 * en un seul geste atomique — une déduction pouvait rester écrite alors que la
 * suivante échouait. `transactional()` suspend ces `flush()` intermédiaires et
 * n'en fait plus qu'un, à la fin, dans une transaction.
 */
class StockService
{
    /**
     * Profondeur d'imbrication des appels à `transactional()`.
     * Tant qu'elle est non nulle, les écritures attendent la fin de l'opération.
     */
    private int $deferred = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UnitConverter $converter,
        private readonly ?VariantResolver $variants = null,
    ) {
    }

    /**
     * Exécute un ensemble de mutations comme une seule opération.
     *
     * @template T
     *
     * @param callable():T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed
    {
        ++$this->deferred;

        try {
            return $this->entityManager->wrapInTransaction($work);
        } finally {
            --$this->deferred;
        }
    }

    public function add(
        Household $household,
        Member $author,
        HouseholdProductVariant $variant,
        int $numberOfPackages,
        float $quantityPerPackage,
        Unit $unit,
        StorageLocation $location,
        ?\DateTimeImmutable $purchaseDate = null,
        ?\DateTimeImmutable $expirationDate = null,
        ?string $note = null,
        bool $quantityIsEstimated = false,
        bool $expirationIsEstimated = false,
    ): StockItem {
        if ($numberOfPackages < 1) {
            throw new StockException('Le nombre d\'exemplaires doit être au moins égal à 1.');
        }
        if ($quantityPerPackage <= 0) {
            throw new StockException('La quantité par exemplaire doit être strictement positive.');
        }
        if ($variant->getUnitFamily() !== $unit->family()) {
            throw new StockException(sprintf(
                '« %s » se compte en %s dans votre inventaire : un lot en %s formerait une autre ligne.',
                $variant->getName(),
                mb_strtolower($variant->getUnitFamily()->label()),
                $unit->label(),
            ));
        }

        $total = round($numberOfPackages * $quantityPerPackage, 3);

        $item = new StockItem($household, $variant, $total, $unit, $location);
        $item->setNumberOfPackages($numberOfPackages);
        // La contenance d'un exemplaire est déclarée, pas recalculée : c'est elle
        // qui permet plus tard de dire quel sachet est entamé et lesquels restent
        // scellés, y compris après une correction de quantité.
        $item->setPackageSize($quantityPerPackage);
        $item->setPurchaseDate($purchaseDate ?? new \DateTimeImmutable('today'));
        $item->setNote($note);
        $item->setQuantityIsEstimated($quantityIsEstimated);
        $item->setAddedBy($author);

        // Une date lue sur l'emballage n'est jamais marquée comme estimée.
        if ($expirationIsEstimated) {
            $item->setEstimatedExpirationDate($expirationDate);
        } else {
            $item->setExpirationDate($expirationDate);
        }

        $this->entityManager->persist($item);
        $this->record($item, MovementType::Addition, $total, $author, 'Ajout au stock');
        $this->flush();

        return $item;
    }

    /**
     * Retire une quantité. La demande est bornée par ce qui reste réellement :
     * le mouvement journalisé porte le delta effectivement appliqué, jamais celui demandé.
     *
     * Le retrait passe par les exemplaires : prélever sur un lot dont aucun n'est
     * entamé en ouvre un. Le stock ne peut donc plus baisser sur un produit qui
     * resterait affiché « fermé ».
     */
    public function consume(
        StockItem $item,
        float $quantity,
        Unit $unit,
        Member $author,
        ?string $reason = null,
        ?StockOperation $operation = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new StockException('La quantité consommée doit être strictement positive.');
        }

        $inItemUnit = $this->converter->convert($quantity, $unit, $item->getUnit());
        $applied = $item->withdraw($inItemUnit);

        if ($applied <= 0.0001) {
            // Constaté sous verrou, pas une saisie invalide : quelqu'un d'autre
            // a pu tout consommer entre la lecture et l'écriture. `StockConflictException`
            // (et non `StockException`) le signale à `StockOperationGuard`, qui peut
            // alors enregistrer l'échec proprement sans rien avoir muté avant ce point.
            throw new StockConflictException('Ce produit est déjà épuisé.');
        }

        $movement = $this->record($item, MovementType::Consumption, -$applied, $author, $reason ?? 'Consommation', $operation);
        $this->flush();

        return $movement;
    }

    public function consumeAll(StockItem $item, Member $author, ?string $reason = null, ?StockOperation $operation = null): StockMovement
    {
        return $this->consume($item, $item->getRemainingQuantity(), $item->getUnit(), $author, $reason ?? 'Tout consommé', $operation);
    }

    /**
     * Retire un exemplaire entier (une bouteille, un paquet).
     *
     * S'il y en a un d'entamé, c'est celui-là : finir le paquet ouvert avant d'en
     * attaquer un autre est la règle même que le suivi des exemplaires défend.
     * La contenance vient désormais du lot et non d'un `initialQuantity /
     * numberOfPackages` qui devenait faux dès la première correction.
     */
    public function consumeOnePackage(StockItem $item, Member $author, ?StockOperation $operation = null): StockMovement
    {
        $size = $item->getOpenPackageQuantity() ?? $item->getPackageSize();

        return $this->consume(
            $item,
            min($size, $item->getRemainingQuantity()),
            $item->getUnit(),
            $author,
            'Exemplaire retiré',
            $operation,
        );
    }

    /** Boutons -1 / +1 des produits comptés à l'unité (œufs, yaourts). */
    public function adjustDiscreteUnits(StockItem $item, int $delta, Member $author, ?StockOperation $operation = null): StockMovement
    {
        if (0 === $delta) {
            throw new StockException('L\'ajustement doit être différent de zéro.');
        }

        return $delta < 0
            ? $this->consume($item, abs($delta), $item->getUnit(), $author, 'Ajustement à l\'unité', $operation)
            : $this->addQuantity($item, $delta, $item->getUnit(), $author, 'Ajustement à l\'unité', $operation);
    }

    /**
     * Réapprovisionnement d'un lot existant (bouton « Ajouter » de la fiche).
     *
     * `purchaseDate`/`expirationDate` sont optionnels : sans eux, l'appelant
     * affirme implicitement qu'il s'agit du même achat que celui déjà décrit
     * par ce lot — le cas majoritaire (« correction de quantité »). Fournis et
     * différents de ce que porte déjà le lot, ils sont refusés : un lot est
     * homogène par construction, et une nouvelle date de péremption ne peut
     * pas venir d'un même achat.
     */
    public function addQuantity(
        StockItem $item,
        float $quantity,
        Unit $unit,
        Member $author,
        ?string $reason = null,
        ?StockOperation $operation = null,
        ?\DateTimeImmutable $purchaseDate = null,
        ?\DateTimeImmutable $expirationDate = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new StockException('La quantité ajoutée doit être strictement positive.');
        }

        $this->assertSameBatch($item, $purchaseDate, $expirationDate);

        $inItemUnit = $this->converter->convert($quantity, $unit, $item->getUnit());

        $item->setRemainingQuantity($item->getRemainingQuantity() + $inItemUnit);
        $item->setInitialQuantity(max($item->getInitialQuantity(), $item->getRemainingQuantity()));

        $movement = $this->record($item, MovementType::Addition, $inItemUnit, $author, $reason ?? 'Réapprovisionnement', $operation);
        $this->flush();

        return $movement;
    }

    /**
     * Fixe une nouvelle quantité restante. Le journal conserve l'écart appliqué,
     * ce qui permet plus tard de repérer une saisie erronée.
     */
    public function correct(
        StockItem $item,
        float $newQuantity,
        Unit $unit,
        Member $author,
        ?string $reason = null,
        ?StockOperation $operation = null,
    ): StockMovement {
        if ($newQuantity < 0) {
            throw new StockException('La quantité corrigée ne peut pas être négative.');
        }

        $inItemUnit = $this->converter->convert($newQuantity, $unit, $item->getUnit());
        $delta = $inItemUnit - $item->getRemainingQuantity();

        $item->setRemainingQuantity($inItemUnit);
        $item->setInitialQuantity(max($item->getInitialQuantity(), $inItemUnit));
        $item->setQuantityIsEstimated(false);

        $movement = $this->record($item, MovementType::Correction, $delta, $author, $reason ?? 'Correction manuelle', $operation);
        $this->flush();

        return $movement;
    }

    /**
     * Répare une unité mal saisie — « g » choisi au lieu de « unité » à l'ajout.
     *
     * Deux situations distinctes :
     *
     * - même famille (g → kg) : la quantité physique ne change pas, on la convertit ;
     * - familles différentes (g → unité) : aucune conversion n'existe, la nouvelle
     *   quantité devient obligatoire. On refuse plutôt que de deviner.
     *
     * Changer de famille change aussi l'identité culinaire du lot : il rejoint la
     * variante correspondante du foyer, sinon l'inventaire additionnerait des
     * grammes à des pièces. Le catalogue partagé, lui, n'est plus touché — c'était
     * un effet de bord entre foyers.
     *
     * Le mouvement journalisé porte une quantité nulle : un écart entre deux familles
     * n'aurait aucun sens. C'est son motif qui rend le journal lisible.
     */
    public function changeUnit(
        StockItem $item,
        Unit $newUnit,
        ?float $newQuantity,
        Member $author,
        ?StockOperation $operation = null,
    ): ?StockMovement {
        $currentUnit = $item->getUnit();

        if ($currentUnit === $newUnit && null === $newQuantity) {
            return null;
        }

        $sameFamily = $this->converter->canConvert($currentUnit, $newUnit);

        if (!$sameFamily && null === $newQuantity) {
            throw new StockException(sprintf(
                'Passer de « %s » à « %s » ne se convertit pas : indiquez la quantité restante en %s.',
                $currentUnit->label(),
                $newUnit->label(),
                $newUnit->label(),
            ));
        }

        if (null !== $newQuantity && $newQuantity < 0) {
            throw new StockException('La quantité corrigée ne peut pas être négative.');
        }

        $previousLabel = sprintf('%s %s', $this->format($item->getRemainingQuantity()), $currentUnit->label());

        if (null !== $newQuantity) {
            // Quantité redonnée à la main : le conditionnement d'origine ne veut
            // plus rien dire non plus, le lot redevient un exemplaire unique.
            $item->repackageAsSingle($newQuantity);
        } else {
            // Un seul facteur pour tout ce que le lot compte — total, contenance
            // d'un exemplaire et exemplaire entamé.
            $item->rescale($this->converter->convert(1.0, $currentUnit, $newUnit));
        }

        $item->setUnit($newUnit);

        if (!$sameFamily && null !== $this->variants) {
            $item->setVariant($this->variants->forUnitFamily($item->getVariant(), $newUnit->family()));
        }

        $movement = $this->record(
            $item,
            MovementType::Correction,
            0.0,
            $author,
            sprintf(
                'Unité corrigée : %s → %s %s',
                $previousLabel,
                $this->format($item->getRemainingQuantity()),
                $newUnit->label(),
            ),
            $operation,
        );
        $this->flush();

        return $movement;
    }

    /** Correction approximative : « plein », « environ la moitié », « presque vide ». */
    public function applyFillLevel(StockItem $item, FillLevel $level, Member $author, ?StockOperation $operation = null): ?StockMovement
    {
        $ratio = $level->ratio();

        if (null === $ratio) {
            $item->setQuantityIsEstimated(true);
            $this->flush();

            return null;
        }

        $movement = $this->correct(
            $item,
            round($item->getInitialQuantity() * $ratio, 3),
            $item->getUnit(),
            $author,
            sprintf('Estimation : %s', $level->label()),
            $operation,
        );

        $item->setQuantityIsEstimated(true);
        $this->flush();

        return $movement;
    }

    /**
     * Entame ou referme un exemplaire, à la main.
     *
     * Le geste porte sur UN exemplaire, pas sur le lot entier : marquer comme
     * entamé un lot de trois sachets en ouvre un et laisse les deux autres
     * scellés — c'est exactement ce que l'ancien drapeau unique ne savait pas dire.
     *
     * « Refermer » ne fait plus disparaître l'exemplaire entamé : c'est un
     * geste physique (voir {@see StockItem::resealOpenPackage()}), pas une
     * correction. Pour annuler une ouverture par erreur, voir {@see cancelOpening()}.
     */
    public function markOpened(StockItem $item, bool $opened): StockItem
    {
        if ($opened) {
            $item->openNextPackage();
        } else {
            $item->resealOpenPackage();
        }

        $this->flush();

        return $item;
    }

    /**
     * Corrige une erreur de saisie : annule l'ouverture comme si elle n'avait
     * jamais eu lieu. Contrairement à « refermer » (voir {@see markOpened()}),
     * qui garde la trace de l'exemplaire entamé, ce geste l'efface
     * volontairement — d'où sa journalisation explicite plutôt qu'un silence.
     */
    public function cancelOpening(StockItem $item, Member $author, ?StockOperation $operation = null): ?StockMovement
    {
        if (!$item->isOpened()) {
            return null;
        }

        $item->closeOpenPackage();

        $movement = $this->record($item, MovementType::Correction, 0.0, $author, 'Ouverture annulée : erreur de saisie', $operation);
        $this->flush();

        return $movement;
    }

    /** Combien de temps un report écarte un lot de la sélection « Aujourd'hui ». */
    private const SNOOZE_DAYS = 2;

    /**
     * Reporte un lot signalé par « Aujourd'hui » : il ne doit pas réapparaître
     * avant {@see SNOOZE_DAYS} jours. Contrairement à `confirm()`, le champ
     * change réellement : `touch()` se déclenche donc tout seul via le cycle
     * de vie Doctrine, sans appel explicite.
     */
    public function snooze(StockItem $item, Member $author, ?StockOperation $operation = null): StockMovement
    {
        $item->setRemindAfter(new \DateTimeImmutable(sprintf('+%d days', self::SNOOZE_DAYS)));

        $movement = $this->record(
            $item,
            MovementType::Correction,
            0.0,
            $author,
            sprintf('Rappel reporté de %d jours', self::SNOOZE_DAYS),
            $operation,
        );
        $this->flush();

        return $movement;
    }

    /**
     * Confirme qu'un lot proposé par « Vérifier mon stock » est toujours
     * conforme : aucune quantité ne change.
     *
     * Seul appel explicite à `touch()` en dehors du cycle de vie Doctrine :
     * rien d'autre ne varie ici, donc sans cet appel `updatedAt` n'avancerait
     * jamais et le lot réapparaîtrait aussitôt dans la prochaine sélection
     * (voir {@see \App\Service\Inventory\StockReviewSelector}, qui écarte tout
     * lot retouché récemment).
     */
    public function confirm(StockItem $item, Member $author, ?StockOperation $operation = null): StockMovement
    {
        $item->touch();

        $movement = $this->record($item, MovementType::Correction, 0.0, $author, 'Confirmé lors de la vérification du stock', $operation);
        $this->flush();

        return $movement;
    }

    /** Produit jeté : le stock tombe à zéro mais la ligne et son historique subsistent. */
    public function discard(StockItem $item, Member $author, ?string $reason = null, ?StockOperation $operation = null): StockMovement
    {
        if ($item->isEmpty()) {
            // Voir le commentaire équivalent dans consume() : constaté sous
            // verrou, c'est un conflit, pas une saisie invalide.
            throw new StockConflictException('Ce produit est déjà épuisé.');
        }

        $discarded = $item->getRemainingQuantity();
        $item->setRemainingQuantity(0);

        $movement = $this->record($item, MovementType::Deletion, -$discarded, $author, $reason ?? 'Produit jeté', $operation);
        $this->flush();

        return $movement;
    }

    /**
     * Sort le lot de l'inventaire sans effacer son passé — ni son contenu.
     *
     * La suppression physique emportait les mouvements en cascade : le journal
     * disparaissait exactement dans le cas où il aurait servi — « qui a supprimé
     * ce lot, et que restait-il dessus ? ».
     *
     * L'archivage ne touche donc pas à la quantité. Il l'a fait un temps, et
     * c'était contradictoire : le geste s'annonce réversible alors que `restore()`
     * ne rendait qu'un lot vide. Déclarer une perte est un autre geste, `discard()`,
     * et lui met bien à zéro. Le mouvement écrit ici porte un delta nul parce que
     * rien n'est sorti du stock ; c'est `remainingAfter` qui dit ce que le lot
     * contenait au moment où on l'a rangé.
     */
    public function archive(StockItem $item, Member $author, ?string $reason = null, ?StockOperation $operation = null): StockItem
    {
        if ($item->isArchived()) {
            return $item;
        }

        $item->archive();
        $this->record($item, MovementType::Correction, 0.0, $author, $reason ?? 'Lot archivé', $operation);
        $this->flush();

        return $item;
    }

    public function restore(StockItem $item, Member $author, ?StockOperation $operation = null): StockItem
    {
        if (!$item->isArchived()) {
            return $item;
        }

        $item->restore();
        $this->record($item, MovementType::Correction, 0.0, $author, 'Lot restauré depuis les archives', $operation);
        $this->flush();

        return $item;
    }

    /**
     * Refuse un réapprovisionnement dont la date déclarée contredit celle déjà
     * portée par le lot : un lot est homogène par construction (voir la
     * doc de {@see StockItem}), un ajout qui ne l'est pas doit créer un nouveau
     * lot plutôt que mélanger deux achats sous une seule date.
     */
    private function assertSameBatch(
        StockItem $item,
        ?\DateTimeImmutable $purchaseDate,
        ?\DateTimeImmutable $expirationDate,
    ): void {
        if (null !== $purchaseDate && null !== $item->getPurchaseDate()
            && $purchaseDate->format('Y-m-d') !== $item->getPurchaseDate()->format('Y-m-d')) {
            throw new StockException(
                'Cette date d\'achat ne correspond pas à celle de ce lot : ajoutez plutôt une nouvelle course.',
            );
        }

        if (null !== $expirationDate && null !== $item->getExpirationDate()
            && $expirationDate->format('Y-m-d') !== $item->getExpirationDate()->format('Y-m-d')) {
            throw new StockException(
                'Cette date de péremption ne correspond pas à celle de ce lot : ajoutez plutôt une nouvelle course.',
            );
        }
    }

    /** « 1500 » plutôt que « 1500.0 », « 1.5 » plutôt que « 1.500 ». */
    private function format(float $quantity): string
    {
        $rounded = round($quantity, 3);

        return rtrim(rtrim(number_format($rounded, 3, ',', ''), '0'), ',');
    }

    private function record(
        StockItem $item,
        MovementType $type,
        float $signedQuantity,
        Member $author,
        ?string $reason,
        ?StockOperation $operation = null,
    ): StockMovement {
        $movement = new StockMovement(
            $item,
            $type,
            $signedQuantity,
            $item->getUnit(),
            $item->getRemainingQuantity(),
            $reason,
            $author,
            $operation,
        );

        $this->entityManager->persist($movement);

        return $movement;
    }

    /** Écrit — sauf si une opération plus large a pris la main sur la transaction. */
    private function flush(): void
    {
        if (0 === $this->deferred) {
            $this->entityManager->flush();
        }
    }
}
