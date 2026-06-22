import type { CalendarDate } from '../substrate/calendar-date.js';
import type { PeriodStatus } from './types.js';

/**
 * Periode — Entity innerhalb des FiscalYear-Aggregats (lückenlos,
 * überlappungsfrei; Statuswechsel nur über das Aggregat).
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

  /** Nur über FiscalYear aufrufen (Reihenfolgeprüfung dort). */
  close(): void {
    this.periodStatus = 'closed';
  }

  /** Nur über FiscalYear aufrufen (Jahresstatusprüfung dort). */
  reopen(): void {
    this.periodStatus = 'open';
  }
}
