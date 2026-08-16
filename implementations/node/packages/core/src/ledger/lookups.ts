import { DomainError } from '../domain-error.js';
import type { JournalRepository } from '../port.js';
import { CalendarDate } from '../substrate/calendar-date.js';
import { InvalidValue } from '../substrate/errors.js';
import type { JournalEntry } from '../substrate/journal-entry.js';
import { Uuid } from '../substrate/uuid.js';

/**
 * The two lookups every ledger service needs. They are free functions, not methods on a shared
 * base: both are stateless apart from the repository they read, so passing it in is cheaper than
 * inheritance — and it keeps `post`, `settle`, `correct` and `reverse` reporting the exact same
 * error for the exact same bad input, which is what the fixtures pin.
 */

/** A posting date; anything unparsable is a period problem, not a format problem (api.md). */
export function parseEntryDate(entryDate: unknown): CalendarDate {
  if (typeof entryDate !== 'string') {
    throw new DomainError('E_PERIOD_UNKNOWN', 'entryDate missing');
  }
  try {
    return CalendarDate.of(entryDate);
  } catch (error) {
    if (error instanceof InvalidValue) {
      throw new DomainError('E_PERIOD_UNKNOWN', `Invalid posting date "${entryDate}"`);
    }
    throw error;
  }
}

/** An existing journal entry by id — a malformed id is "unknown", never a crash. */
export function requireEntry(journal: JournalRepository, entryId: unknown): JournalEntry {
  let entry: JournalEntry | null = null;
  if (typeof entryId === 'string' && entryId !== '') {
    try {
      entry = journal.byId(Uuid.fromString(entryId));
    } catch (error) {
      if (!(error instanceof InvalidValue)) throw error;
    }
  }
  if (entry === null) {
    throw new DomainError('E_ENTRY_UNKNOWN', `Posting ${typeof entryId === 'string' ? entryId : '?'} does not exist`);
  }
  return entry;
}
