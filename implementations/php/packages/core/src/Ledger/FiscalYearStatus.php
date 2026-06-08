<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

enum FiscalYearStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
