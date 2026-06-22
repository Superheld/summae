<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

final readonly class DatabaseAuditTrail implements AuditTrail
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function append(AuditRecord $record): void
    {
        $data = $record->jsonSerialize();
        $data['changes'] = $data['changes'] instanceof \stdClass ? [] : $data['changes'];

        $this->table()->insert([
            'id' => $record->id->value,
            'tenant_id' => $this->tenantId->value,
            'payload' => Hydrator::encode($data),
        ]);
    }

    public function all(): array
    {
        $records = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->orderBy('seq')->get() as $row) {
            /** @var object{payload: string} $row */
            $data = Hydrator::decode($row->payload);

            /** @var array<string, array{from: mixed, to: mixed}> $changes */
            $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];

            $records[] = new AuditRecord(
                Uuid::fromString(is_string($data['id'] ?? null) ? $data['id'] : ''),
                new \DateTimeImmutable(is_string($data['at'] ?? null) ? $data['at'] : 'now'),
                is_string($data['actor'] ?? null) ? $data['actor'] : 'system',
                is_string($data['objectType'] ?? null) ? $data['objectType'] : '',
                Uuid::fromString(is_string($data['objectId'] ?? null) ? $data['objectId'] : ''),
                is_string($data['action'] ?? null) ? $data['action'] : '',
                $changes,
            );
        }

        return $records;
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'audit_log');
    }
}
