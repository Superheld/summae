import { InvalidValue } from './errors.js';

/**
 * Zoneless calendar date (determinismus.md §4): voucher and posting date
 * know no time zone — no UTC shift risk. ISO format sorts
 * lexicographically correctly (= chronologically).
 *
 * The arithmetic below is deliberately done by hand instead of through `Date`.
 * `Date.UTC(year, …)` maps years 0–99 onto 1900+year (a JavaScript legacy rule),
 * which silently made `0000-01-01` … `0099-12-31` fail the round-trip check while
 * the PHP reference accepted them — same input, different result, in the one layer
 * that is supposed to be frozen and jurisdiction-free. Keeping the host `Date` out
 * of the substrate removes the whole class of such divergences.
 */
const DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31] as const;

function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

function daysInMonth(year: number, month: number): number {
  return month === 2 && isLeapYear(year) ? 29 : (DAYS_IN_MONTH[month - 1] ?? 0);
}

function iso(year: number, month: number, day: number): string {
  return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

/**
 * Days since the civil epoch (1970-01-01), by Howard Hinnant's `days_from_civil`.
 * Hand-rolled for the same reason as the rest of this file: the host `Date` is out of
 * the substrate, so year 0042 subtracts like any other year. Valid for the proleptic
 * Gregorian calendar, which is what a zoneless bookkeeping date is.
 */
function daysFromCivil(year: number, month: number, day: number): number {
  const y = month <= 2 ? year - 1 : year;
  const era = Math.floor(y / 400);
  const yoe = y - era * 400; // [0, 399]
  const doy = Math.floor((153 * (month + (month > 2 ? -3 : 9)) + 2) / 5) + day - 1; // [0, 365]
  const doe = yoe * 365 + Math.floor(yoe / 4) - Math.floor(yoe / 100) + doy; // [0, 146096]
  return era * 146097 + doe - 719468;
}

export class CalendarDate {
  private constructor(readonly iso: string) {}

  static of(value: string): CalendarDate {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      throw new InvalidValue(`Not a valid calendar date: "${value}"`);
    }
    const year = Number(value.slice(0, 4));
    const month = Number(value.slice(5, 7));
    const day = Number(value.slice(8, 10));
    // Catches 2026-13-01, 2026-02-30, 2026-02-29 in a non-leap year, …
    if (month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month)) {
      throw new InvalidValue(`Not a valid calendar date: "${value}"`);
    }
    return new CalendarDate(value);
  }

  /**
   * Whole days from `other` up to this date; negative when this date is earlier.
   * Zoneless subtraction — no hours, no DST, no leap seconds to lose a day to.
   */
  daysSince(other: CalendarDate): number {
    return this.dayNumber() - other.dayNumber();
  }

  private dayNumber(): number {
    return daysFromCivil(
      Number(this.iso.slice(0, 4)),
      Number(this.iso.slice(5, 7)),
      Number(this.iso.slice(8, 10)),
    );
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
    return new CalendarDate(iso(this.year(), this.month(), daysInMonth(this.year(), this.month())));
  }

  firstDayOfNextMonth(): CalendarDate {
    const rollsOver = this.month() === 12;
    return new CalendarDate(iso(rollsOver ? this.year() + 1 : this.year(), rollsOver ? 1 : this.month() + 1, 1));
  }

  toJSON(): string {
    return this.iso;
  }

  toString(): string {
    return this.iso;
  }
}
