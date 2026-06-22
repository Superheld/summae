import type { CalendarDate } from './calendar-date.js';
import type { PeriodStatus } from './types.js';

/**
 * Period — entity within the FiscalYear aggregate (gapless,
 * non-overlapping; status change only via the aggregate).
 */
export class Period {
  private periodStatus: PeriodStatus;

  constructor(
    readonly number: number,
    readonly start: CalendarDate,
    readonly end: CalendarDate,
    status: PeriodStatus = 'open',
  ) {
    this.periodStatus = status;
  }

  status(): PeriodStatus {
    return this.periodStatus;
  }

  isOpen(): boolean {
    return this.periodStatus === 'open';
  }

  contains(date: CalendarDate): boolean {
    return date.isBetween(this.start, this.end);
  }

  /** Call only via FiscalYear (order check there). */
  close(): void {
    this.periodStatus = 'closed';
  }

  /** Call only via FiscalYear (year status check there). */
  reopen(): void {
    this.periodStatus = 'open';
  }
}
