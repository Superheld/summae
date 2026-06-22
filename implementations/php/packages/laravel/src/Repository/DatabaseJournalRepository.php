<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Ledger\EntryLine;
use Summae\Core\Ledger\EntryStatus;
use Summae\Core\Ledger\JournalEntry;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Timestamp;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

/**
 * Journal: append-only — `save` aktualisiert ausschließlich die
 * veränderlichen Aggregat-Teile (Status, Text, Zeilen-Korrektur,
 * Storno-Verweis); gelöscht wird nie.
 */
final readonly class DatabaseJournalRepository implements JournalRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function append(JournalEntry $entry): void
    {
        $this->table()->insert([
            'id' => $entry->id->value,
            'tenant_id' => $this->tenantId->value,
            'fiscal_year' => $entry->periodRef->fiscalYear,
            'sequence_number' => $entry->sequenceNumber,
            'period' => $entry->periodRef->period,
            'status' => $entry->status()->value,
            'entry_date' => $entry->entryDate->iso,
            'voucher_date' => $entry->voucherDate?->iso,
            'recorded_at' => Timestamp::canonical($entry->recordedAt),
            'voucher_id' => $entry->voucherId->value,
            'text' => $entry->text(),
            'lines' => Hydrator::encode(array_map(
                static fn (EntryLine $line): array => $line->jsonSerialize(),
                $entry->lines(),
            )),
            'reverses' => $entry->reverses?->value,
            'reversed_by' => $entry->reversedBy()?->value,
        ]);
    }

    public function save(JournalEntry $entry): void
    {
        $this->table()->where('id', $entry->id->value)->update([
            'status' => $entry->status()->value,
            'text' => $entry->text(),
            'lines' => Hydrator::encode(array_map(
                static fn (EntryLine $line): array => $line->jsonSerialize(),
                $entry->lines(),
            )),
            'reversed_by' => $entry->reversedBy()?->value,
        ]);
    }

    public function byId(Uuid $id): ?JournalEntry
    {
        $row = $this->table()->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function nextSequenceNumber(int $fiscalYear): int
    {
        $max = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('fiscal_year', $fiscalYear)
            ->max('sequence_number');

        return (is_int($max) ? $max : 0) + 1;
    }

    public function all(): array
    {
        $entries = [];

        foreach ($this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->orderBy('fiscal_year')
            ->orderBy('sequence_number')
            ->get() as $row) {
            $entries[] = $this->hydrate($row);
        }

        return $entries;
    }

    public function forFiscalYear(int $fiscalYear): array
    {
        $entries = [];

        foreach ($this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('fiscal_year', $fiscalYear)
            ->orderBy('sequence_number')
            ->get() as $row) {
            $entries[] = $this->hydrate($row);
        }

        return $entries;
    }

    private function hydrate(object $row): JournalEntry
    {
        /** @var object{id: string, fiscal_year: int|string, sequence_number: int|string, period: int|string, status: string, entry_date: string, voucher_date: ?string, recorded_at: string, voucher_id: string, text: string, lines: string, reverses: ?string, reversed_by: ?string} $row */
        return new JournalEntry(
            Uuid::fromString($row->id),
            (int) $row->sequence_number,
            Hydrator::date($row->entry_date) ?? throw new \RuntimeException('entry_date fehlt'),
            Hydrator::date($row->voucher_date),
            new \DateTimeImmutable($row->recorded_at),
            new PeriodRef((int) $row->fiscal_year, (int) $row->period),
            Uuid::fromString($row->voucher_id),
            $row->text,
            Hydrator::entryLines(Hydrator::decodeList($row->lines)),
            $row->reverses === null ? null : Uuid::fromString($row->reverses),
            $row->reversed_by === null ? null : Uuid::fromString($row->reversed_by),
            EntryStatus::from($row->status),
        );
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'journal_entries');
    }
}
