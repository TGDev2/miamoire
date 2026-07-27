<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use App\Enum\Allergen;
use App\Enum\ProductSource;
use App\Enum\SafetyVerdict;
use App\Enum\Unit;
use App\Service\Ai\Safety\ProductAllergenAssessor;
use App\Service\Product\VariantIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Le contrôle structuré : ce qu'on sait vraiment de la composition d'un
 * produit, distinct du grep sur le texte d'une recette.
 *
 * Trois issues, pas deux : `Unknown` n'est pas un défaut technique, c'est
 * l'état honnête d'un produit dont la composition n'a jamais été vérifiée.
 */
class ProductAllergenAssessorTest extends TestCase
{
    private Household $household;
    private ProductAllergenAssessor $assessor;

    protected function setUp(): void
    {
        $this->household = new Household('Foyer de test');
        $this->assessor = new ProductAllergenAssessor();
    }

    public function testUnknownWhenNeitherProductNorHouseholdDeclareAnything(): void
    {
        $variant = $this->variant(null);

        self::assertSame(SafetyVerdict::Unknown, $this->assessor->assess($variant, Allergen::Milk));
    }

    public function testIncompatibleWhenTheProductDeclaresTheAllergen(): void
    {
        $variant = $this->variant($this->product(allergensTags: ['en:milk']));

        self::assertSame(SafetyVerdict::Incompatible, $this->assessor->assess($variant, Allergen::Milk));
    }

    public function testIncompatibleWhenOnlyATraceIsDeclared(): void
    {
        $variant = $this->variant($this->product(tracesTags: ['en:milk']));

        self::assertSame(SafetyVerdict::Incompatible, $this->assessor->assess($variant, Allergen::Milk));
    }

    public function testCompatibleWhenTheProductDeclaresOtherAllergensOnly(): void
    {
        $variant = $this->variant($this->product(allergensTags: ['en:gluten']));

        self::assertSame(SafetyVerdict::Compatible, $this->assessor->assess($variant, Allergen::Milk));
    }

    /** La correction du foyer prime toujours sur la fiche catalogue. */
    public function testHouseholdOverrideWinsOverTheProduct(): void
    {
        $variant = $this->variant($this->product(allergensTags: ['en:milk']));
        $variant->setAllergensOverride([]);

        self::assertSame(
            SafetyVerdict::Compatible,
            $this->assessor->assess($variant, Allergen::Milk),
            'Le foyer a vérifié : aucun allergène suivi, malgré ce que dit le produit.',
        );
    }

    /**
     * Un produit saisi à la main n'a jamais été confronté à Open Food Facts :
     * un tableau vide y signifierait « jamais rempli », pas « vérifié sans
     * allergène ». Sans correction explicite du foyer, il reste inconnu.
     */
    public function testManualProductWithoutOverrideIsUnknownEvenIfLinkedToAProduct(): void
    {
        $variant = $this->variant($this->product(source: ProductSource::Manual));

        self::assertSame(SafetyVerdict::Unknown, $this->assessor->assess($variant, Allergen::Milk));
    }

    /** Une correction explicite lève le doute même pour un produit manuel. */
    public function testHouseholdOverrideLiftsTheDoubtOnAManualProduct(): void
    {
        $variant = $this->variant($this->product(source: ProductSource::Manual));
        $variant->setAllergensOverride([]);

        self::assertSame(SafetyVerdict::Compatible, $this->assessor->assess($variant, Allergen::Milk));
    }

    private function variant(?Product $product): HouseholdProductVariant
    {
        $identity = VariantIdentity::of('lait', Unit::Liter->family(), []);
        $variant = new HouseholdProductVariant($this->household, 'Lait', $identity, $identity->slugBase());
        $variant->setProduct($product);

        return $variant;
    }

    /**
     * @param list<string> $allergensTags
     * @param list<string> $tracesTags
     */
    private function product(
        array $allergensTags = [],
        array $tracesTags = [],
        ProductSource $source = ProductSource::OpenFoodFacts,
    ): Product {
        $product = new Product('Lait demi-écrémé', 'lait', $source);
        $product->setAllergensTags($allergensTags);
        $product->setTracesTags($tracesTags);

        return $product;
    }
}
