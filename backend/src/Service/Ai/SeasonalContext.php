<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Enum\Season;

/**
 * Le moment où la recette est demandée — la date, et ce qu'elle implique.
 *
 * Le modèle recevait des échéances absolues (`useBy: 2026-08-06`) sans jamais
 * savoir quel jour on était. Il ne pouvait donc ni juger lui-même de l'urgence —
 * il dépendait entièrement du booléen `expiresSoon` calculé pour lui — ni
 * seulement remarquer qu'on ne mange pas la même chose en février et en août.
 *
 * La date est **reçue en argument** et jamais lue ici : c'est ce qui rend la
 * saison testable sans horloge injectée. Le projet n'a pas `symfony/clock`, et
 * lit l'heure au bord (`new \DateTimeImmutable('today')`), comme
 * {@see \App\Service\Freshness\EffectiveUseBy::daysFromToday()}.
 */
final readonly class SeasonalContext
{
    /** @var list<string> */
    private const DAYS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    /** @var list<string> */
    private const MONTHS = [
        'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];

    /** @param array{legumes: list<string>, fruits: list<string>} $produce */
    public function __construct(
        public \DateTimeImmutable $date,
        public Season $season,
        public array $produce,
    ) {
    }

    /** « mardi 4 août 2026 » — une date qu'un modèle situe mieux qu'un `2026-08-04` seul. */
    public function todayLabel(): string
    {
        return sprintf(
            '%s %d %s %s',
            self::DAYS[(int) $this->date->format('N') - 1],
            (int) $this->date->format('j'),
            self::MONTHS[(int) $this->date->format('n') - 1],
            $this->date->format('Y'),
        );
    }

    /** @return array<string, mixed> */
    public function toAiPayload(): array
    {
        return [
            'today' => $this->date->format('Y-m-d'),
            'todayLabel' => $this->todayLabel(),
            'season' => $this->season->label(),
            'seasonalProduce' => $this->produce,
        ];
    }
}
