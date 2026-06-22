import type { JournalEntry } from './journal-entry.js';

/**
 * Result of `post`: the posting plus the open items created along the way
 * (AR/AP automation — follows with the open-items slice, hence empty for now).
 */
export class PostResult {
  constructor(
    readonly entry: JournalEntry,
    readonly openItemsCreated: unknown[],
  ) {}
}
