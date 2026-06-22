<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\CalendarDate;

/**
 * Periode — Entity innerhalb des FiscalYear-Aggregats
 * (lückenlos, überlappungsfrei; Statuswechsel nur über das Aggregat).
 */
final class Period
{
    public function __construct(
        public readonly int $number,
        public readonly CalendarDate $start,
        public readonly CalendarDate $end,
        private PeriodStatus $status = PeriodStatus::Open,
    ) {
    }

    public function status(): PeriodStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }

    public function contains(CalendarDate $date): bool
    {
        return $date->isBetween($this->start, $this->end);
    }

    /** Nur über FiscalYear aufrufen (Reihenfolgeprüfung dort). */
    public function close(): void
    {
        $this->status = PeriodStatus::Closed;
    }

    /** Nur über FiscalYear aufrufen (Jahresstatusprüfung dort). */
    public function reopen(): void
    {
        $this->status = PeriodStatus::Open;
    }
}
