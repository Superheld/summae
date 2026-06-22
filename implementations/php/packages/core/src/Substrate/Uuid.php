<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * UUIDv7 (RFC 9562): 48 bit Unix milliseconds + random — time-sortable
 * as a string, generatable independent of implementation
 * (datenformat.md principle 3). Fixtures never compare ID values,
 * only placeholder equality (determinismus.md §5).
 */
final readonly class Uuid implements \JsonSerializable, \Stringable
{
    private const string PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower($value);

        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new InvalidValue(sprintf('Not a valid UUID: "%s"', $value));
        }

        return new self($normalized);
    }

    public static function v7(Clock $clock = new SystemClock()): self
    {
        $milliseconds = (int) $clock->now()->format('Uv');
        $time = str_pad(dechex($milliseconds), 12, '0', STR_PAD_LEFT);
        $random = bin2hex(random_bytes(10)); // 20 hex chars of entropy

        // Variant nibble: top two bits = 10 -> 8, 9, a or b.
        $variant = dechex((hexdec($random[3]) & 0x3) | 0x8);

        return new self(sprintf(
            '%s-%s-7%s-%s%s-%s',
            substr($time, 0, 8),
            substr($time, 8, 4),
            substr($random, 0, 3),
            $variant,
            substr($random, 4, 3),
            substr($random, 7, 12),
        ));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /** Byte-wise = chronological order for v7. */
    public function compareTo(self $other): int
    {
        return strcmp($this->value, $other->value) <=> 0;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
