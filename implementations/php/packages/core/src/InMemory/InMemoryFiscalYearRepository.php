<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Ledger\FiscalYear;
use Summae\Core\Port\FiscalYearRepository;
use Summae\Core\Shared\CalendarDate;

final class InMemoryFiscalYearRepository implements FiscalYearRepository
{
    /** @var array<int, FiscalYear> */
    private array $byYear = [];

    public function add(FiscalYear $fiscalYear): void
    {
        $this->byYear[$fiscalYear->year] = $fiscalYear;
    }

    public function save(FiscalYear $fiscalYear): void
    {
    }

    public function byYear(int $year): ?FiscalYear
    {
        return $this->byYear[$year] ?? null;
    }

    public function forDate(CalendarDate $date): ?FiscalYear
    {
        foreach ($this->byYear as $fiscalYear) {
            if ($fiscalYear->contains($date)) {
                return $fiscalYear;
            }
        }

        return null;
    }

    public function all(): array
    {
        $years = array_values($this->byYear);
        usort($years, static fn (FiscalYear $a, FiscalYear $b): int => $a->year <=> $b->year);

        return $years;
    }
}
