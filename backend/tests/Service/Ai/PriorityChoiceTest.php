<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Enum\Unit;
use App\Http\JsonPayload;
use App\Service\Ai\PriorityChoice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La lecture du corps de requête des aliments imposés.
 *
 * Une règle gouverne tout ce fichier : **un chiffre douteux ne coûte jamais la
 * contrainte elle-même**. Perdre la quantité est un désagrément, perdre
 * l'aliment imposé fait échouer la génération entière — et pour un champ qui,
 * la plupart du temps, n'est même pas rempli.
 */
class PriorityChoiceTest extends TestCase
{
    public function testAKeyAloneImposesTheFoodWithoutAnyQuantity(): void
    {
        $choice = PriorityChoice::fromArray(['key' => 'courgette']);

        self::assertSame('courgette', $choice->key);
        self::assertFalse($choice->hasAmount());
    }

    public function testAQuantityAndItsUnitAreRead(): void
    {
        $choice = PriorityChoice::fromArray(['key' => 'courgette', 'quantity' => 400, 'unit' => 'g']);

        self::assertSame(400.0, $choice->quantity);
        self::assertSame(Unit::Gram, $choice->unit);
        self::assertNull($choice->packages);
    }

    public function testPackagesAreRead(): void
    {
        $choice = PriorityChoice::fromArray(['key' => 'tomate', 'packages' => 2]);

        self::assertSame(2, $choice->packages);
        self::assertNull($choice->quantity);
    }

    /**
     * Les deux écritures ensemble ne peuvent pas exister en mémoire — le
     * constructeur est privé. Reçues ensemble malgré tout, les exemplaires
     * l'emportent : c'est le geste le plus concret des deux.
     */
    public function testPackagesWinWhenBothFormsArriveTogether(): void
    {
        $choice = PriorityChoice::fromArray([
            'key' => 'tomate',
            'packages' => 1,
            'quantity' => 999,
            'unit' => 'g',
        ]);

        self::assertSame(1, $choice->packages);
        self::assertNull($choice->quantity);
    }

    /** @param array<string, mixed> $entry */
    #[DataProvider('unusableAmounts')]
    public function testAnUnusableQuantityLosesTheNumberNotTheFood(array $entry): void
    {
        $choice = PriorityChoice::fromArray(['key' => 'courgette'] + $entry);

        self::assertNotNull($choice);
        self::assertSame('courgette', $choice->key, 'L\'aliment reste imposé.');
        self::assertFalse($choice->hasAmount());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function unusableAmounts(): iterable
    {
        yield 'unité inconnue' => [['quantity' => 400, 'unit' => 'cuillères']];
        yield 'unité absente' => [['quantity' => 400]];
        yield 'quantité négative' => [['quantity' => -5, 'unit' => 'g']];
        yield 'quantité nulle' => [['quantity' => 0, 'unit' => 'g']];
        yield 'quantité non numérique' => [['quantity' => 'beaucoup', 'unit' => 'g']];
        yield 'zéro exemplaire' => [['packages' => 0]];
        yield 'exemplaires non numériques' => [['packages' => 'deux']];
    }

    /** Sans clé, il n'y a rien à imposer : c'est le seul cas qui ne produit rien. */
    public function testAnEntryWithoutAKeyIsDropped(): void
    {
        self::assertNull(PriorityChoice::fromArray(['quantity' => 400, 'unit' => 'g']));
        self::assertNull(PriorityChoice::fromArray(['key' => '   ']));
    }

    public function testReadsTheModernPayload(): void
    {
        $choices = PriorityChoice::listFrom(JsonPayload::fromArray([
            'priorities' => [
                ['key' => 'tomate', 'packages' => 1],
                ['key' => 'creme', 'quantity' => 200, 'unit' => 'g'],
                ['quantity' => 50, 'unit' => 'g'],
            ],
        ]));

        self::assertCount(2, $choices, 'L\'entrée sans clé est écartée, pas les autres.');
        self::assertSame('tomate', $choices[0]->key);
        self::assertSame(200.0, $choices[1]->quantity);
    }

    /**
     * Le repli qui compte : un APK déjà installé n'envoie que `priorityKeys`, et
     * continuera de le faire tant qu'il ne sera pas mis à jour. Le backend se
     * déploie seul, l'application non — sans ce repli, imposer un aliment
     * cesserait de fonctionner sur tous les téléphones le jour de la mise en
     * production.
     */
    public function testStillReadsTheLegacyPayloadOfAnAlreadyInstalledApp(): void
    {
        $choices = PriorityChoice::listFrom(JsonPayload::fromArray([
            'priorityKeys' => ['courgette', 'creme'],
        ]));

        self::assertCount(2, $choices);
        self::assertSame(['courgette', 'creme'], array_map(static fn (PriorityChoice $c) => $c->key, $choices));
        self::assertFalse($choices[0]->hasAmount());
    }

    /** `priorities` fait foi dès qu'il est présent, même vide : l'utilisateur a pu tout retirer. */
    public function testAnEmptyModernPayloadDoesNotFallBackToTheLegacyOne(): void
    {
        $choices = PriorityChoice::listFrom(JsonPayload::fromArray([
            'priorities' => [],
            'priorityKeys' => ['courgette'],
        ]));

        self::assertSame([], $choices);
    }

    public function testNoPriorityAtAllIsTheCommonCase(): void
    {
        self::assertSame([], PriorityChoice::listFrom(JsonPayload::fromArray([])));
    }
}
