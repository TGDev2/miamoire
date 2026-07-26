<?php

declare(strict_types=1);

namespace App\Enum;

/** Cycle de vie d'une opération de stock identifiable. */
enum StockOperationStatus: string
{
    /** Réservée, transaction en cours. Un rejeu concurrent doit être refusé, pas dupliqué. */
    case Pending = 'pending';

    /** Terminée : la réponse figée est rejouable à l'identique, sans seconde écriture. */
    case Succeeded = 'succeeded';

    /** Échouée : aucune écriture n'a été conservée, la même clé peut être retentée. */
    case Failed = 'failed';
}
