<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\InMemory;

use Rechnungswesen\Core\Ledger\AuditRecord;
use Rechnungswesen\Core\Port\AuditTrail;

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
