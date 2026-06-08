<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
