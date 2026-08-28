<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Records\AuditFilter;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Substrate\Uuid;

final class InMemoryAuditTrail implements AuditTrail
{
    /** @var list<AuditRecord> */
    private array $records = [];

    public function append(AuditRecord $record): void
    {
        $this->records[] = $record;
    }

    public function all(): array
    {
        return $this->records;
    }

    public function find(array $criteria): array
    {
        return AuditFilter::apply($this->records, $criteria);
    }

    public function eraseFor(string $objectType, Uuid $objectId): int
    {
        $before = count($this->records);
        $this->records = array_values(array_filter(
            $this->records,
            static fn (AuditRecord $record): bool => $record->objectType !== $objectType
                || $record->objectId->value !== $objectId->value,
        ));

        return $before - count($this->records);
    }
}
