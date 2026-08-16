import { DomainError, rejectedValue } from '../domain-error.js';
import type { FiscalYearRepository, JournalRepository } from '../port.js';
import { FiscalYear } from '../substrate/fiscal-year.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { parseEntryDate } from './lookups.js';

/**
 * Fiscal years and periods: creating a year, closing and reopening a period, closing the year.
 * The constraint side of the ledger — these operations write no postings, they only decide what
 * may still be posted.
 */
export class FiscalPeriodService {
  constructor(
    private readonly fiscalYears: FiscalYearRepository,
    private readonly journal: JournalRepository,
    private readonly ids: IdGenerator,
  ) {}

  createFiscalYear(input: Record<string, unknown>): FiscalYear {
    // Anything that was not a number became year 0 — a quoted `"2027"` from a JSON caller
    // created a fiscal year nobody could address again: every later report for 2027 came back
    // empty and correct-looking instead of saying the year does not exist. A fiscal year is a
    // positive whole number; 2028.5 or -5 are caller mistakes, not values to round into shape.
    const rawYear = input.year;
    // Safe integer, not just integer: `1e21` passes Number.isInteger but is beyond what the
    // PHP side can hold as an int, so it was accepted here and rejected there — same input,
    // different answer, which is the one thing the equivalence policy does not allow.
    if (typeof rawYear !== 'number' || !Number.isSafeInteger(rawYear) || rawYear <= 0) {
      throw new DomainError('E_INPUT_INVALID', 'createFiscalYear requires "year" as a positive whole number', {
        year: rejectedValue(rawYear),
      });
    }
    const year = rawYear;
    const start = parseEntryDate(input.start);
    const end = parseEntryDate(input.end);

    for (const existing of this.fiscalYears.all()) {
      const overlaps = !existing.end.isBefore(start) && !existing.start.isAfter(end);
      if (overlaps || existing.year === year) {
        throw new DomainError(
          'E_FISCALYEAR_OVERLAP',
          `Fiscal year ${year} (${start.iso} to ${end.iso}) overlaps with ${existing.year}`,
          { year, existing: existing.year },
        );
      }
    }

    const fiscalYear = FiscalYear.create(this.ids.next(), year, start, end);
    this.fiscalYears.add(fiscalYear);
    return fiscalYear;
  }

  closePeriod(input: Record<string, unknown>): { fiscalYear: number; period: number; status: string } {
    const fiscalYear = this.requireFiscalYear(input.fiscalYear);
    const period = fiscalYear.closePeriod(this.periodNumber(input));
    this.fiscalYears.save(fiscalYear);
    return { fiscalYear: fiscalYear.year, period: period.number, status: period.status() };
  }

  reopenPeriod(input: Record<string, unknown>): { fiscalYear: number; period: number; status: string } {
    const fiscalYear = this.requireFiscalYear(input.fiscalYear);
    const period = fiscalYear.reopenPeriod(this.periodNumber(input));
    this.fiscalYears.save(fiscalYear);
    return { fiscalYear: fiscalYear.year, period: period.number, status: period.status() };
  }

  closeFiscalYear(input: Record<string, unknown>): FiscalYear {
    const fiscalYear = this.requireFiscalYear(input.fiscalYear);
    for (const entry of this.journal.forFiscalYear(fiscalYear.year)) {
      if (!entry.isFinalized()) {
        throw new DomainError(
          'E_FISCALYEAR_UNFINALIZED_ENTRIES',
          `Year-end close ${fiscalYear.year}: posting ${entry.sequenceNumber} is not finalized`,
          { fiscalYear: fiscalYear.year, sequenceNumber: entry.sequenceNumber },
        );
      }
    }
    fiscalYear.close();
    this.fiscalYears.save(fiscalYear);
    return fiscalYear;
  }

  private requireFiscalYear(year: unknown): FiscalYear {
    const fiscalYear = typeof year === 'number' ? this.fiscalYears.byYear(year) : null;
    if (fiscalYear === null) {
      throw new DomainError('E_PERIOD_UNKNOWN', `Fiscal year ${typeof year === 'number' ? year : '?'} is not created`);
    }
    return fiscalYear;
  }

  private periodNumber(input: Record<string, unknown>): number {
    const period = input.period;
    if (typeof period !== 'number' || !Number.isInteger(period)) {
      throw new DomainError('E_PERIOD_UNKNOWN', 'Period number missing');
    }
    return period;
  }
}
