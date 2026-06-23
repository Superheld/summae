<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\Uuid;

/**
 * Fiscal year with periods (ledger-modell.md aggregate 3).
 * Invariants: periods gapless and non-overlapping; closing only
 * in order; reopening only before year-end closing.
 * `year` = calendar year of the fiscal year end (datenformat.md v0.3).
 */
final class FiscalYear
{
    /**
     * @param list<Period> $periods
     */
    private function __construct(
        public readonly Uuid $id,
        public readonly int $year,
        public readonly CalendarDate $start,
        public readonly CalendarDate $end,
        private array $periods,
        private FiscalYearStatus $status = FiscalYearStatus::Open,
    ) {
    }

    /**
     * @param list<array{period: int, start: CalendarDate, end: CalendarDate}>|null $explicitPeriods
     */
    public static function create(
        Uuid $id,
        int $year,
        CalendarDate $start,
        CalendarDate $end,
        ?array $explicitPeriods = null,
    ): self {
        if (!$start->isBefore($end)) {
            throw new InvalidValue('Fiscal year: start must be before end');
        }

        $periods = $explicitPeriods === null
            ? self::monthlyPeriods($start, $end)
            : array_map(
                static fn (array $definition): Period => new Period(
                    $definition['period'],
                    $definition['start'],
                    $definition['end'],
                ),
                $explicitPeriods,
            );

        return new self($id, $year, $start, $end, $periods);
    }

    /**
     * Rehydration from persistence (adapter): status is preserved.
     *
     * @param list<Period> $periods
     */
    public static function restore(
        Uuid $id,
        int $year,
        CalendarDate $start,
        CalendarDate $end,
        FiscalYearStatus $status,
        array $periods,
    ): self {
        return new self($id, $year, $start, $end, $periods, $status);
    }

    /** @return list<Period> */
    private static function monthlyPeriods(CalendarDate $start, CalendarDate $end): array
    {
        $periods = [];
        $cursor = $start;
        $number = 1;

        while (!$cursor->isAfter($end)) {
            $monthEnd = $cursor->lastDayOfMonth();
            $periodEnd = $monthEnd->isAfter($end) ? $end : $monthEnd;
            $periods[] = new Period($number, $cursor, $periodEnd);
            $cursor = $cursor->firstDayOfNextMonth();
            $number++;
        }

        return $periods;
    }

    public function status(): FiscalYearStatus
    {
        return $this->status;
    }

    public function isClosed(): bool
    {
        return $this->status === FiscalYearStatus::Closed;
    }

    /** @return list<Period> */
    public function periods(): array
    {
        return $this->periods;
    }

    public function period(int $number): Period
    {
        foreach ($this->periods as $period) {
            if ($period->number === $number) {
                return $period;
            }
        }

        throw new DomainError('E_PERIOD_UNKNOWN', sprintf(
            'Period %d does not exist in fiscal year %d',
            $number,
            $this->year,
        ), ['fiscalYear' => $this->year, 'period' => $number]);
    }

    public function contains(CalendarDate $date): bool
    {
        return $date->isBetween($this->start, $this->end);
    }

    public function periodForDate(CalendarDate $date): Period
    {
        foreach ($this->periods as $period) {
            if ($period->contains($date)) {
                return $period;
            }
        }

        throw new DomainError('E_PERIOD_UNKNOWN', sprintf(
            'No period range for %s in fiscal year %d',
            $date->iso,
            $this->year,
        ), ['date' => $date->iso, 'fiscalYear' => $this->year]);
    }

    public function closePeriod(int $number): Period
    {
        $this->assertNotClosed();
        $target = $this->period($number);

        foreach ($this->periods as $period) {
            if ($period->number < $number && $period->isOpen()) {
                throw new DomainError('E_PERIOD_OUT_OF_ORDER', sprintf(
                    'Period %d cannot be closed: period %d is still open',
                    $number,
                    $period->number,
                ), ['fiscalYear' => $this->year, 'period' => $number, 'openPeriod' => $period->number]);
            }
        }

        $target->close();

        return $target;
    }

    public function reopenPeriod(int $number): Period
    {
        $this->assertNotClosed();
        $target = $this->period($number);
        $target->reopen();

        return $target;
    }

    /** Pure status change — no business posting effect (api.md v0.3). */
    public function close(): void
    {
        foreach ($this->periods as $period) {
            if ($period->isOpen()) {
                throw new DomainError('E_PERIOD_OUT_OF_ORDER', sprintf(
                    'Year-end closing %d: period %d is still open',
                    $this->year,
                    $period->number,
                ), ['fiscalYear' => $this->year, 'openPeriod' => $period->number]);
            }
        }

        $this->status = FiscalYearStatus::Closed;
    }

    private function assertNotClosed(): void
    {
        if ($this->isClosed()) {
            throw new DomainError('E_FISCALYEAR_CLOSED', sprintf(
                'Fiscal year %d is closed',
                $this->year,
            ), ['fiscalYear' => $this->year]);
        }
    }
}
