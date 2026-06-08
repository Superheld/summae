<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

enum OpenItemKind: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}
