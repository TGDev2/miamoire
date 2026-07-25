<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Entity\Household;
use App\Entity\Product;
use App\Entity\StockItem;
use App\Entity\Member;
use App\Entity\StockMovement;
use App\Enum\FillLevel;
use App\Enum\MovementType;
use App\Enum\ProductSource;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Exception\StockException;
use App\Service\UnitConverter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'entrée unique pour toute variation de stock.
 *
 * Règle invariante : aucune quantité ne change sans qu'un StockMovement soit écrit.
 */
class StockService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UnitConverter $converter,
    ) {
    }

    public function add(
        Household $household,
        Member $author,
        Product $product,
        int $numberOfPackages,
        float $quantityPerPackage,
        Unit $unit,
        StorageLocation $location,
        ?\DateTimeImmutable $purchaseDate = null,
        ?\DateTimeImmutable $expirationDate = null,
        ?string $note = null,
        bool $quantityIsEstimated = false,
    ): StockItem {
        if ($numberOfPackages < 1) {
            throw new StockException('Le nombre d\'exemplaires doit être au moins égal à 1.');
        }
        if ($quantityPerPackage <= 0) {
            throw new StockException('La quantité par exemplaire doit être strictement positive.');
        }

        $total = round($numberOfPackages * $quantityPerPackage, 3);

        $item = new StockItem($household, $product, $total, $unit, $location);
        $item->setNumberOfPackages($numberOfPackages);
        $item->setPurchaseDate($purchaseDate ?? new \DateTimeImmutable('today'));
        $item->setExpirationDate($expirationDate);
        $item->setNote($note);
        $item->setQuantityIsEstimated($quantityIsEstimated);
        $item->setAddedBy($author);

        $this->entityManager->persist($item);
        $this->record($item, MovementType::Addition, $total, $author, 'Ajout au stock');
        $this->entityManager->flush();

        return $item;
    }

    /**
     * Retire une quantité. La demande est bornée par ce qui reste réellement :
     * le mouvement journalisé porte le delta effectivement appliqué, jamais celui demandé.
     */
    public function consume(
        StockItem $item,
        float $quantity,
        Unit $unit,
        Member $author,
        ?string $reason = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new StockException('La quantité consommée doit être strictement positive.');
        }

        $inItemUnit = $this->converter->convert($quantity, $unit, $item->getUnit());
        $applied = min($inItemUnit, $item->getRemainingQuantity());

        if ($applied <= 0.0001) {
            throw new StockException('Ce produit est déjà épuisé.');
        }

        $item->setRemainingQuantity($item->getRemainingQuantity() - $applied);

        $movement = $this->record($item, MovementType::Consumption, -$applied, $author, $reason ?? 'Consommation');
        $this->entityManager->flush();

        return $movement;
    }

    public function consumeAll(StockItem $item, Member $author, ?string $reason = null): StockMovement
    {
        return $this->consume($item, $item->getRemainingQuantity(), $item->getUnit(), $author, $reason ?? 'Tout consommé');
    }

    /** Retire un exemplaire entier (une bouteille, un paquet). */
    public function consumeOnePackage(StockItem $item, Member $author): StockMovement
    {
        $packageSize = $item->getNumberOfPackages() > 0
            ? $item->getInitialQuantity() / $item->getNumberOfPackages()
            : $item->getInitialQuantity();

        return $this->consume(
            $item,
            min($packageSize, $item->getRemainingQuantity()),
            $item->getUnit(),
            $author,
            'Exemplaire retiré',
        );
    }

    /** Boutons -1 / +1 des produits comptés à l'unité (œufs, yaourts). */
    public function adjustDiscreteUnits(StockItem $item, int $delta, Member $author): StockMovement
    {
        if (0 === $delta) {
            throw new StockException('L\'ajustement doit être différent de zéro.');
        }

        return $delta < 0
            ? $this->consume($item, abs($delta), $item->getUnit(), $author, 'Ajustement à l\'unité')
            : $this->addQuantity($item, $delta, $item->getUnit(), $author, 'Ajustement à l\'unité');
    }

    /** Réapprovisionnement d'un lot existant (bouton « Ajouter » de la fiche). */
    public function addQuantity(
        StockItem $item,
        float $quantity,
        Unit $unit,
        Member $author,
        ?string $reason = null,
    ): StockMovement {
        if ($quantity <= 0) {
            throw new StockException('La quantité ajoutée doit être strictement positive.');
        }

        $inItemUnit = $this->converter->convert($quantity, $unit, $item->getUnit());

        $item->setRemainingQuantity($item->getRemainingQuantity() + $inItemUnit);
        $item->setInitialQuantity(max($item->getInitialQuantity(), $item->getRemainingQuantity()));

        $movement = $this->record($item, MovementType::Addition, $inItemUnit, $author, $reason ?? 'Réapprovisionnement');
        $this->entityManager->flush();

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
    ): StockMovement {
        if ($newQuantity < 0) {
            throw new StockException('La quantité corrigée ne peut pas être négative.');
        }

        $inItemUnit = $this->converter->convert($newQuantity, $unit, $item->getUnit());
        $delta = $inItemUnit - $item->getRemainingQuantity();

        $item->setRemainingQuantity($inItemUnit);
        $item->setInitialQuantity(max($item->getInitialQuantity(), $inItemUnit));
        $item->setQuantityIsEstimated(false);

        $movement = $this->record($item, MovementType::Correction, $delta, $author, $reason ?? 'Correction manuelle');
        $this->entityManager->flush();

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
     * Le mouvement journalisé porte une quantité nulle : un écart entre deux familles
     * n'aurait aucun sens. C'est son motif qui rend le journal lisible.
     */
    public function changeUnit(
        StockItem $item,
        Unit $newUnit,
        ?float $newQuantity,
        Member $author,
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
            $item->setInitialQuantity($newQuantity);
            $item->setRemainingQuantity($newQuantity);
        } else {
            $item->setInitialQuantity($this->converter->convert($item->getInitialQuantity(), $currentUnit, $newUnit));
            $item->setRemainingQuantity($this->converter->convert($item->getRemainingQuantity(), $currentUnit, $newUnit));
        }

        $item->setUnit($newUnit);

        // Le conditionnement de référence d'un produit saisi à la main suit la correction :
        // le prochain ajout du même aliment repartira de la bonne unité. Les fiches
        // Open Food Facts, elles, sont partagées entre foyers et ne sont jamais réécrites ici.
        $product = $item->getProduct();
        if (ProductSource::Manual === $product->getSource()) {
            $product->setPackageUnit($newUnit);
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
        );
        $this->entityManager->flush();

        return $movement;
    }

    /** Correction approximative : « plein », « environ la moitié », « presque vide ». */
    public function applyFillLevel(StockItem $item, FillLevel $level, Member $author): ?StockMovement
    {
        $ratio = $level->ratio();

        if (null === $ratio) {
            $item->setQuantityIsEstimated(true);
            $this->entityManager->flush();

            return null;
        }

        $movement = $this->correct(
            $item,
            round($item->getInitialQuantity() * $ratio, 3),
            $item->getUnit(),
            $author,
            sprintf('Estimation : %s', $level->label()),
        );

        $item->setQuantityIsEstimated(true);
        $this->entityManager->flush();

        return $movement;
    }

    public function markOpened(StockItem $item, bool $opened): StockItem
    {
        $item->setOpenedAt($opened ? ($item->getOpenedAt() ?? new \DateTimeImmutable()) : null);
        $this->entityManager->flush();

        return $item;
    }

    /** Produit jeté : le stock tombe à zéro mais la ligne et son historique subsistent. */
    public function discard(StockItem $item, Member $author, ?string $reason = null): StockMovement
    {
        if ($item->isEmpty()) {
            throw new StockException('Ce produit est déjà épuisé.');
        }

        $discarded = $item->getRemainingQuantity();
        $item->setRemainingQuantity(0);

        $movement = $this->record($item, MovementType::Deletion, -$discarded, $author, $reason ?? 'Produit jeté');
        $this->entityManager->flush();

        return $movement;
    }

    public function delete(StockItem $item): void
    {
        $this->entityManager->remove($item);
        $this->entityManager->flush();
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
    ): StockMovement {
        $movement = new StockMovement(
            $item,
            $type,
            $signedQuantity,
            $item->getUnit(),
            $item->getRemainingQuantity(),
            $reason,
            $author,
        );

        $this->entityManager->persist($movement);

        return $movement;
    }
}
