import type { JournalRepository } from '../../port.js';
import type { JournalEntry } from '../../substrate/journal-entry.js';

/**
 * Resolves the reversal back-references of an entry into journal sequence numbers.
 *
 * The journal stores them as ids (`reverses` / `reversedBy`), which is right for the data format and
 * useless on a printed sheet: a reader looking at a cash book finds the counterpart by its number,
 * not by a UUID. So the views a person reads publish numbers, and the export keeps publishing ids.
 *
 * Why the views must publish it at all: a reversal that is not shown as one leaves a reader unable to
 * tell a corrected mistake from a removed transaction, and that is a formal defect in its own right —
 * no evidence of manipulation needed. This core reverses by general reversal (same side, negated
 * amount), which makes it worse without the marker: the movement shows a negative amount on the
 * original side and nothing explaining it.
 *
 * Mechanism, not jurisdiction: every set of books needs its corrections to be traceable.
 */
export class ReversalIndex {
  private constructor(private readonly sequenceById: ReadonlyMap<string, number>) {}

  static of(journal: JournalRepository): ReversalIndex {
    const sequenceById = new Map<string, number>();
    for (const entry of journal.all()) {
      sequenceById.set(entry.id.value, entry.sequenceNumber);
    }
    return new ReversalIndex(sequenceById);
  }

  /**
   * The two fields a movement carries. Both null for an ordinary posting — present and null, never
   * absent, so a reader can tell "not a reversal" from "this view does not say".
   */
  forEntry(entry: JournalEntry): { reversesEntry: number | null; reversedByEntry: number | null } {
    const reversedBy = entry.reversedBy();
    return {
      reversesEntry: entry.reverses === null ? null : (this.sequenceById.get(entry.reverses.value) ?? null),
      reversedByEntry: reversedBy === null ? null : (this.sequenceById.get(reversedBy.value) ?? null),
    };
  }
}
