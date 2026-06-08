<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Ledger\FiscalYear;
use Summae\Core\Shared\CalendarDate;

interface FiscalYearRepository
{
    public function add(FiscalYear $fiscalYear): void;

    public function save(FiscalYear $fiscalYear): void;

    public function byYear(int $year): ?FiscalYear;

    public function forDate(CalendarDate $date): ?FiscalYear;

    /** @return list<FiscalYear> sortiert nach Jahr */
    public function all(): array;
}
