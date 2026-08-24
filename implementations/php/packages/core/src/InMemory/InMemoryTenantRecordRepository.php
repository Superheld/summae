<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Composition\TenantRecord;
use Summae\Core\Port\TenantRecordRepository;

/**
 * The tenant record, in memory — the fake behind TenantRecordRepository.
 *
 * It stores a copy rather than the object it was handed, so a caller that keeps mutating its own
 * record cannot change what "has been saved" retroactively. That matters here more than in the
 * other fakes: this is the repository whose whole point is that a change outlives the object that
 * made it, and a fake that shares the reference would prove nothing.
 */
final class InMemoryTenantRecordRepository implements TenantRecordRepository
{
    private ?TenantRecord $stored = null;

    public function load(): ?TenantRecord
    {
        return $this->stored === null ? null : self::copy($this->stored);
    }

    public function save(TenantRecord $record): void
    {
        $this->stored = self::copy($record);
    }

    private static function copy(TenantRecord $record): TenantRecord
    {
        return new TenantRecord(
            $record->id,
            $record->name,
            $record->baseCurrency,
            $record->packIdentity,
            $record->config,
        );
    }
}
