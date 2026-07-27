<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Household;
use App\Entity\HouseholdProductVariant;
use App\Entity\Product;
use App\Entity\StockItem;
use App\Enum\Allergen;
use App\Enum\ProductSource;
use App\Enum\RecipePlanStatus;
use App\Enum\StorageLocation;
use App\Enum\Unit;
use App\Repository\HouseholdProductVariantRepository;
use App\Repository\StockItemRepository;
use App\Service\Ai\RecipePlanService;
use App\Service\Ai\Safety\DietaryGuard;
use App\Service\Ai\Safety\ProductAllergenAssessor;
use App\Service\Ai\Safety\RecipeSafetyFilter;
use App\Service\Product\VariantIdentity;
use App\Service\Stock\FefoAllocator;
use App\Service\TextNormalizer;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

/**
 * Le plan de préparation recalculé — le cœur de la réévaluation honnête d'une
 * recette. Chacun des cinq statuts est vérifié isolément, puis la priorité
 * entre eux quand plusieurs lignes d'une même recette ne sont pas d'accord.
 */
class RecipePlanServiceTest extends TestCase
{
    private Household $household;
    private TextNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->household = new Household('Foyer de test');
        $this->normalizer = new TextNormalizer();
    }

    public function testReadyWhenEverythingChecksOut(): void
    {
        $variant = $this->variant('lait', Unit::Milliliter, $this->product(['en:gluten']));

        $plan = $this->planner($this->repository(['lait' => $variant]), [$this->lot($variant, 1000, Unit::Milliliter)])
            ->plan($this->guard(), [], $this->household, $this->recipe());

        self::assertSame(RecipePlanStatus::Ready, $plan->status);
        self::assertNull($plan->reason);
    }

    public function testNeedsShoppingWhenStockIsInsufficient(): void
    {
        $variant = $this->variant('lait', Unit::Milliliter, $this->product());

        $plan = $this->planner($this->repository(['lait' => $variant]), [$this->lot($variant, 100, Unit::Milliliter)])
            ->plan($this->guard(), [], $this->household, $this->recipe());

        self::assertSame(RecipePlanStatus::NeedsShopping, $plan->status);
    }

    public function testNeedsReassociationWhenTheKeyNoLongerResolves(): void
    {
        $plan = $this->planner($this->repository([]), [])
            ->plan($this->guard(), [], $this->household, $this->recipe());

        self::assertSame(RecipePlanStatus::NeedsReassociation, $plan->status);
    }

    /** Une allergie stricte sans fiche allergènes vérifiée ne doit jamais se lire comme sûre. */
    public function testSafetyUnknownWhenTheProductHasNoAllergenData(): void
    {
        $variant = $this->variant('lait', Unit::Milliliter, product: null);

        $plan = $this->planner($this->repository(['lait' => $variant]), [$this->lot($variant, 1000, Unit::Milliliter)])
            ->plan($this->guard(), ['lait'], $this->household, $this->recipe());

        self::assertSame(RecipePlanStatus::SafetyUnknown, $plan->status);
        self::assertContains('Lait et produits laitiers', $plan->checkedExclusions);
    }

    public function testIncompatibleWhenTheProductDeclaresTheExcludedAllergen(): void
    {
        $variant = $this->variant('lait', Unit::Milliliter, $this->product(['en:milk']));

        $plan = $this->planner($this->repository(['lait' => $variant]), [$this->lot($variant, 1000, Unit::Milliliter)])
            ->plan($this->guard(), ['lait'], $this->household, $this->recipe());

        self::assertSame(RecipePlanStatus::Incompatible, $plan->status);
    }

    /** Le contrôle lexical reste le premier rempart, même sans donnée produit. */
    public function testLexicalViolationForcesIncompatibleRegardlessOfStructuredData(): void
    {
        $variant = $this->variant('riz', Unit::Gram, $this->product());

        $plan = $this->planner($this->repository(['riz' => $variant]), [$this->lot($variant, 1000, Unit::Gram)])
            ->plan(
                $this->guard('arachide'),
                [],
                $this->household,
                $this->recipe(summary: 'Ajouter un peu de beurre de cacahuète.'),
            );

        self::assertSame(RecipePlanStatus::Incompatible, $plan->status);
    }

    /** Quand plusieurs lignes désaccordent, c'est le pire statut qui l'emporte. */
    public function testTheWorstLineStatusWins(): void
    {
        $shortage = $this->variant('riz', Unit::Gram, $this->product());
        $unknown = $this->variant('lait', Unit::Milliliter, product: null);

        $repository = $this->repository(['riz' => $shortage, 'lait' => $unknown]);
        $lots = [$this->lot($shortage, 10, Unit::Gram), $this->lot($unknown, 1000, Unit::Milliliter)];

        $recipe = $this->recipe();
        $recipe['ingredientsFromInventory'][] = [
            'key' => 'riz', 'label' => 'Riz', 'quantity' => 200, 'unit' => 'g', 'essential' => true,
        ];
        $recipe['stockConsumptionProposal'][] = [
            'key' => 'riz', 'label' => 'Riz', 'quantity' => 200, 'unit' => 'g',
        ];

        $plan = $this->planner($repository, $lots)->plan($this->guard(), ['lait'], $this->household, $recipe);

        self::assertSame(
            RecipePlanStatus::SafetyUnknown,
            $plan->status,
            'Sécurité inconnue est plus grave que stock insuffisant : elle doit l\'emporter.',
        );
    }

    /** @return array<string, mixed> */
    private function recipe(string $summary = 'Un dessert simple.'): array
    {
        return [
            'recipeName' => 'Riz au lait',
            'feasibility' => 'ready',
            'servings' => 2,
            'preparationTime' => 10,
            'summary' => $summary,
            'includesSideDish' => false,
            'ingredientsFromInventory' => [
                ['key' => 'lait', 'label' => 'Lait', 'quantity' => 500, 'unit' => 'ml', 'essential' => true],
            ],
            'missingIngredients' => [],
            'substitutions' => [],
            'steps' => ['Faire chauffer le lait.'],
            'stockConsumptionProposal' => [
                ['key' => 'lait', 'label' => 'Lait', 'quantity' => 500, 'unit' => 'ml'],
            ],
        ];
    }

    private function guard(string ...$exclusions): DietaryGuard
    {
        return new DietaryGuard($this->normalizer, array_values($exclusions));
    }

    /** @param array<string, HouseholdProductVariant> $bySlug */
    private function repository(array $bySlug): HouseholdProductVariantRepository
    {
        $repository = $this->createMock(HouseholdProductVariantRepository::class);
        $repository->method('findOneBySlug')->willReturnCallback(
            static fn (Household $household, string $slug) => $bySlug[$slug] ?? null,
        );

        return $repository;
    }

    /** @param list<StockItem> $lots */
    private function planner(HouseholdProductVariantRepository $variants, array $lots): RecipePlanService
    {
        $stockItems = $this->createMock(StockItemRepository::class);
        $stockItems->method('findAvailableByVariant')->willReturn($lots);

        return new RecipePlanService(
            new RecipeSafetyFilter(),
            $variants,
            new ProductAllergenAssessor(),
            new FefoAllocator($stockItems, new UnitConverter()),
            $this->normalizer,
        );
    }

    private function variant(string $key, Unit $unit, ?Product $product): HouseholdProductVariant
    {
        $identity = VariantIdentity::of($key, $unit->family(), []);
        $variant = new HouseholdProductVariant($this->household, $key, $identity, $identity->slugBase());
        $variant->setProduct($product);

        return $variant;
    }

    /** @param list<string> $allergensTags */
    private function product(array $allergensTags = []): Product
    {
        $product = new Product('Lait demi-écrémé', 'lait', ProductSource::OpenFoodFacts);
        $product->setAllergensTags($allergensTags);

        return $product;
    }

    private function lot(HouseholdProductVariant $variant, float $quantity, Unit $unit): StockItem
    {
        return new StockItem($this->household, $variant, $quantity, $unit, StorageLocation::Pantry);
    }
}
