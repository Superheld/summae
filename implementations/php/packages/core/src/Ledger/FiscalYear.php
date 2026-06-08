<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

use Rechnungswesen\Core\DomainError;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Exception\InvalidValue;
use Rechnungswesen\Core\Shared\Uuid;

/**
 * Geschäftsjahr mit Perioden (ledger-modell.md Aggregat 3).
 * Invarianten: Perioden lückenlos und überlappungsfrei; Schließen nur
 * in Reihenfolge; Wiedereröffnen nur vor Jahresabschluss.
 * `year` = Kalenderjahr des GJ-Endes (§ 4a EStG, datenformat.md v0.3).
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
            throw new InvalidValue('Geschäftsjahr: start muss vor end liegen');
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
     * Rehydrierung aus Persistenz (Adapter): Status bleibt erhalten.
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
            'Periode %d existiert nicht im Geschäftsjahr %d',
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
            'Kein Periodenzeitraum für %s im Geschäftsjahr %d',
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
                    'Periode %d kann nicht geschlossen werden: Periode %d ist noch offen',
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

    /** Reiner Statuswechsel — keine fachliche Buchungswirkung (api.md v0.3). */
    public function close(): void
    {
        foreach ($this->periods as $period) {
            if ($period->isOpen()) {
                throw new DomainError('E_PERIOD_OUT_OF_ORDER', sprintf(
                    'Jahresabschluss %d: Periode %d ist noch offen',
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
                'Geschäftsjahr %d ist abgeschlossen',
                $this->year,
            ), ['fiscalYear' => $this->year]);
        }
    }
}
