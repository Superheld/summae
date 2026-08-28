<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Assets\Asset;
use Summae\Core\Policies\Expansion\Assets\AssetRoute;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

final readonly class DatabaseAssetRepository implements AssetRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
        private Currency $currency,
    ) {
    }

    public function add(Asset $asset): void
    {
        $this->table()->insert([
            'id' => $asset->id->value,
            'tenant_id' => $this->tenantId->value,
            'payload' => Hydrator::encode($this->payload($asset)),
            'state' => Hydrator::encode($this->state($asset)),
        ]);
    }

    /**
     * The payload is written too, not only the state.
     *
     * It used not to be, and that was safe exactly as long as nothing in the payload could change —
     * master data does not, so `add` wrote it once and `save` only touched the history. An unplanned
     * write-down broke that: it rewrites the depreciation SCHEDULE, which lives in the payload, and a
     * database-backed tenant kept booking the old plan while the in-memory one booked the new. Same
     * input, two different sets of books, and only the run against a real adapter could see it.
     */
    public function save(Asset $asset): void
    {
        $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $asset->id->value)->update([
            'payload' => Hydrator::encode($this->payload($asset)),
            'state' => Hydrator::encode($this->state($asset)),
        ]);
    }

    public function byId(Uuid $id): ?Asset
    {
        $row = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $assets = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->orderBy('id')->get() as $row) {
            $assets[] = $this->hydrate($row);
        }

        return $assets;
    }

    /** @return array<string, mixed> */
    private function payload(Asset $asset): array
    {
        return $asset->jsonSerialize() + [
            'monthlySchedule' => array_map(
                static fn (Money $amount): array => $amount->jsonSerialize(),
                $asset->monthlySchedule,
            ),
            // Kept out of jsonSerialize() on purpose: the plan start is bookkeeping mechanics, not
            // part of the asset register an auditor reads. Losing it here would silently move a
            // pooled asset's plan back to its acquisition month after a restart.
            'depreciationStart' => $asset->depreciationStart?->iso,
            // Also mechanics, and also silently destructive if lost: after an unplanned write-down the
            // schedule IS the plan, and a restart that forgot this would go back to re-deriving the
            // plan from the acquisition cost — the very figure the write-down said is no longer valid.
            'scheduleRevised' => $asset->scheduleWasRevised(),
            'specialDepreciationBudget' => $asset->specialDepreciationBudget?->jsonSerialize(),
            'specialDepreciationWindowEnd' => $asset->specialDepreciationWindowEnd,
            'totalUnits' => $asset->totalUnits,
            'reportedUnits' => $asset->reportedUnits(),
        ];
    }

    /** @return array<string, mixed> */
    private function state(Asset $asset): array
    {
        return [
            'disposed' => $asset->isDisposed(),
            'disposedOn' => $asset->jsonSerialize()['disposedOn'],
            'accumulated' => $asset->accumulatedDepreciationAt(null)->jsonSerialize(),
            'depreciations' => $asset->depreciationsForPersistence(),
        ];
    }

    private function hydrate(object $row): Asset
    {
        /** @var object{id: string, payload: string, state: string} $row */
        $data = Hydrator::decode($row->payload);
        $state = Hydrator::decode($row->state);

        $schedule = [];
        foreach (is_array($data['monthlySchedule'] ?? null) ? $data['monthlySchedule'] : [] as $amount) {
            if (is_array($amount)) {
                /** @var array<string, mixed> $amount */
                $schedule[] = Hydrator::money($amount, $this->currency);
            }
        }

        $depreciations = [];
        foreach (is_array($state['depreciations'] ?? null) ? $state['depreciations'] : [] as $booking) {
            if (!is_array($booking)) {
                continue;
            }

            /** @var array<string, mixed> $bookingMoney */
            $bookingMoney = is_array($booking['amount'] ?? null) ? $booking['amount'] : [];

            $depreciations[] = [
                'planMonth' => is_int($booking['planMonth'] ?? null) ? $booking['planMonth'] : 0,
                'date' => Hydrator::date($booking['date'] ?? null) ?? throw new \RuntimeException('depreciation date missing'),
                'amount' => Hydrator::money($bookingMoney, $this->currency),
                'entryId' => Uuid::fromString(is_string($booking['entryId'] ?? null) ? $booking['entryId'] : ''),
                'kind' => is_string($booking['kind'] ?? null) ? $booking['kind'] : 'planned',
            ];
        }

        /** @var array<string, mixed> $cost */
        $cost = is_array($data['acquisitionCost'] ?? null) ? $data['acquisitionCost'] : [];

        return Asset::restore(
            Uuid::fromString($row->id),
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['assetClass'] ?? null) ? $data['assetClass'] : '',
            AccountNumber::of(is_string($data['assetAccount'] ?? null) ? $data['assetAccount'] : '0'),
            Hydrator::money($cost, $this->currency),
            Hydrator::date($data['acquiredOn'] ?? null) ?? throw new \RuntimeException('acquiredOn missing'),
            AssetRoute::from(is_string($data['route'] ?? null) ? $data['route'] : 'capitalize'),
            is_int($data['usefulLifeMonths'] ?? null) ? $data['usefulLifeMonths'] : null,
            $schedule,
            Uuid::fromString(is_string($data['voucherId'] ?? null) ? $data['voucherId'] : ''),
            $depreciations,
            ($state['disposed'] ?? false) === true,
            Hydrator::date($state['disposedOn'] ?? null),
            // IMPL-023: the asset's dimensions survive the round trip — every machine entry about it
            // reads them, so losing them here would make depreciation impossible after a restart
            // wherever a dimension is mandatory.
            self::dimensions($data['dimensions'] ?? null),
            Hydrator::date($data['depreciationStart'] ?? null),
            is_string($data['depreciationMethod'] ?? null) ? $data['depreciationMethod'] : null,
            ($data['scheduleRevised'] ?? false) === true,
            $this->optionalMoney($data['specialDepreciationBudget'] ?? null),
            is_int($data['specialDepreciationWindowEnd'] ?? null) ? $data['specialDepreciationWindowEnd'] : null,
            is_int($data['totalUnits'] ?? null) ? $data['totalUnits'] : null,
            is_int($data['reportedUnits'] ?? null) ? $data['reportedUnits'] : 0,
        );
    }

    private function optionalMoney(mixed $raw): ?Money
    {
        if (!is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        return Hydrator::money($raw, $this->currency);
    }

    /** @return list<array{type: string, code: string}> */
    private static function dimensions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $parsed = [];
        foreach ($raw as $item) {
            if (is_array($item) && is_string($item['type'] ?? null) && is_string($item['code'] ?? null)) {
                $parsed[] = ['type' => $item['type'], 'code' => $item['code']];
            }
        }

        return $parsed;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'assets');
    }
}
