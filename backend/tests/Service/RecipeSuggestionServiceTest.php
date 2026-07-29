<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Unit;
use App\Exception\StockException;
use App\Service\Ai\RecipeSuggestionService;
use App\Service\Inventory\InventoryLine;
use PHPUnit\Framework\TestCase;

/**
 * La résolution des aliments prioritaires (1 imposé + jusqu'à 2 souhaités).
 *
 * `resolvePriorities()` est privée et pure — aucune dépendance réseau ni
 * base — donc testée par réflexion plutôt qu'en construisant tout le graphe
 * de `RecipeSuggestionService` (générateur IA, agrégateur, garde-fous...)
 * pour une seule méthode qui n'en a besoin d'aucun.
 */
class RecipeSuggestionServiceTest extends TestCase
{
    /**
     * @param list<InventoryLine> $inventory
     * @param list<string>        $priorityKeys
     *
     * @return array{required: ?InventoryLine, desired: list<InventoryLine>}
     */
    private function resolvePriorities(array $inventory, array $priorityKeys): array
    {
        $service = (new \ReflectionClass(RecipeSuggestionService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RecipeSuggestionService::class, 'resolvePriorities');

        /** @var array{required: ?InventoryLine, desired: list<InventoryLine>} */
        return $method->invoke($service, $inventory, $priorityKeys);
    }

    public function testNoKeysResolvesToNothing(): void
    {
        $result = $this->resolvePriorities([$this->line('courgette', 'Courgettes')], []);

        self::assertNull($result['required']);
        self::assertSame([], $result['desired']);
    }

    /** Comportement inchangé de l'ancien `priorityKey` : la première clé est une contrainte. */
    public function testFirstKeyBecomesRequired(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities([$courgette], ['courgette']);

        self::assertSame($courgette, $result['required']);
        self::assertSame([], $result['desired']);
    }

    public function testSubsequentKeysBecomeDesired(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');
        $creme = $this->line('creme', 'Crème entamée');
        $fromage = $this->line('fromage', 'Fromage');

        $result = $this->resolvePriorities([$courgette, $creme, $fromage], ['courgette', 'creme', 'fromage']);

        self::assertSame($courgette, $result['required']);
        self::assertSame([$creme, $fromage], $result['desired']);
    }

    /** Au plus 2 souhaités, même si davantage sont envoyées (le contrôleur tronque déjà à 3 au total). */
    public function testDesiredIngredientsAreCappedAtTwo(): void
    {
        $a = $this->line('a', 'A');
        $b = $this->line('b', 'B');
        $c = $this->line('c', 'C');
        $d = $this->line('d', 'D');

        $result = $this->resolvePriorities([$a, $b, $c, $d], ['a', 'b', 'c', 'd']);

        self::assertSame($a, $result['required']);
        self::assertSame([$b, $c], $result['desired']);
    }

    public function testDuplicateKeysAreDeduplicated(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities([$courgette], ['courgette', 'courgette']);

        self::assertSame($courgette, $result['required']);
        self::assertSame([], $result['desired']);
    }

    /**
     * Contrainte, donc erreur explicite : une clé imposée introuvable ne doit
     * jamais être ignorée en silence.
     */
    public function testAnUnknownRequiredKeyThrows(): void
    {
        $this->expectException(StockException::class);

        $this->resolvePriorities([$this->line('courgette', 'Courgettes')], ['inconnu']);
    }

    /**
     * Préférence, donc tolérance : une clé souhaitée introuvable est
     * silencieusement ignorée plutôt que de faire échouer toute la
     * génération pour un aliment facultatif par nature.
     */
    public function testAnUnknownDesiredKeyIsSilentlyDropped(): void
    {
        $courgette = $this->line('courgette', 'Courgettes');

        $result = $this->resolvePriorities([$courgette], ['courgette', 'inconnu']);

        self::assertSame($courgette, $result['required']);
        self::assertSame([], $result['desired']);
    }

    private function line(string $key, string $label): InventoryLine
    {
        return new InventoryLine(
            key: $key,
            variantId: '00000000-0000-0000-0000-000000000000',
            label: $label,
            name: $label,
            quantity: 500,
            unit: Unit::Gram,
            expiresSoon: false,
            alreadyOpened: false,
            earliestExpiration: null,
            expirationIsEstimated: false,
            quantityIsEstimated: false,
            category: null,
            locations: [],
            lotCount: 1,
        );
    }
}
