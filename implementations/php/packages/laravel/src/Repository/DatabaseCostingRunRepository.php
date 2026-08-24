<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Costing\CostingRun;
use Summae\Core\Port\CostingRunRepository;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Costing runs — payload as JSON, one row per run (F-KLR-001/004).
 *
 * The table the library did not have. A released run is what the requirements say the BAB and the
 * rates are a projection *of*, and it used to live in an array inside the service: gone with the
 * process, and the version counter restarted with it. Everything the three projections read is in
 * the payload, frozen at release — a released run that answers differently tomorrow is not
 * released.
 */
final readonly class DatabaseCostingRunRepository implements CostingRunRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function add(CostingRun $run): void
    {
        $this->table()->insert([
            'id' => $run->id->value,
            'tenant_id' => $this->tenantId->value,
            'fiscal_year' => $run->period->fiscalYear,
            'period' => $run->period->period,
            'version' => $run->version,
            'status' => $run->status(),
            'payload' => Hydrator::encode($run->jsonSerialize()),
        ]);
    }

    public function save(CostingRun $run): void
    {
        $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $run->id->value)->update([
                'status' => $run->status(),
                'payload' => Hydrator::encode($run->jsonSerialize()),
            ]);
    }

    public function byId(Uuid $id): ?CostingRun
    {
        $row = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $runs = [];

        foreach (
            $this->table()
                ->where('tenant_id', $this->tenantId->value)
                ->orderBy('fiscal_year')->orderBy('period')->orderBy('version')->get() as $row
        ) {
            $runs[] = $this->hydrate($row);
        }

        return $runs;
    }

    private function hydrate(object $row): CostingRun
    {
        /** @var object{id: string, fiscal_year: int, period: int, version: int, status: string, payload: string} $row */
        $data = Hydrator::decode($row->payload);

        return CostingRun::restore(
            Uuid::fromString($row->id),
            new PeriodRef((int) $row->fiscal_year, (int) $row->period),
            (int) $row->version,
            (string) $row->status,
            $this->totals($data['primary'] ?? null),
            $this->totals($data['afterAllocation'] ?? null),
            $this->grandTotal($data),
            is_string($data['method'] ?? null) ? $data['method'] : 'step_ladder',
            /** @phpstan-ignore-next-line shape restored from the payload it was written from */
            is_array($data['rates'] ?? null) ? array_values($data['rates']) : [],
            /** @phpstan-ignore-next-line shape restored from the payload it was written from */
            is_array($data['rateWarnings'] ?? null) ? array_values($data['rateWarnings']) : [],
            /** @phpstan-ignore-next-line shape restored from the payload it was written from */
            is_array($data['productionCost'] ?? null) ? $data['productionCost'] : null,
        );
    }

    /** @param array<string, mixed> $data */
    private function grandTotal(array $data): Money
    {
        $raw = $data['grandTotal'] ?? null;
        if (!is_array($raw)) {
            return Money::zero(Currency::of('EUR'));
        }

        /** @var array<string, mixed> $raw */
        return Hydrator::money($raw);
    }

    /**
     * @return array<string, Money>
     */
    private function totals(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $code => $value) {
            if (is_string($code) && is_array($value)) {
                /** @var array<string, mixed> $value */
                $out[$code] = Hydrator::money($value);
            }
        }

        return $out;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'costing_runs');
    }
}
