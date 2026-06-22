import { DomainError } from '../domain-error.js';
import type { CalendarDate } from '../substrate/calendar-date.js';
import type { PeriodRef } from '../substrate/period-ref.js';
import type { Uuid } from '../substrate/uuid.js';
import type { EntryLine } from './entry-line.js';
import type { EntryStatus } from './types.js';

/**
 * Buchung — das wichtigste Aggregat (ledger-modell.md). Entsteht vollständig und
 * gültig (Validierung im Ledger-Service); Lebenszyklus entered → finalized,
 * danach nur Storno (neue Buchung mit Rückverweis, Generalumkehr).
 */
export class JournalEntry {
  private entryText: string;
  private entryLines: EntryLine[];
  private entryReversedBy: Uuid | null;
  private entryStatus: EntryStatus;

  constructor(
    readonly id: Uuid,
    readonly sequenceNumber: number,
    readonly entryDate: CalendarDate,
    readonly voucherDate: CalendarDate | null,
    readonly recordedAt: string,
    readonly periodRef: PeriodRef,
    readonly voucherId: Uuid,
    text: string,
    lines: EntryLine[],
    readonly reverses: Uuid | null = null,
    reversedBy: Uuid | null = null,
    status: EntryStatus = 'entered',
  ) {
    this.entryText = text;
    this.entryLines = lines;
    this.entryReversedBy = reversedBy;
    this.entryStatus = status;
  }

  status(): EntryStatus {
    return this.entryStatus;
  }

  isFinalized(): boolean {
    return this.entryStatus === 'finalized';
  }

  text(): string {
    return this.entryText;
  }

  lines(): EntryLine[] {
    return this.entryLines;
  }

  reversedBy(): Uuid | null {
    return this.entryReversedBy;
  }

  changeText(text: string): void {
    this.assertCorrectable();
    this.entryText = text;
  }

  changeLines(lines: EntryLine[]): void {
    this.assertCorrectable();
    this.entryLines = lines;
  }

  finalize(): void {
    this.entryStatus = 'finalized';
  }

  markReversed(reversalId: Uuid): void {
    if (this.entryReversedBy !== null) {
      throw new DomainError(
        'E_ENTRY_ALREADY_REVERSED',
        `Buchung ${this.id.value} ist bereits storniert (durch ${this.entryReversedBy.value})`,
        { entryId: this.id.value, reversedBy: this.entryReversedBy.value },
      );
    }
    this.entryReversedBy = reversalId;
  }

  private assertCorrectable(): void {
    if (this.entryStatus !== 'entered') {
      throw new DomainError(
        'E_ENTRY_FINALIZED',
        `Buchung ${this.id.value} ist festgeschrieben — Korrektur nicht möglich, nur Storno`,
        { entryId: this.id.value },
      );
    }
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      sequenceNumber: this.sequenceNumber,
      status: this.entryStatus,
      entryDate: this.entryDate.iso,
      voucherDate: this.voucherDate?.iso ?? null,
      recordedAt: this.recordedAt,
      periodRef: this.periodRef.toJSON(),
      voucherId: this.voucherId.value,
      text: this.entryText,
      lines: this.entryLines.map((line) => line.toJSON()),
      reverses: this.reverses?.value ?? null,
      reversedBy: this.entryReversedBy?.value ?? null,
    };
  }
}
