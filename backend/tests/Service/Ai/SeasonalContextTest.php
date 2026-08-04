<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Enum\Season;
use App\Service\Ai\SeasonalContextFactory;
use App\Service\Food\SeasonalProduceCalendar;
use PHPUnit\Framework\TestCase;

/**
 * Le générateur ne recevait aucune date. Il lisait `useBy: 2026-08-06` sans savoir
 * si c'était demain, et proposait un pot-au-feu en août aussi volontiers qu'un
 * gaspacho en janvier.
 */
class SeasonalContextTest extends TestCase
{
    private SeasonalContextFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new SeasonalContextFactory(new SeasonalProduceCalendar());
    }

    public function testSummerCarriesItsOwnProduce(): void
    {
        $context = $this->factory->for(new \DateTimeImmutable('2026-08-04'));

        self::assertSame(Season::Summer, $context->season);
        self::assertContains('courgette', $context->produce['legumes']);
        self::assertContains('tomate', $context->produce['legumes']);
        self::assertNotContains('endive', $context->produce['legumes'], 'L\'endive est un légume d\'hiver.');
        self::assertContains('melon', $context->produce['fruits']);
    }

    public function testWinterCarriesItsOwn(): void
    {
        $context = $this->factory->for(new \DateTimeImmutable('2026-01-15'));

        self::assertSame(Season::Winter, $context->season);
        self::assertContains('poireau', $context->produce['legumes']);
        self::assertContains('clémentine', $context->produce['fruits']);
        self::assertNotContains('fraise', $context->produce['fruits'], 'Pas de fraise en janvier.');
    }

    /** Découpage météorologique : l'été commence le 1ᵉʳ juin, pas au solstice. */
    public function testSeasonBoundariesFollowTheMonth(): void
    {
        self::assertSame(Season::Spring, Season::fromMonth(5));
        self::assertSame(Season::Summer, Season::fromMonth(6));
        self::assertSame(Season::Summer, Season::fromMonth(8));
        self::assertSame(Season::Autumn, Season::fromMonth(9));
        self::assertSame(Season::Winter, Season::fromMonth(12));
        self::assertSame(Season::Winter, Season::fromMonth(1));
    }

    /** C'est cette date, écrite en toutes lettres, qui situe toutes les échéances. */
    public function testTheDateIsWrittenSoAModelCanPlaceIt(): void
    {
        $context = $this->factory->for(new \DateTimeImmutable('2026-08-04'));

        self::assertSame('mardi 4 août 2026', $context->todayLabel());

        $payload = $context->toAiPayload();
        self::assertSame('2026-08-04', $payload['today']);
        self::assertSame('été', $payload['season']);
    }

    /** Un mois sans produits de saison laisserait le modèle inventer sa liste. */
    public function testEveryMonthIsCovered(): void
    {
        $calendar = new SeasonalProduceCalendar();

        for ($month = 1; $month <= 12; ++$month) {
            $produce = $calendar->forMonth($month);

            self::assertNotEmpty($produce['legumes'], sprintf('Mois %d sans légume.', $month));
            self::assertNotEmpty($produce['fruits'], sprintf('Mois %d sans fruit.', $month));
        }
    }
}
