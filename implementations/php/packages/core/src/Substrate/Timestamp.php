<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Kanonische Zeitstempel-Serialisierung (F-CROSS-001): RFC 3339 in **UTC** mit
 * fester Millisekunden-Stelle und `Z` — byte-identisch zu JavaScripts
 * `Date.toISOString()`. Damit `recordedAt`/`at`/`exportedAt` über alle
 * Implementierungen gleich aussehen (Determinismus, geteilte DB).
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
