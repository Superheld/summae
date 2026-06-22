<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * Reference to a period: fiscal year + period number
 * (datenformat.md `periodRef`). `fiscalYear` = calendar year of the fiscal year end.
 */
final readonly class PeriodRef implements \JsonSerializable
{
    public function __construct(
        public int $fiscalYear,
        public int $period,
    ) {
        if ($fiscalYear < 1 || $fiscalYear > 9999) {
            throw new InvalidValue(sprintf('Invalid fiscal year: %d', $fiscalYear));
        }

        if ($period < 1 || $period > 999) {
            throw new InvalidValue(sprintf('Invalid period number: %d', $period));
        }
    }

    /** Chronological: year first, then period. */
    public function compareTo(self $other): int
    {
        return [$this->fiscalYear, $this->period] <=> [$other->fiscalYear, $other->period];
    }

    public function equals(self $other): bool
    {
        return $this->fiscalYear === $other->fiscalYear && $this->period === $other->period;
    }

    /** @return array{fiscalYear: int, period: int} */
    public function jsonSerialize(): array
    {
        return [
            'fiscalYear' => $this->fiscalYear,
            'period' => $this->period,
        ];
    }
}
