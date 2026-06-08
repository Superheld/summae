<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
