<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * Kontonummer als String — führende Nullen sind signifikant (datenformat.md).
 * Vergleich nach Unicode-Codepoints, keine Locale-Collation:
 * "10" < "9" ist gewollt, "0420" < "1200" < "8400" (determinismus.md §3).
 *
 * Byteweiser Vergleich von UTF-8 entspricht exakt der Codepoint-Ordnung.
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
            throw new InvalidValue('Kontonummer muss 1-64 Zeichen lang sein');
        }

        if (preg_match('/^[^\s\p{C}]+$/u', $value) !== 1) {
            throw new InvalidValue(sprintf(
                'Kontonummer enthält Whitespace oder Steuerzeichen: "%s"',
                $value,
            ));
        }

        return new self($value);
    }

    /** Unicode-Codepoint-Ordnung (byteweise auf UTF-8). */
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
