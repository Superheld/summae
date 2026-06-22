<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Policies\Expansion\Assets\Asset;
use Summae\Core\Policies\Expansion\Assets\AssetRoute;
use Summae\Core\Port\AssetRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Money;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

final readonly class DatabaseAssetRepository implements AssetRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
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

    public function save(Asset $asset): void
    {
        $this->table()->where('id', $asset->id->value)->update([
            'state' => Hydrator::encode($this->state($asset)),
        ]);
    }

    public function byId(Uuid $id): ?Asset
    {
        $row = $this->table()->where('id', $id->value)->first();

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
                $schedule[] = Hydrator::money($amount);
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
                'date' => Hydrator::date($booking['date'] ?? null) ?? throw new \RuntimeException('AfA-Datum fehlt'),
                'amount' => Hydrator::money($bookingMoney),
                'entryId' => Uuid::fromString(is_string($booking['entryId'] ?? null) ? $booking['entryId'] : ''),
            ];
        }

        /** @var array<string, mixed> $cost */
        $cost = is_array($data['acquisitionCost'] ?? null) ? $data['acquisitionCost'] : [];

        return Asset::restore(
            Uuid::fromString($row->id),
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['assetClass'] ?? null) ? $data['assetClass'] : '',
            AccountNumber::of(is_string($data['assetAccount'] ?? null) ? $data['assetAccount'] : '0'),
            Hydrator::money($cost),
            Hydrator::date($data['acquiredOn'] ?? null) ?? throw new \RuntimeException('acquiredOn fehlt'),
            AssetRoute::from(is_string($data['route'] ?? null) ? $data['route'] : 'capitalize'),
            is_int($data['usefulLifeMonths'] ?? null) ? $data['usefulLifeMonths'] : null,
            $schedule,
            Uuid::fromString(is_string($data['voucherId'] ?? null) ? $data['voucherId'] : ''),
            $depreciations,
            ($state['disposed'] ?? false) === true,
            Hydrator::date($state['disposedOn'] ?? null),
        );
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'assets');
    }
}
