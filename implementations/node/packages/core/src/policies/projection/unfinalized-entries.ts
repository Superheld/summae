import type { JournalRepository } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { Clock } from '../../substrate/clock.js';

/**
 * Postings still in status `entered` at a reference date (F-CORE-027).
 *
 * GoBD asks for finalization "at the latest with the VAT return" (Rz. 47 ff.) — a deadline
 * nothing in the data enforces, because it is a *German* rule and the substrate is
 * jurisdiction-free. What the substrate can do is make the deadline observable: the journal
 * already carries `entryDate` and `recordedAt`, so how long a posting has been sitting open
 * is a fold over the journal, not new state.
 *
 * The age is measured from `entryDate` (the bookkeeping date, zoneless) against `asOf`, not
 * from `recordedAt` — a posting recorded late for an old date is exactly the case the
 * deadline is about, and measuring from the recording moment would hide it.
 *
 * The projection reports; it never blocks. Which age is too old, and what happens then, is
 * the embedding application's workflow — the library supplies the number.
 */
export class UnfinalizedEntriesProjection {
  constructor(
    private readonly journal: JournalRepository,
    private readonly clock: Clock,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const asOf =
      typeof params.asOf === 'string'
        ? CalendarDate.of(params.asOf)
        : CalendarDate.of(this.clock.now().toISOString().slice(0, 10));
    const olderThanDays = typeof params.olderThanDays === 'number' ? params.olderThanDays : 0;
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : null;

    const source = fiscalYear === null ? this.journal.all() : this.journal.forFiscalYear(fiscalYear);
    const entries: Array<Record<string, unknown>> = [];
    let oldestAgeInDays = 0;

    for (const entry of source) {
      if (entry.isFinalized()) continue;
      const ageInDays = asOf.daysSince(entry.entryDate);
      if (ageInDays < olderThanDays) continue;

      entries.push({
        entryId: entry.id.value,
        sequenceNumber: entry.sequenceNumber,
        entryDate: entry.entryDate.iso,
        recordedAt: entry.recordedAt,
        fiscalYear: entry.periodRef.fiscalYear,
        period: entry.periodRef.period,
        ageInDays,
        text: entry.text(),
      });
      if (ageInDays > oldestAgeInDays) oldestAgeInDays = ageInDays;
    }

    // Journal order (sequenceNumber) is the order the entries arrive in; keeping it makes
    // the result deterministic without a second sort key.
    return {
      asOf: asOf.iso,
      olderThanDays,
      count: entries.length,
      oldestAgeInDays,
      entries,
    };
  }
}
