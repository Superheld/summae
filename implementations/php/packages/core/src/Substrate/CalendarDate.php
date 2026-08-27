<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\Exception\InvalidValue;

/**
 * Zoneless calendar date (determinismus.md §4): voucher date and
 * posting date know no time zone — no UTC shift risk.
 * ISO format sorts lexicographically correctly.
 */
final readonly class CalendarDate implements \JsonSerializable, \Stringable
{
    private function __construct(
        public string $iso,
    ) {
    }

    public static function of(string $iso): self
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $iso);

        if ($parsed === false || $parsed->format('Y-m-d') !== $iso) {
            throw new InvalidValue(sprintf('Not a valid calendar date: "%s"', $iso));
        }

        return new self($iso);
    }

    /**
     * Whole days from $other up to this date; negative when this date is earlier.
     * Zoneless subtraction — no hours, no DST, no leap seconds to lose a day to.
     */
    public function daysSince(self $other): int
    {
        return $this->dayNumber() - $other->dayNumber();
    }

    /**
     * Days since the civil epoch (1970-01-01), by Howard Hinnant's `days_from_civil`.
     * Hand-rolled so the Node twin can compute it identically without the host Date
     * (IMPL-009); proleptic Gregorian, which is what a zoneless bookkeeping date is.
     */
    private function dayNumber(): int
    {
        $year = (int) substr($this->iso, 0, 4);
        $month = (int) substr($this->iso, 5, 2);
        $day = (int) substr($this->iso, 8, 2);

        $y = $month <= 2 ? $year - 1 : $year;
        $era = (int) floor($y / 400);
        $yoe = $y - $era * 400;                                          // [0, 399]
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1; // [0, 365]
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;  // [0, 146096]

        return $era * 146097 + $doe - 719468;
    }

    public function compareTo(self $other): int
    {
        return strcmp($this->iso, $other->iso) <=> 0;
    }

    public function equals(self $other): bool
    {
        return $this->iso === $other->iso;
    }

    public function isBefore(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isBetween(self $start, self $end): bool
    {
        return !$this->isBefore($start) && !$this->isAfter($end);
    }

    public function year(): int
    {
        return (int) substr($this->iso, 0, 4);
    }

    public function month(): int
    {
        return (int) substr($this->iso, 5, 2);
    }

    /**
     * The same day-of-month `$months` later, clamped to the target month's last day (31 January plus
     * one month is 28 February, never 3 March). Zoneless like everything else here: this is calendar
     * arithmetic on three numbers, not a timestamp shifted by seconds.
     *
     * Written out rather than `->modify('+n months')`, which overflows exactly the way this must
     * not: PHP would answer 3 March, and the Node side would not.
     */
    public function plusMonths(int $months): self
    {
        $zeroBased = $this->year() * 12 + ($this->month() - 1) + $months;
        $year = intdiv($zeroBased, 12);
        $month = $zeroBased - $year * 12;
        if ($month < 0) {
            --$year;
            $month += 12;
        }
        ++$month;
        $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $day = min((int) substr($this->iso, 8, 2), $daysInMonth);

        return new self(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    public function lastDayOfMonth(): self
    {
        $date = new \DateTimeImmutable($this->iso);

        return new self($date->modify('last day of this month')->format('Y-m-d'));
    }

    public function firstDayOfNextMonth(): self
    {
        $date = new \DateTimeImmutable($this->iso);

        return new self($date->modify('first day of next month')->format('Y-m-d'));
    }

    public function jsonSerialize(): string
    {
        return $this->iso;
    }

    public function __toString(): string
    {
        return $this->iso;
    }
}
