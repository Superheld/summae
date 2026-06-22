<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Records\AuditRecord;
use Summae\Core\Port\AuditTrail;

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
}
