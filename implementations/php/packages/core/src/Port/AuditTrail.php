<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Records\AuditRecord;

/**
 * Audit-Trail ist Formatbestandteil (datenformat.md v0.3, Review G3):
 * der ursprüngliche Inhalt bleibt über die Aufbewahrungsdauer
 * feststellbar — append-only, wird bei Migration vollständig übernommen.
 */
interface AuditTrail
{
    public function append(AuditRecord $record): void;

    /** @return list<AuditRecord> in Erfassungsreihenfolge */
    public function all(): array;
}
