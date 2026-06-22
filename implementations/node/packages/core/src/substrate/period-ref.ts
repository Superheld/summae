import { InvalidValue } from './errors.js';

/**
 * Verweis auf eine Periode: Geschäftsjahr + Periodennummer (datenformat.md
 * `periodRef`). `fiscalYear` = Kalenderjahr des GJ-Endes.
 */
export class PeriodRef {
  constructor(
    readonly fiscalYear: number,
    readonly period: number,
  ) {
    if (fiscalYear < 1 || fiscalYear > 9999) {
      throw new InvalidValue(`Ungültiges Geschäftsjahr: ${fiscalYear}`);
    }
    if (period < 1 || period > 999) {
      throw new InvalidValue(`Ungültige Periodennummer: ${period}`);
    }
  }

  /** Chronologisch: erst Jahr, dann Periode. */
  compareTo(other: PeriodRef): number {
    if (this.fiscalYear !== other.fiscalYear) {
      return this.fiscalYear < other.fiscalYear ? -1 : 1;
    }
    return this.period < other.period ? -1 : this.period > other.period ? 1 : 0;
  }

  equals(other: PeriodRef): boolean {
    return this.fiscalYear === other.fiscalYear && this.period === other.period;
  }

  toJSON(): { fiscalYear: number; period: number } {
    return { fiscalYear: this.fiscalYear, period: this.period };
  }
}
