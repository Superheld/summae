<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Inventory\InventoryValuation;
use Summae\Core\Port\InventoryValuationRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Inventory valuations — payload as JSON, one row per act (F-CORE-050).
 *
 * Period and version are columns rather than payload fields for the reason the costing runs give:
 * they are what a valuation is *found* by, and the next version of a period comes out of the store.
 * There is no `save` — a valuation is never edited. Repeating one produces the next version, whose
 * posting is the difference against what the books by then already carry.
 */
final readonly class DatabaseInventoryValuationRepository implements InventoryValuationRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
        private Currency $currency,
    ) {
    }

    public function add(InventoryValuation $valuation): void
    {
        $this->table()->insert([
            'id' => $valuation->id->value,
            'tenant_id' => $this->tenantId->value,
            'fiscal_year' => $valuation->period->fiscalYear,
            'period' => $valuation->period->period,
            'version' => $valuation->version,
            'payload' => Hydrator::encode($valuation->jsonSerialize()),
        ]);
    }

    public function byId(Uuid $id): ?InventoryValuation
    {
        $row = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $valuations = [];

        foreach (
            $this->table()
                ->where('tenant_id', $this->tenantId->value)
                ->orderBy('fiscal_year')->orderBy('period')->orderBy('version')->get() as $row
        ) {
            $valuations[] = $this->hydrate($row);
        }

        return $valuations;
    }

    private function hydrate(object $row): InventoryValuation
    {
        /** @var object{id: string, fiscal_year: int, period: int, version: int, payload: string} $row */
        $data = Hydrator::decode($row->payload);

        $runId = $data['runId'] ?? null;
        $entryId = $data['entryId'] ?? null;
        $valuationDate = $data['valuationDate'] ?? null;

        return InventoryValuation::restore(
            Uuid::fromString($row->id),
            new PeriodRef((int) $row->fiscal_year, (int) $row->period),
            (int) $row->version,
            CalendarDate::of(is_string($valuationDate) ? $valuationDate : '1970-01-01'),
            is_string($runId) ? Uuid::fromString($runId) : null,
            /** @phpstan-ignore-next-line shape restored from the payload it was written from */
            is_array($data['categories'] ?? null) ? array_values($data['categories']) : [],
            $this->money($data['closingTotal'] ?? null),
            $this->money($data['change'] ?? null),
            is_string($entryId) ? Uuid::fromString($entryId) : null,
        );
    }

    private function money(mixed $raw): Money
    {
        if (!is_array($raw)) {
            return Money::zero($this->currency);
        }

        /** @var array<string, mixed> $raw */
        return Hydrator::money($raw, $this->currency);
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'inventory_valuations');
    }
}
