<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Unit;

class IncompatibleUnitException extends \DomainException
{
    public function __construct(
        public readonly Unit $from,
        public readonly Unit $to,
    ) {
        parent::__construct(sprintf(
            'Conversion impossible de « %s » vers « %s » : ces unités ne sont pas de la même famille.',
            $from->label(),
            $to->label(),
        ));
    }
}
