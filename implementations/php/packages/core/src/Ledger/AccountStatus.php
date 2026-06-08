<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

enum AccountStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
}
