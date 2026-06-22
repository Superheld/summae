<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

enum PeriodStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
