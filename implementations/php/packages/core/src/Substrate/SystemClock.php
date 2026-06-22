<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
