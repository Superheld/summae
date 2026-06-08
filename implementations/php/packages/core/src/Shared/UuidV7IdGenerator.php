<?php

declare(strict_types=1);

namespace Summae\Core\Shared;

final readonly class UuidV7IdGenerator implements IdGenerator
{
    public function __construct(
        private Clock $clock = new SystemClock(),
    ) {
    }

    public function next(): Uuid
    {
        return Uuid::v7($this->clock);
    }
}
