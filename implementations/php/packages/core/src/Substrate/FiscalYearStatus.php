<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

enum FiscalYearStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
