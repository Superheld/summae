<?php

declare(strict_types=1);

namespace Summae\Core\Shared;

/**
 * Feststehende Zeit für Tests und deterministische Läufe.
 */
final class FixedClock implements Clock
{
    public function __construct(
        private \DateTimeImmutable $now,
    ) {
    }

    public static function at(string $iso8601): self
    {
        return new self(new \DateTimeImmutable($iso8601));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceMilliseconds(int $milliseconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d milliseconds', $milliseconds));
    }
}
