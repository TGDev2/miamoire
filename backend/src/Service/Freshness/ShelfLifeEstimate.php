<?php

declare(strict_types=1);

namespace App\Service\Freshness;

use App\Enum\StorageLocation;

/**
 * Une durée de conservation déduite, et d'où elle vient.
 *
 * L'origine n'est pas un détail décoratif : « d'après « salade » » se discute,
 * « durée par défaut du réfrigérateur » se corrige. Une estimation qu'on ne peut
 * pas expliquer, on ne peut pas la contredire.
 */
final readonly class ShelfLifeEstimate
{
    private function __construct(
        public int $days,
        public string $source,
        public ?string $matched,
        public StorageLocation $location,
    ) {
    }

    public static function fromFood(int $days, string $matched, StorageLocation $location): self
    {
        return new self($days, 'food', $matched, $location);
    }

    public static function fromTrait(int $days, string $matched, StorageLocation $location): self
    {
        return new self($days, 'trait', $matched, $location);
    }

    public static function fromCategory(int $days, string $matched, StorageLocation $location): self
    {
        return new self($days, 'category', $matched, $location);
    }

    public static function fromLocation(int $days, StorageLocation $location): self
    {
        return new self($days, 'location', null, $location);
    }

    public function until(?\DateTimeImmutable $from = null): \DateTimeImmutable
    {
        return ($from ?? new \DateTimeImmutable('today'))->modify(sprintf('+%d days', $this->days));
    }

    /** Phrase affichée sous la date : elle doit dire que c'est une estimation. */
    public function explanation(): string
    {
        $duration = $this->humanDuration();

        return match ($this->source) {
            'food' => sprintf('Environ %s, d\'après « %s » au %s.', $duration, $this->matched, $this->locationLabel()),
            'trait' => sprintf('Environ %s : produit %s.', $duration, $this->matched),
            'category' => sprintf('Environ %s, d\'après la catégorie « %s ».', $duration, $this->matched),
            default => sprintf('Environ %s, durée usuelle au %s.', $duration, $this->locationLabel()),
        };
    }

    private function locationLabel(): string
    {
        return mb_strtolower($this->location->label());
    }

    private function humanDuration(): string
    {
        return match (true) {
            $this->days <= 1 => '1 jour',
            $this->days < 14 => sprintf('%d jours', $this->days),
            $this->days < 60 => sprintf('%d semaines', (int) round($this->days / 7)),
            default => sprintf('%d mois', (int) round($this->days / 30)),
        };
    }
}
