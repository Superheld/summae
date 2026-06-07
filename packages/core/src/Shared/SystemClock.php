<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Shared;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
