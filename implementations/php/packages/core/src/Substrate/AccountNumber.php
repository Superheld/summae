<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * Account number as a string — leading zeros are significant (datenformat.md).
 * Comparison by Unicode code points, no locale collation:
 * "10" < "9" is intended, "0420" < "1200" < "8400" (determinismus.md §3).
 *
 * Byte-wise comparison of UTF-8 matches the code point order exactly.
 */
final readonly class AccountNumber implements \JsonSerializable, \Stringable
{
    private function __construct(
        public string $value,
    ) {
    }

    public static function of(string $value): self
    {
        if ($value === '' || strlen($value) > 64) {
            throw new InvalidValue('Account number must be 1-64 characters long');
        }

        if (preg_match('/^[^\s\p{C}]+$/u', $value) !== 1) {
            throw new InvalidValue(sprintf(
                'Account number contains whitespace or control characters: "%s"',
                $value,
            ));
        }

        return new self($value);
    }

    /** Unicode code point order (byte-wise on UTF-8). */
    public function compareTo(self $other): int
    {
        return strcmp($this->value, $other->value) <=> 0;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
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
