import { InvalidValue } from './errors.js';

/**
 * Zoneless calendar date (determinismus.md §4): voucher and posting date
 * know no time zone — no UTC shift risk. ISO format sorts
 * lexicographically correctly (= chronologically).
 */
function toIso(year: number, monthIndex: number, day: number): string {
  const date = new Date(Date.UTC(year, monthIndex, day));
  const y = String(date.getUTCFullYear()).padStart(4, '0');
  const m = String(date.getUTCMonth() + 1).padStart(2, '0');
  const d = String(date.getUTCDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
}

export class CalendarDate {
  private constructor(readonly iso: string) {}

  static of(iso: string): CalendarDate {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
      throw new InvalidValue(`Not a valid calendar date: "${iso}"`);
    }
    const year = Number(iso.slice(0, 4));
    const month = Number(iso.slice(5, 7));
    const day = Number(iso.slice(8, 10));
    // Round-trip check catches 2026-13-01, 2026-02-30 etc.
    if (toIso(year, month - 1, day) !== iso) {
      throw new InvalidValue(`Not a valid calendar date: "${iso}"`);
    }
    return new CalendarDate(iso);
  }

  compareTo(other: CalendarDate): number {
    return this.iso < other.iso ? -1 : this.iso > other.iso ? 1 : 0;
  }

  equals(other: CalendarDate): boolean {
    return this.iso === other.iso;
  }

  isBefore(other: CalendarDate): boolean {
    return this.compareTo(other) < 0;
  }

  isAfter(other: CalendarDate): boolean {
    return this.compareTo(other) > 0;
  }

  isBetween(start: CalendarDate, end: CalendarDate): boolean {
    return !this.isBefore(start) && !this.isAfter(end);
  }

  year(): number {
    return Number(this.iso.slice(0, 4));
  }

  month(): number {
    return Number(this.iso.slice(5, 7));
  }

  lastDayOfMonth(): CalendarDate {
    // Day 0 of the next month = last day of this month.
    return new CalendarDate(toIso(this.year(), this.month(), 0));
  }

  firstDayOfNextMonth(): CalendarDate {
    return new CalendarDate(toIso(this.year(), this.month(), 1));
  }

  toJSON(): string {
    return this.iso;
  }

  toString(): string {
    return this.iso;
  }
}
