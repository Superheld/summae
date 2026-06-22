<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * UUIDv7-shaped IDs from a fixed clock + counter instead of random — for tests
 * and the double-run determinism check of the conformance suite
 * (stream hashes contain IDs; two runs must be byte-identical).
 * Production uses UuidV7IdGenerator.
 */
final class DeterministicIdGenerator implements IdGenerator
{
    private int $counter = 0;

    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function next(): Uuid
    {
        $this->counter++;

        $milliseconds = (int) $this->clock->now()->format('Uv');
        $time = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);
        $sequence = str_pad(dechex($this->counter), 18, '0', STR_PAD_LEFT);

        return Uuid::fromString(sprintf(
            '%s-%s-7%s-8%s-%s',
            substr($time, 0, 8),
            substr($time, 8, 4),
            substr($sequence, 0, 3),
            substr($sequence, 3, 3),
            substr($sequence, 6, 12),
        ));
    }
}
