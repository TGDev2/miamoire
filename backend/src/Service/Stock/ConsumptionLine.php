<?php

declare(strict_types=1);

namespace App\Service\Stock;

use App\Enum\Unit;

/** Une ligne de proposition, validée et consolidée : « 400 g de tomate-conserve ». */
final readonly class ConsumptionLine
{
    public function __construct(
        /** Le slug de variante recopié par le modèle. */
        public string $key,
        public string $label,
        public float $quantity,
        public Unit $unit,
    ) {
    }

    /** Forme canonique servant à l'empreinte d'idempotence. */
    public function signature(): string
    {
        return sprintf('%s:%s:%s', $this->key, number_format($this->quantity, 3, '.', ''), $this->unit->value);
    }
}
