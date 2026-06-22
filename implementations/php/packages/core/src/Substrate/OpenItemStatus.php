<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

enum OpenItemStatus: string
{
    case Open = 'open';
    case PartiallySettled = 'partially_settled';
    case Settled = 'settled';
}
