<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\Uuid;

/**
 * Journal: append-only, gapless sequenceNumber per fiscal year
 * (decision 2026-06-07, DATEV practice). `save` persists
 * status changes (correct/finalize/reversedBy) — the entry itself
 * is never deleted.
 */
interface JournalRepository
{
    public function append(JournalEntry $entry): void;

    public function save(JournalEntry $entry): void;

    public function byId(Uuid $id): ?JournalEntry;

    /** Next gapless journal number in the fiscal year. */
    public function nextSequenceNumber(int $fiscalYear): int;

    /** @return list<JournalEntry> sorted by (fiscalYear, sequenceNumber) */
    public function all(): array;

    /** @return list<JournalEntry> sorted by sequenceNumber */
    public function forFiscalYear(int $fiscalYear): array;
}
