<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Ledger\JournalEntry;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Shared\Uuid;

final class InMemoryJournalRepository implements JournalRepository
{
    /** @var list<JournalEntry> in Append-Reihenfolge */
    private array $entries = [];

    /** @var array<string, JournalEntry> */
    private array $byId = [];

    /** @var array<int, int> fiscalYear -> letzte sequenceNumber */
    private array $sequences = [];

    public function append(JournalEntry $entry): void
    {
        $this->entries[] = $entry;
        $this->byId[$entry->id->value] = $entry;
        $this->sequences[$entry->periodRef->fiscalYear] = $entry->sequenceNumber;
    }

    public function save(JournalEntry $entry): void
    {
    }

    public function byId(Uuid $id): ?JournalEntry
    {
        return $this->byId[$id->value] ?? null;
    }

    public function nextSequenceNumber(int $fiscalYear): int
    {
        return ($this->sequences[$fiscalYear] ?? 0) + 1;
    }

    public function all(): array
    {
        $entries = $this->entries;
        usort($entries, static fn (JournalEntry $a, JournalEntry $b): int =>
            [$a->periodRef->fiscalYear, $a->sequenceNumber] <=> [$b->periodRef->fiscalYear, $b->sequenceNumber]);

        return $entries;
    }

    public function forFiscalYear(int $fiscalYear): array
    {
        $entries = array_values(array_filter(
            $this->entries,
            static fn (JournalEntry $entry): bool => $entry->periodRef->fiscalYear === $fiscalYear,
        ));
        usort($entries, static fn (JournalEntry $a, JournalEntry $b): int => $a->sequenceNumber <=> $b->sequenceNumber);

        return $entries;
    }
}
