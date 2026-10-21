<?php

declare(strict_types=1);

namespace App\Service\GroceryReturn;

use App\Service\TextNormalizer;

/**
 * The key under which a shop name or a printed receipt label is remembered:
 * lower-case ASCII words, so that "LAIT 1/2 ECR 6X1L" and "Lait 1/2 écr. 6x1L"
 * are the same label. One rule, used by the reader and by the confirmation.
 */
final class ReceiptAliasKey
{
    public function __construct(private readonly TextNormalizer $normalizer)
    {
    }

    public function of(?string $value): string
    {
        return mb_substr(trim($this->normalizer->toWords((string) $value)), 0, 255);
    }
}
