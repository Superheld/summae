<?php

declare(strict_types=1);

namespace Rechnungswesen\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Rechnungswesen\Core\Ledger\FiscalYear;
use Rechnungswesen\Core\Ledger\FiscalYearStatus;
use Rechnungswesen\Core\Ledger\Period;
use Rechnungswesen\Core\Ledger\PeriodStatus;
use Rechnungswesen\Core\Port\FiscalYearRepository;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Uuid;
use Rechnungswesen\Laravel\Schema\SchemaInstaller;

final readonly class EloquentFiscalYearRepository implements FiscalYearRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function add(FiscalYear $fiscalYear): void
    {
        $this->table()->insert([
            'id' => $fiscalYear->id->value,
            'tenant_id' => $this->tenantId->value,
            'year' => $fiscalYear->year,
            'start' => $fiscalYear->start->iso,
            'end' => $fiscalYear->end->iso,
            'status' => $fiscalYear->status()->value,
            'periods' => $this->encodePeriods($fiscalYear),
        ]);
    }

    public function save(FiscalYear $fiscalYear): void
    {
        $this->table()->where('id', $fiscalYear->id->value)->update([
            'status' => $fiscalYear->status()->value,
            'periods' => $this->encodePeriods($fiscalYear),
        ]);
    }

    public function byYear(int $year): ?FiscalYear
    {
        $row = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('year', $year)
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function forDate(CalendarDate $date): ?FiscalYear
    {
        foreach ($this->all() as $fiscalYear) {
            if ($fiscalYear->contains($date)) {
                return $fiscalYear;
            }
        }

        return null;
    }

    public function all(): array
    {
        $years = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->orderBy('year')->get() as $row) {
            $years[] = $this->hydrate($row);
        }

        return $years;
    }

    private function encodePeriods(FiscalYear $fiscalYear): string
    {
        return Hydrator::encode(array_map(
            static fn (Period $period): array => [
                'period' => $period->number,
                'start' => $period->start->iso,
                'end' => $period->end->iso,
                'status' => $period->status()->value,
            ],
            $fiscalYear->periods(),
        ));
    }

    private function hydrate(object $row): FiscalYear
    {
        /** @var object{id: string, year: int|string, start: string, end: string, status: string, periods: string} $row */
        $periods = [];

        foreach (Hydrator::decodeList($row->periods) as $periodData) {
            $periods[] = new Period(
                is_numeric($periodData['period'] ?? null) ? (int) $periodData['period'] : 0,
                Hydrator::date($periodData['start'] ?? null) ?? throw new \RuntimeException('Periodenstart fehlt'),
                Hydrator::date($periodData['end'] ?? null) ?? throw new \RuntimeException('Periodenende fehlt'),
                PeriodStatus::from(is_string($periodData['status'] ?? null) ? $periodData['status'] : 'open'),
            );
        }

        return FiscalYear::restore(
            Uuid::fromString($row->id),
            (int) $row->year,
            Hydrator::date($row->start) ?? throw new \RuntimeException('start fehlt'),
            Hydrator::date($row->end) ?? throw new \RuntimeException('end fehlt'),
            FiscalYearStatus::from($row->status),
            $periods,
        );
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'fiscal_years');
    }
}
