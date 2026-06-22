<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Substrate\CalendarDate;

/**
 * Period — entity within the FiscalYear aggregate
 * (gapless, non-overlapping; status change only via the aggregate).
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

    /** Call only via FiscalYear (order check there). */
    public function close(): void
    {
        $this->status = PeriodStatus::Closed;
    }

    /** Call only via FiscalYear (year status check there). */
    public function reopen(): void
    {
        $this->status = PeriodStatus::Open;
    }
}
