<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProductSource;
use App\Enum\ProductTrait;
use App\Enum\Unit;
use App\Repository\ProductRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Le catalogue canonique — « Lait demi-écrémé Lactel, bouteille de 1 L ».
 *
 * Ne porte JAMAIS de quantité possédée : c'est le rôle de StockItem. Ne porte
 * plus non plus de correction locale : c'est le rôle de HouseholdProductVariant.
 *
 * Cette table est la seule que tous les foyers partagent. Elle est donc écrite
 * par une seule voie, `ProductLookupService`, à partir d'Open Food Facts ou d'une
 * saisie explicite — jamais au fil de l'édition d'un lot. Un foyer qui corrige le
 * nom d'un aliment corrige sa variante, et ne change rien pour les autres.
 *
 * Sert aussi de cache local pour Open Food Facts, dont les lectures produit
 * sont limitées à 15 requêtes/minute/IP.
 */
#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\Table(name: 'product')]
#[ORM\Index(columns: ['ingredient_key'], name: 'idx_product_ingredient_key')]
class Product
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\Column(length: 64, unique: true, nullable: true)]
    private ?string $barcode = null;

    #[ORM\Column(length: 255)]
    private string $name;

    /** Clé normalisée (sans accent, sans marque) servant au regroupement et au matching IA. */
    #[ORM\Column(length: 255)]
    private string $ingredientKey;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $brand = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $category = null;

    /**
     * État réel du produit — en conserve, épluché, surgelé…
     *
     * Sans lui, une recette faite de pommes de terre en conserve commencerait par
     * « épluchez les pommes de terre ». Détecté à la création, corrigeable à la main.
     *
     * @var list<string> valeurs de ProductTrait
     */
    #[ORM\Column(type: 'json')]
    private array $traits = [];

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $packageQuantity = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true, enumType: Unit::class)]
    private ?Unit $packageUnit = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(type: 'string', length: 32, enumType: ProductSource::class)]
    private ProductSource $source;

    /** Dernière synchronisation Open Food Facts — pilote l'expiration du cache. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $refreshedAt = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, string $ingredientKey, ProductSource $source)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->ingredientKey = $ingredientKey;
        $this->source = $source;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBarcode(): ?string
    {
        return $this->barcode;
    }

    public function setBarcode(?string $barcode): void
    {
        $this->barcode = $barcode;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIngredientKey(): string
    {
        return $this->ingredientKey;
    }

    /**
     * Renommer et reclasser sont un seul geste.
     *
     * Deux accesseurs indépendants laissaient renommer sans régénérer la clé :
     * l'aliment gardait alors l'ancienne clé culinaire et se rapprochait des
     * mauvais lots. L'invariant était pourtant déjà respecté par le
     * rafraîchissement Open Food Facts ; il est maintenant impossible à enfreindre.
     */
    public function renameFromCatalog(string $name, string $ingredientKey): void
    {
        $this->name = $name;
        $this->ingredientKey = $ingredientKey;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function setBrand(?string $brand): void
    {
        $this->brand = $brand;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(?string $category): void
    {
        $this->category = $category;
    }

    /** @return list<ProductTrait> */
    public function getTraits(): array
    {
        return ProductTrait::fromValues($this->traits);
    }

    /** @param list<ProductTrait> $traits */
    public function setTraits(array $traits): void
    {
        $this->traits = ProductTrait::toValues($traits);
    }

    public function getPackageQuantity(): ?float
    {
        return $this->packageQuantity;
    }

    public function setPackageQuantity(?float $packageQuantity): void
    {
        $this->packageQuantity = $packageQuantity;
    }

    public function getPackageUnit(): ?Unit
    {
        return $this->packageUnit;
    }

    public function setPackageUnit(?Unit $packageUnit): void
    {
        $this->packageUnit = $packageUnit;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function setImageUrl(?string $imageUrl): void
    {
        $this->imageUrl = $imageUrl;
    }

    public function getSource(): ProductSource
    {
        return $this->source;
    }

    public function setSource(ProductSource $source): void
    {
        $this->source = $source;
    }

    public function getRefreshedAt(): ?\DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    public function setRefreshedAt(?\DateTimeImmutable $refreshedAt): void
    {
        $this->refreshedAt = $refreshedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isStale(int $ttlDays): bool
    {
        if (ProductSource::OpenFoodFacts !== $this->source) {
            return false;
        }

        return null === $this->refreshedAt
            || $this->refreshedAt < new \DateTimeImmutable(sprintf('-%d days', $ttlDays));
    }

    /** Libellé affichable : « Pâtes penne — Barilla ». */
    public function getDisplayName(): string
    {
        return null !== $this->brand && '' !== $this->brand
            ? sprintf('%s — %s', $this->name, $this->brand)
            : $this->name;
    }
}
