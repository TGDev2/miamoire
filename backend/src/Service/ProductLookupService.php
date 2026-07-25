<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Enum\ProductSource;
use App\Enum\Unit;
use App\Repository\ProductRepository;
use App\Service\OpenFoodFacts\OpenFoodFactsClient;
use App\Service\OpenFoodFacts\OpenFoodFactsProduct;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résolution d'un code-barres, cache local d'abord.
 *
 * Open Food Facts limite les lectures produit à 15 requêtes/minute/IP : sans ce
 * cache, une famille qui scanne sa course hebdomadaire épuiserait le quota.
 */
class ProductLookupService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly OpenFoodFactsClient $client,
        private readonly IngredientKeyGenerator $keyGenerator,
        private readonly ProductTraitDetector $traitDetector,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $cacheTtlDays,
    ) {
    }

    public function findByBarcode(string $barcode): ?Product
    {
        $cached = $this->products->findOneByBarcode($barcode);

        if (null !== $cached && !$cached->isStale($this->cacheTtlDays)) {
            return $cached;
        }

        $remote = $this->client->fetchProduct($barcode);

        if (null === $remote) {
            // Une fiche périmée reste plus utile qu'une absence de résultat.
            return $cached;
        }

        $product = $cached ?? new Product(
            $remote->name,
            $this->keyGenerator->generate($remote->name),
            ProductSource::OpenFoodFacts,
        );

        $this->applyRemoteData($product, $remote);

        if (null === $cached) {
            $this->entityManager->persist($product);
        }
        $this->entityManager->flush();

        return $product;
    }

    public function createManual(
        string $name,
        ?string $brand = null,
        ?string $category = null,
        ?float $packageQuantity = null,
        ?Unit $packageUnit = null,
        ?string $barcode = null,
    ): Product {
        $product = new Product($name, $this->keyGenerator->generate($name), ProductSource::Manual);
        $product->setBrand($brand);
        $product->setCategory($category);
        $product->setPackageQuantity($packageQuantity);
        $product->setPackageUnit($packageUnit);
        $product->setBarcode(null !== $barcode && '' !== $barcode ? $barcode : null);
        $product->setTraits($this->traitDetector->detect($name, $category));

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    private function applyRemoteData(Product $product, OpenFoodFactsProduct $remote): void
    {
        $product->setBarcode($remote->barcode);
        $product->setName($remote->name);
        $product->setIngredientKey($this->keyGenerator->generate($remote->name));
        $product->setBrand($remote->brand);
        $product->setCategory($remote->category);

        // Un rafraîchissement du cache ne doit pas effacer ce que l'utilisateur a corrigé.
        if ([] === $product->getTraits()) {
            $product->setTraits($this->traitDetector->detect($remote->name, $remote->category));
        }
        $product->setPackageQuantity($remote->packageQuantity);
        $product->setPackageUnit($remote->packageUnit);
        $product->setImageUrl($remote->imageUrl);
        $product->setSource(ProductSource::OpenFoodFacts);
        $product->setRefreshedAt(new \DateTimeImmutable());
    }
}
