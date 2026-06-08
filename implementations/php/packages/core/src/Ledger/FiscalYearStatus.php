<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

enum FiscalYearStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
