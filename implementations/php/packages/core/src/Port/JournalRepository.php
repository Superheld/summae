<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Ledger\JournalEntry;
use Summae\Core\Substrate\Uuid;

/**
 * Journal: append-only, lückenlose sequenceNumber je Geschäftsjahr
 * (Entscheidung 2026-06-07, DATEV-Praxis). `save` persistiert
 * Statuswechsel (correct/finalize/reversedBy) — der Eintrag selbst
 * wird nie gelöscht.
 */
interface JournalRepository
{
    public function append(JournalEntry $entry): void;

    public function save(JournalEntry $entry): void;

    public function byId(Uuid $id): ?JournalEntry;

    /** Nächste lückenlose Journalnummer im Geschäftsjahr. */
    public function nextSequenceNumber(int $fiscalYear): int;

    /** @return list<JournalEntry> sortiert nach (fiscalYear, sequenceNumber) */
    public function all(): array;

    /** @return list<JournalEntry> sortiert nach sequenceNumber */
    public function forFiscalYear(int $fiscalYear): array;
}
