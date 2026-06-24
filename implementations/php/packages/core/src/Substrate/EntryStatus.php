<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Record lifecycle: entered (correctable with audit) -> finalized
 * (immutable, afterwards only reversal).
 */
enum EntryStatus: string
{
    case Entered = 'entered';
    case Finalized = 'finalized';
}
