<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

enum AccountStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
}
