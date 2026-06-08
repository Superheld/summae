<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

enum OpenItemKind: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}
