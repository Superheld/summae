import type { JournalEntry } from './journal-entry.js';

/**
 * Ergebnis von `post`: die Buchung plus die dabei entstandenen offenen Posten
 * (AR/AP-Automatik — folgt mit dem Open-Items-Slice, daher vorerst leer).
 */
export class PostResult {
  constructor(
    readonly entry: JournalEntry,
    readonly openItemsCreated: unknown[],
  ) {}
}
