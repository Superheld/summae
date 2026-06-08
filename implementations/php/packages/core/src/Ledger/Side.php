<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
