<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\FoodGroup;
use App\Enum\Unit;
use App\Exception\StockException;
use App\Service\Ai\PriorityChoice;
use App\Service\Ai\RecipeRequest;
use App\Service\Ai\RecipeSuggestionService;
use App\Service\Ai\ResolvedPriority;
use App\Service\Inventory\InventoryLine;
use App\Service\Inventory\InventoryPackaging;
use App\Service\UnitConverter;
use PHPUnit\Framework\TestCase;

/**
 * La résolution des aliments prioritaires (1 imposé + jusqu'à 2 souhaités) et
 * de la quantité facultative que chacun peut porter.
 *
 * `resolvePriorities()` est privée et n'a besoin que du convertisseur d'unités —
 * ni réseau, ni base — donc testée par réflexion plutôt qu'en construisant tout
 * le graphe de `RecipeSuggestionService` (générateur IA, agrégateur,
 * garde-fous...) pour une seule méthode qui n'en a besoin d'aucun.
 */
class RecipeSuggestionServiceTest extends TestCase
{
    /**
     * @param list<InventoryLine>  $inventory
     * @param list<PriorityChoice> $priorities
     *
     * @return array{required: ?ResolvedPriority, desired: list<ResolvedPriority>}
     */
    private function resolvePriorities(array $inventory, array $priorities): array
    {
        $service = (new \ReflectionClass(RecipeSuggestionService::class))->newInstanceWithoutConstructor();

        $converter = new \ReflectionProperty(RecipeSuggestionService::class, 'converter');
        $converter->setValue($service, new UnitConverter());

        $method = new \ReflectionMethod(RecipeSuggestionService::class, 'resolvePriorities');

        /** @var array{required: ?ResolvedPriority, desired: list<ResolvedPriority>} */
        return $method->invoke($service, $inventory, $priorities);
    }

    public function testNoKeysResolvesToNothing(): void
    {
        $result = $this->resolvePriorities([$this->line('courgette', 'Courgettes')], []);

        self::assertNull($result['required']);
        self::assertSame([], $result['desired']);
    }

    /** Comportement inchangé de l'ancien `priorityKey` : le premier aliment est une contrainte. */
    public function testFirstKeyBecomesRequired(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities([$courgette], [PriorityChoice::of('courgette')]);

        self::assertSame($courgette, $result['required']->line);
        self::assertFalse($result['required']->hasTarget(), 'Aucune quantité imposée : le cas courant.');
        self::assertSame([], $result['desired']);
    }

    public function testSubsequentKeysBecomeDesired(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');
        $creme = $this->line('creme', 'Crème entamée');
        $fromage = $this->line('fromage', 'Fromage');

        $result = $this->resolvePriorities(
            [$courgette, $creme, $fromage],
            [PriorityChoice::of('courgette'), PriorityChoice::of('creme'), PriorityChoice::of('fromage')],
        );

        self::assertSame($courgette, $result['required']->line);
        self::assertSame([$creme, $fromage], array_map(static fn (ResolvedPriority $p) => $p->line, $result['desired']));
    }

    /** Au plus 2 souhaités, même si davantage sont envoyés (le contrôleur tronque déjà à 3 au total). */
    public function testDesiredIngredientsAreCappedAtTwo(): void
    {
        $lines = array_map(fn (string $key) => $this->line($key, strtoupper($key)), ['a', 'b', 'c', 'd']);

        $result = $this->resolvePriorities($lines, array_map(PriorityChoice::of(...), ['a', 'b', 'c', 'd']));

        self::assertSame($lines[0], $result['required']->line);
        self::assertSame(
            [$lines[1], $lines[2]],
            array_map(static fn (ResolvedPriority $p) => $p->line, $result['desired']),
        );
    }

    public function testDuplicateKeysAreDeduplicated(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities(
            [$courgette],
            [PriorityChoice::of('courgette'), PriorityChoice::of('courgette')],
        );

        self::assertSame($courgette, $result['required']->line);
        self::assertSame([], $result['desired']);
    }

    /**
     * Contrainte, donc erreur explicite : une clé imposée introuvable ne doit
     * jamais être ignorée en silence.
     */
    public function testAnUnknownRequiredKeyThrows(): void
    {
        $this->expectException(StockException::class);

        $this->resolvePriorities([$this->line('courgette', 'Courgettes')], [PriorityChoice::of('inconnu')]);
    }

    /**
     * Préférence, donc tolérance : une clé souhaitée introuvable est
     * silencieusement ignorée plutôt que de faire échouer toute la
     * génération pour un aliment facultatif par nature.
     */
    public function testAnUnknownDesiredKeyIsSilentlyDropped(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities(
            [$courgette],
            [PriorityChoice::of('courgette'), PriorityChoice::of('inconnu')],
        );

        self::assertSame($courgette, $result['required']->line);
        self::assertSame([], $result['desired']);
    }

    public function testAnImposedQuantityIsKeptAsIs(): void
    {
        $result = $this->resolvePriorities(
            [$this->line('courgette', 'Courgettes')],
            [PriorityChoice::ofQuantity('courgette', 400, Unit::Gram)],
        );

        self::assertSame(400.0, $result['required']->quantity);
        self::assertSame(Unit::Gram, $result['required']->unit);
        self::assertSame('400 g', $result['required']->describe());
    }

    /** Une unité de la même famille se convertit vers celle de la ligne. */
    public function testAQuantityInAnotherUnitOfTheSameFamilyIsConverted(): void
    {
        $result = $this->resolvePriorities(
            [$this->line('courgette', 'Courgettes')],
            [PriorityChoice::ofQuantity('courgette', 0.3, Unit::Kilogram)],
        );

        self::assertSame(300.0, $result['required']->quantity);
        self::assertSame(Unit::Gram, $result['required']->unit);
    }

    /**
     * Le geste que la fonctionnalité vise : « écoule une boîte », pas
     * « écoule 400 g ». C'est ici que la boîte devient une quantité, parce que
     * c'est ici seulement qu'on sait ce qu'elle pèse.
     */
    public function testPackagesBecomeAQuantity(): void
    {
        $line = $this->line('tomate', 'Tomates pelées', quantity: 800, packaging: new InventoryPackaging(2, 400, Unit::Gram));

        $result = $this->resolvePriorities([$line], [PriorityChoice::ofPackages('tomate', 1)]);

        self::assertSame(400.0, $result['required']->quantity);
        self::assertSame('400 g', $result['required']->describe());
    }

    /**
     * Sans conditionnement connu, « un exemplaire » ne veut rien dire — mais
     * l'utilisateur a bien désigné un aliment à écouler, et ce geste-là reste
     * valable. On abandonne le chiffre, jamais la contrainte.
     */
    public function testPackagesWithoutKnownPackagingLoseTheQuantityNotTheFood(): void
    {
        $line = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities([$line], [PriorityChoice::ofPackages('courgette', 2)]);

        self::assertSame($line, $result['required']->line);
        self::assertFalse($result['required']->hasTarget());
    }

    /**
     * Des grammes ne deviennent pas des litres : `UnitConverter` refuse de
     * franchir les familles, et ce refus protège l'inventaire. La quantité est
     * abandonnée, l'aliment reste imposé.
     */
    public function testAQuantityFromAnotherUnitFamilyIsDropped(): void
    {
        $line = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities([$line], [PriorityChoice::ofQuantity('courgette', 2, Unit::Liter)]);

        self::assertSame($line, $result['required']->line);
        self::assertFalse($result['required']->hasTarget());
    }

    /**
     * On ne peut pas écouler ce qu'on n'a pas. Sans ce plafonnement, une cible
     * inatteignable écarterait les trois recettes pour une faute que
     * l'utilisateur n'a pas commise.
     */
    public function testATargetLargerThanTheStockIsCappedToWhatRemains(): void
    {
        $result = $this->resolvePriorities(
            [$this->line('courgette', 'Courgettes', quantity: 500)],
            [PriorityChoice::ofQuantity('courgette', 800, Unit::Gram)],
        );

        self::assertSame(500.0, $result['required']->quantity);
    }

    /** Un aliment souhaité porte lui aussi sa quantité — un souhait, jamais un rejet. */
    public function testDesiredIngredientsCarryTheirOwnTarget(): void
    {
        $result = $this->resolvePriorities(
            [$this->line('courgette', 'Courgettes'), $this->line('creme', 'Crème')],
            [PriorityChoice::of('courgette'), PriorityChoice::ofQuantity('creme', 200, Unit::Gram)],
        );

        self::assertFalse($result['required']->hasTarget());
        self::assertSame(200.0, $result['desired'][0]->quantity);
    }

    /** Rien n'indique que relancer échouera : c'est le bon conseil. */
    public function testRetryIsAdvisedWhenNoRecipeLackedARequestedGroup(): void
    {
        self::assertSame('Relancez une génération.', $this->retryAdvice([], new RecipeRequest()));
    }

    /**
     * Un inventaire sans protéine n'en aura pas davantage au prochain essai :
     * le conseil désigne ce qui débloque vraiment la situation.
     */
    public function testAMissingGroupPointsToTheSettingsInsteadOfARetry(): void
    {
        $rejected = [['recipeName' => 'Tian', 'reason' => 'sans protéine']];

        $strict = $this->retryAdvice($rejected, new RecipeRequest(foodGroups: [FoodGroup::Protein]));
        self::assertStringNotContainsString('Relancez', $strict);
        self::assertStringContainsString('décochez ce groupe', $strict);
        self::assertStringContainsString('autorisez quelques ingrédients manquants', $strict);

        // Les ajouts déjà autorisés, il ne reste qu'à décocher.
        $lenient = $this->retryAdvice($rejected, new RecipeRequest(allowExtraIngredients: true, foodGroups: [FoodGroup::Protein]));
        self::assertStringContainsString('décochez ce groupe', $lenient);
        self::assertStringNotContainsString('autorisez', $lenient);
    }

    /** @param list<array{recipeName: string, reason: string}> $compositionRejections */
    private function retryAdvice(array $compositionRejections, RecipeRequest $request): string
    {
        $service = (new \ReflectionClass(RecipeSuggestionService::class))->newInstanceWithoutConstructor();

        /** @var string */
        return (new \ReflectionMethod(RecipeSuggestionService::class, 'retryAdvice'))
            ->invoke($service, $compositionRejections, $request);
    }

    private function line(
        string $key,
        string $label,
        float $quantity = 500,
        ?InventoryPackaging $packaging = null,
    ): InventoryLine {
        return new InventoryLine(
            key: $key,
            variantId: '00000000-0000-0000-0000-000000000000',
            label: $label,
            name: $label,
            quantity: $quantity,
            unit: Unit::Gram,
            expiresSoon: false,
            alreadyOpened: false,
            earliestExpiration: null,
            expirationIsEstimated: false,
            quantityIsEstimated: false,
            category: null,
            locations: [],
            lotCount: 1,
            packaging: $packaging,
        );
    }
}
