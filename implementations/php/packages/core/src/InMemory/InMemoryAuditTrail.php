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
        // The head is the last record's hash — a redacted shell keeps its own, so an erasure does
        // not move the chain's tip and every later link still resolves.
        $last = $this->records === [] ? null : $this->records[count($this->records) - 1];
        $this->records[] = $record->chainedTo($last?->recordHash);
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
        $erased = 0;
        foreach ($this->records as $i => $record) {
            if ($record->objectType !== $objectType || $record->objectId->value !== $objectId->value) {
                continue;
            }
            // Replaced by its shell, not removed: a deleted row would break the chain at the
            // successor and leave every later verification reporting a manipulation that never
            // happened.
            $this->records[$i] = $record->redactedShell();
            ++$erased;
        }

        return $erased;
    }
}
