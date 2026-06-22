<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

enum AccountStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
}
