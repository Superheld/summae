import type { FiscalYearRepository } from '../../port.js';
import { integerOrNull } from './parameters.js';

/**
 * Fiscal years and their periods, with the status of each (F-CORE-029).
 *
 * `closePeriod`, `reopenPeriod` and `closeFiscalYear` decide what may still be posted, and until
 * this projection nothing on the read side said what they had decided. `systemDescription` names
 * the invariant — "a closed period accepts no further postings" — without naming the periods.
 *
 * What was left was `auditLog`, which records every close and reopen. That is a **trail, not a
 * state**: replaying it into "period 3 is open" makes the application rebuild library state from a
 * log, and it is wrong the moment a period is closed by anything that did not pass through that
 * application. The same shape as the account status — the write side owning state the read side
 * does not publish.
 *
 * `start` and `end` come out with it, and they are not decoration: without them an application
 * cannot offer a period *list* at all. Twelve months is a guess that a fiscal year running
 * February to January does not share, and a form that invents them asks for input the ledger will
 * refuse.
 *
 * Ordered by year, periods by their number — the order they fall due in.
 */
export class FiscalYearsProjection {
  constructor(private readonly fiscalYears: FiscalYearRepository) {}

  compute(params: Record<string, unknown>): { fiscalYears: Array<Record<string, unknown>> } {
    const wanted = integerOrNull(params.fiscalYear);

    const years = [...this.fiscalYears.all()]
      .filter((year) => wanted === null || year.year === wanted)
      .sort((a, b) => a.year - b.year);

    return {
      fiscalYears: years.map((year) => ({
        year: year.year,
        start: year.start.iso,
        end: year.end.iso,
        status: year.status(),
        periods: [...year.periods()]
          .sort((a, b) => a.number - b.number)
          .map((period) => ({
            period: period.number,
            start: period.start.iso,
            end: period.end.iso,
            status: period.status(),
          })),
      })),
    };
  }
}
