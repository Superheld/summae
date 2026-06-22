<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

enum OpenItemKind: string
{
    case Receivable = 'receivable';
    case Payable = 'payable';
}
