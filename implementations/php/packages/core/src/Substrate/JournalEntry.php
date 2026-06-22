<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\DomainError;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\PeriodRef;
use Summae\Core\Substrate\Timestamp;
use Summae\Core\Substrate\Uuid;

/**
 * Buchung — das wichtigste Aggregat (ledger-modell.md).
 * Entsteht vollständig und gültig (Validierung im Ledger-Service mit
 * spezifizierter Prüfreihenfolge); Lebenszyklus entered -> finalized;
 * danach nur Storno (neue Buchung mit Rückverweis, Generalumkehr).
 */
final class JournalEntry implements \JsonSerializable
{
    /**
     * @param list<EntryLine> $lines
     */
    public function __construct(
        public readonly Uuid $id,
        public readonly int $sequenceNumber,
        public readonly CalendarDate $entryDate,
        public readonly ?CalendarDate $voucherDate,
        public readonly \DateTimeImmutable $recordedAt,
        public readonly PeriodRef $periodRef,
        public readonly Uuid $voucherId,
        private string $text,
        private array $lines,
        public readonly ?Uuid $reverses = null,
        private ?Uuid $reversedBy = null,
        private EntryStatus $status = EntryStatus::Entered,
    ) {
    }

    public function status(): EntryStatus
    {
        return $this->status;
    }

    public function isFinalized(): bool
    {
        return $this->status === EntryStatus::Finalized;
    }

    public function text(): string
    {
        return $this->text;
    }

    /** @return list<EntryLine> */
    public function lines(): array
    {
        return $this->lines;
    }

    public function reversedBy(): ?Uuid
    {
        return $this->reversedBy;
    }

    public function changeText(string $text): void
    {
        $this->assertCorrectable();
        $this->text = $text;
    }

    /** @param list<EntryLine> $lines */
    public function changeLines(array $lines): void
    {
        $this->assertCorrectable();
        $this->lines = $lines;
    }

    public function finalize(): void
    {
        $this->status = EntryStatus::Finalized;
    }

    public function markReversed(Uuid $reversalId): void
    {
        if ($this->reversedBy !== null) {
            throw new DomainError('E_ENTRY_ALREADY_REVERSED', sprintf(
                'Buchung %s ist bereits storniert (durch %s)',
                $this->id->value,
                $this->reversedBy->value,
            ), ['entryId' => $this->id->value, 'reversedBy' => $this->reversedBy->value]);
        }

        $this->reversedBy = $reversalId;
    }

    private function assertCorrectable(): void
    {
        if ($this->status !== EntryStatus::Entered) {
            throw new DomainError('E_ENTRY_FINALIZED', sprintf(
                'Buchung %s ist festgeschrieben — Korrektur nicht möglich, nur Storno',
                $this->id->value,
            ), ['entryId' => $this->id->value]);
        }
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id->value,
            'sequenceNumber' => $this->sequenceNumber,
            'status' => $this->status->value,
            'entryDate' => $this->entryDate->iso,
            'voucherDate' => $this->voucherDate?->iso,
            'recordedAt' => Timestamp::canonical($this->recordedAt),
            'periodRef' => $this->periodRef->jsonSerialize(),
            'voucherId' => $this->voucherId->value,
            'text' => $this->text,
            'lines' => array_map(
                static fn (EntryLine $line): array => $line->jsonSerialize(),
                $this->lines,
            ),
            'reverses' => $this->reverses?->value,
            'reversedBy' => $this->reversedBy?->value,
        ];
    }
}
