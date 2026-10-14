<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\HouseholdProductVariant;

/**
 * A correction would make a food identical to one the household already has.
 *
 * Two stocks are never merged silently: the API refuses with a code the app
 * recognises, and the app asks whether to merge. Only a cut correction can
 * then be resent with `mergeIfExists` — see VariantResolver::changeCut().
 */
class VariantCollisionException extends StockException
{
    public function __construct(public readonly HouseholdProductVariant $existing)
    {
        parent::__construct(sprintf(
            'Vous avez déjà « %s » dans votre inventaire : ce serait le même aliment.',
            $existing->getCulinaryLabel(),
        ));
    }
}
