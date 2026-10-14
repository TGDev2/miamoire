<?php

declare(strict_types=1);

namespace App\Service\Freshness;

/**
 * La date à laquelle un lot doit réellement être consommé — la plus proche de
 * la date imprimée/estimée et, si le lot est entamé, de sa date d'ouverture
 * majorée de la durée de conservation après ouverture.
 *
 * `basis` documente laquelle des deux a gagné : c'est ce qui permet à
 * l'affichage de dire « à consommer avant » plutôt que d'inventer une
 * précision que personne n'a jamais mesurée.
 */
final readonly class EffectiveUseBy
{
    public const BASIS_PRINTED = 'printed';
    public const BASIS_ESTIMATED = 'estimated';
    public const BASIS_AFTER_OPENING = 'after_opening';
    public const BASIS_UNKNOWN = 'unknown';
    /**
     * In the freezer: the date is a quality limit, not a safety one. Frozen
     * food stays safe; past this date it is only "best eaten soon".
     */
    public const BASIS_FROZEN = 'frozen';
    /** Out of the freezer: thawed food is eaten within 24 hours. */
    public const BASIS_THAWED = 'thawed';

    public function __construct(
        public ?\DateTimeImmutable $date,
        public string $basis,
        /** `null` : le lot n'est pas entamé, la question ne se pose pas. */
        public ?bool $afterOpeningDurationKnown,
    ) {
    }

    public function daysFromToday(): ?int
    {
        if (null === $this->date) {
            return null;
        }

        $today = new \DateTimeImmutable('today');

        return (int) $today->diff($this->date)->format('%r%a');
    }

    /** Never true in the freezer: a quality limit is not a deadline to beat. */
    public function isExpiringSoon(int $withinDays = 3): bool
    {
        $days = $this->daysFromToday();

        return !$this->isFrozen() && null !== $days && $days <= $withinDays;
    }

    /** Never true in the freezer: see {@see isPastBest()} for what replaces it there. */
    public function isExpired(): bool
    {
        $days = $this->daysFromToday();

        return !$this->isFrozen() && null !== $days && $days < 0;
    }

    /** A frozen lot past its freezer quality limit: still safe, best eaten soon. */
    public function isPastBest(): bool
    {
        $days = $this->daysFromToday();

        return $this->isFrozen() && null !== $days && $days < 0;
    }

    public function isFrozen(): bool
    {
        return self::BASIS_FROZEN === $this->basis;
    }
}
