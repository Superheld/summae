<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

enum OpenItemStatus: string
{
    case Open = 'open';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
}
