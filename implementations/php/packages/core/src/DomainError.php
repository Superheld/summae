<?php

declare(strict_types=1);

namespace Summae\Core;

/**
 * Domain error with catalog code (fehlerkatalog.md). Contract part:
 * same violation -> same code in all implementations.
 * `message` is free-form, `details` carries the IDs/values involved.
 */
final class DomainError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message = '',
        public readonly array $details = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    /**
     * Echoes a rejected input value back in `details` so the caller can spot their typo.
     *
     * Only strings and safe integers are rendered; everything else becomes null. That is the
     * same line canonical JSON draws (integers are exactly representable, floats are rejected
     * rather than serialized) — and it has to be drawn here too, because a plain cast is not
     * the same operation in both languages: PHP renders `true` as "1" and Node as "true",
     * PHP `1.0E+25` against Node `1e+25`. A detail that differs by language is a detail that
     * breaks equivalence, so it is better dropped than guessed at.
     */
    public static function rejectedValue(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        // Safe integers only — beyond 2^53 a JS number is no longer an exact integer, while
        // this side is still an int, and the two would render differently.
        if (is_int($value) && abs($value) <= 9007199254740991) {
            return (string) $value;
        }

        return null;
    }
}
