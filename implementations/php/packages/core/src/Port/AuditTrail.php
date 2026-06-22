<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Records\AuditRecord;

/**
 * The audit trail is part of the format (datenformat.md v0.3, review G3):
 * the original content stays determinable over the retention period
 * — append-only, fully carried over on migration.
 */
interface AuditTrail
{
    public function append(AuditRecord $record): void;

    /** @return list<AuditRecord> in capture order */
    public function all(): array;
}
