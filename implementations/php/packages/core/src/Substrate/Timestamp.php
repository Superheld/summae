<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Canonical timestamp serialization (F-CROSS-001): RFC 3339 in **UTC** with
 * fixed millisecond place and `Z` — byte-identical to JavaScript's
 * `Date.toISOString()`. So that `recordedAt`/`at`/`exportedAt` look the same
 * across all implementations (determinism, shared DB).
 */
final class Timestamp
{
    private function __construct()
    {
    }

    public static function canonical(\DateTimeInterface $instant): string
    {
        return \DateTimeImmutable::createFromInterface($instant)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
