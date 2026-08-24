<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Composition\TenantRecord;
use Summae\Core\Port\TenantRecordRepository;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * The tenant record (SPEC-015): identity plus the configuration five operations change.
 *
 * Scoped to one tenant like every repository here, so `load` needs no argument — the id it was
 * built with is the one it answers for. A `null` means "no such tenant in this store", which is the
 * distinction that did not exist before: an unknown id used to open an empty ledger that looked
 * exactly like a new one.
 */
final readonly class DatabaseTenantRecordRepository implements TenantRecordRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function load(): ?TenantRecord
    {
        $row = $this->table()->where('id', $this->tenantId->value)->first();
        if ($row === null) {
            return null;
        }

        $row = (array) $row;
        $config = Hydrator::decode($row['config'] ?? null);
        $packId = $row['pack_id'] ?? null;
        $packVersion = $row['pack_version'] ?? null;

        return new TenantRecord(
            is_string($row['id'] ?? null) ? $row['id'] : $this->tenantId->value,
            is_string($row['name'] ?? null) ? $row['name'] : '',
            is_string($row['base_currency'] ?? null) ? $row['base_currency'] : '',
            is_string($packId) && is_string($packVersion) ? ['id' => $packId, 'version' => $packVersion] : null,
            [
                'taxProfile' => self::block($config['taxProfile'] ?? null),
                'dimensionTypes' => self::typeList($config['dimensionTypes'] ?? null),
                'dimensionValues' => self::valueList($config['dimensionValues'] ?? null),
                'allocationScheme' => self::block($config['allocationScheme'] ?? null),
                'mappings' => self::mappingList($config['mappings'] ?? null),
            ],
        );
    }

    public function save(TenantRecord $record): void
    {
        $columns = [
            'name' => $record->name,
            'base_currency' => $record->baseCurrency,
            'pack_id' => $record->packIdentity === null ? null : $record->packIdentity['id'],
            'pack_version' => $record->packIdentity === null ? null : $record->packIdentity['version'],
            'config' => Hydrator::encode($record->config),
        ];

        if ($this->table()->where('id', $record->id)->exists()) {
            $this->table()->where('id', $record->id)->update($columns);

            return;
        }

        $this->table()->insert(['id' => $record->id] + $columns);
    }

    /**
     * Which tenants a store holds — the question no port answers, because a repository here speaks
     * for one tenant and this one is about the store.
     *
     * It is deliberately not a projection: a projection is computed on a tenant, and this has none
     * to run on. An embedding that manages several tenants needs it all the same, and until now had
     * to keep its own register beside the books and hope the two agreed.
     *
     * @return list<array{id: string, name: string, baseCurrency: string}>
     */
    public static function listTenants(ConnectionInterface $connection): array
    {
        $rows = $connection->table(SchemaInstaller::PREFIX . 'tenants')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $out[] = [
                'id' => is_string($row['id'] ?? null) ? $row['id'] : '',
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'baseCurrency' => is_string($row['base_currency'] ?? null) ? $row['base_currency'] : '',
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private static function block(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /** @return list<array{code: string}> */
    private static function typeList(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_array($entry) && is_string($entry['code'] ?? null)) {
                $out[] = ['code' => $entry['code']];
            }
        }

        return $out;
    }

    /** @return list<array{typeCode: string, code: string}> */
    private static function valueList(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_array($entry) && is_string($entry['typeCode'] ?? null) && is_string($entry['code'] ?? null)) {
                $out[] = ['typeCode' => $entry['typeCode'], 'code' => $entry['code']];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function mappingList(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = $entry;
            }
        }

        return $out;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'tenants');
    }
}
