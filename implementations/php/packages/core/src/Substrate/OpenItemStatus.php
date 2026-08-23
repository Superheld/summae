<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

enum OpenItemStatus: string
{
    case Open = 'open';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
    /** Closed by a reversal of the origin entry, not by payment (IMPL-008). */
    case Cancelled = 'cancelled';
}
