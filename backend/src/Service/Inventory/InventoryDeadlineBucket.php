<?php

declare(strict_types=1);

namespace App\Service\Inventory;

/**
 * Une part de la quantité d'un ingrédient qui partage la même échéance
 * effective — « 250 g entamés à utiliser demain », « 1 kg scellé plus tard ».
 *
 * Sans ce découpage, deux lots aux échéances très différentes se noyaient dans
 * un seul total assorti d'un booléen global : 1,25 kg marqué « urgent » alors
 * que 1 kg de ce total ne l'était pas du tout.
 */
final readonly class InventoryDeadlineBucket
{
    public function __construct(
        public float $quantity,
        public ?\DateTimeImmutable $useBy,
        public bool $useByIsEstimated,
        /** Au moins un exemplaire de ce groupe est entamé (ouvert ou refermé). */
        public bool $opened,
        public bool $expiresSoon,
    ) {
    }

    /** @return array<string, mixed> */
    public function toAiPayload(): array
    {
        $payload = ['quantity' => $this->quantity];

        if (null !== $this->useBy) {
            $payload['useBy'] = $this->useBy->format('Y-m-d');
        }

        if ($this->opened) {
            $payload['opened'] = true;
        }

        if ($this->expiresSoon) {
            $payload['expiresSoon'] = true;
        }

        return $payload;
    }
}
