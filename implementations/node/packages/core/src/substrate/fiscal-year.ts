import { DomainError } from '../domain-error.js';
import type { CalendarDate } from './calendar-date.js';
import { InvalidValue } from './errors.js';
import type { Uuid } from './uuid.js';
import { Period } from './period.js';
import type { FiscalYearStatus } from './types.js';

export interface PeriodDefinition {
  readonly period: number;
  readonly start: CalendarDate;
  readonly end: CalendarDate;
}

/**
 * Fiscal year with periods (ledger-modell.md aggregate 3). Invariants:
 * periods gapless and non-overlapping; closing only in order;
 * reopening only before year-end closing. `year` = calendar year of the fiscal year end.
 */
export class FiscalYear {
  private fiscalStatus: FiscalYearStatus;

  private constructor(
    readonly id: Uuid,
    readonly year: number,
    readonly start: CalendarDate,
    readonly end: CalendarDate,
    private readonly periodList: Period[],
    status: FiscalYearStatus = 'open',
  ) {
    this.fiscalStatus = status;
  }

  static create(
    id: Uuid,
    year: number,
    start: CalendarDate,
    end: CalendarDate,
    explicitPeriods: PeriodDefinition[] | null = null,
  ): FiscalYear {
    if (!start.isBefore(end)) {
      throw new InvalidValue('Fiscal year: start must be before end');
    }
    const periods =
      explicitPeriods === null
        ? FiscalYear.monthlyPeriods(start, end)
        : explicitPeriods.map((d) => new Period(d.period, d.start, d.end));
    return new FiscalYear(id, year, start, end, periods);
  }

  /**
   * Restore from persistence: take over status and periods explicitly
   * (no rebuild, no validation) — counterpart to PHP's `FiscalYear::restore`.
   */
  static restore(
    id: Uuid,
    year: number,
    start: CalendarDate,
    end: CalendarDate,
    status: FiscalYearStatus,
    periods: Period[],
  ): FiscalYear {
    return new FiscalYear(id, year, start, end, periods, status);
  }

  private static monthlyPeriods(start: CalendarDate, end: CalendarDate): Period[] {
    const periods: Period[] = [];
    let cursor = start;
    let number = 1;
    while (!cursor.isAfter(end)) {
      const monthEnd = cursor.lastDayOfMonth();
      const periodEnd = monthEnd.isAfter(end) ? end : monthEnd;
      periods.push(new Period(number, cursor, periodEnd));
      cursor = cursor.firstDayOfNextMonth();
      number++;
    }
    return periods;
  }

  status(): FiscalYearStatus {
    return this.fiscalStatus;
  }

  isClosed(): boolean {
    return this.fiscalStatus === 'closed';
  }

  periods(): Period[] {
    return this.periodList;
  }

  period(number: number): Period {
    const found = this.periodList.find((p) => p.number === number);
    if (found === undefined) {
      throw new DomainError(
        'E_PERIOD_UNKNOWN',
        `Period ${number} does not exist in fiscal year ${this.year}`,
        { fiscalYear: this.year, period: number },
      );
    }
    return found;
  }

  contains(date: CalendarDate): boolean {
    return date.isBetween(this.start, this.end);
  }

  periodForDate(date: CalendarDate): Period {
    const found = this.periodList.find((p) => p.contains(date));
    if (found === undefined) {
      throw new DomainError(
        'E_PERIOD_UNKNOWN',
        `No period range for ${date.iso} in fiscal year ${this.year}`,
        { date: date.iso, fiscalYear: this.year },
      );
    }
    return found;
  }

  closePeriod(number: number): Period {
    this.assertNotClosed();
    const target = this.period(number);
    for (const period of this.periodList) {
      if (period.number < number && period.isOpen()) {
        throw new DomainError(
          'E_PERIOD_OUT_OF_ORDER',
          `Period ${number} cannot be closed: period ${period.number} is still open`,
          { fiscalYear: this.year, period: number, openPeriod: period.number },
        );
      }
    }
    target.close();
    return target;
  }

  reopenPeriod(number: number): Period {
    this.assertNotClosed();
    const target = this.period(number);
    target.reopen();
    return target;
  }

  /** Pure status change — no business posting effect (api.md v0.3). */
  close(): void {
    for (const period of this.periodList) {
      if (period.isOpen()) {
        throw new DomainError(
          'E_PERIOD_OUT_OF_ORDER',
          `Year-end closing ${this.year}: period ${period.number} is still open`,
          { fiscalYear: this.year, openPeriod: period.number },
        );
      }
    }
    this.fiscalStatus = 'closed';
  }

  private assertNotClosed(): void {
    if (this.isClosed()) {
      throw new DomainError('E_FISCALYEAR_CLOSED', `Fiscal year ${this.year} is closed`, {
        fiscalYear: this.year,
      });
    }
  }
}
