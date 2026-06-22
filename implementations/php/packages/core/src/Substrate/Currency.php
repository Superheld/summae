<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * Currency per ISO 4217 with a fixed decimal-places scale
 * (datenformat.md: "fixed decimal places per currency").
 */
final readonly class Currency implements \JsonSerializable, \Stringable
{
    /**
     * Scales deviating from the default 2. Deliberately kept small —
     * v1 is EUR-centric, foreign currency comes only in v2.
     */
    private const array SCALES = [
        'JPY' => 0,
        'KRW' => 0,
        'BHD' => 3,
        'KWD' => 3,
        'TND' => 3,
    ];

    private function __construct(
        public string $code,
        public int $scale,
    ) {
    }

    /**
     * `$scaleOverride` sets the decimal-places scale explicitly (pack parameter
     * `packPolicy.currencyScale`) — overrides the global default/ISO scale per
     * tenant (scale is a pack matter, not global).
     */
    public static function of(string $code, ?int $scaleOverride = null): self
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidValue(sprintf('Invalid ISO 4217 code: "%s"', $code));
        }

        return new self($code, $scaleOverride ?? self::SCALES[$code] ?? 2);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }

    public function jsonSerialize(): string
    {
        return $this->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
