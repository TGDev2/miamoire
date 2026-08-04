<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\Season;
use App\Service\Food\SeasonalProduceCalendar;

/** Assemble la date, la saison qu'elle désigne, et les produits de ce mois-là. */
final readonly class SeasonalContextFactory
{
    public function __construct(private SeasonalProduceCalendar $calendar)
    {
    }

    public function for(\DateTimeImmutable $date): SeasonalContext
    {
        $month = (int) $date->format('n');

        return new SeasonalContext($date, Season::fromMonth($month), $this->calendar->forMonth($month));
    }
}
