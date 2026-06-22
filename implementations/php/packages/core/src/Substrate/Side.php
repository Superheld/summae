<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

enum Side: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
