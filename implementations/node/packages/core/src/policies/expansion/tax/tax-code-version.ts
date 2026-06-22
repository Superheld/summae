import type { CalendarDate } from '../../../substrate/calendar-date.js';

/**
 * Rule version of a tax code with validity period. Contents are
 * rule-module data — code cites no statute. mechanism `reverse_charge`:
 * VAT and input-tax line at once; `intra_community_supply`: tax-free.
 */
export class TaxCodeVersion {
  constructor(
    readonly validFrom: CalendarDate,
    readonly validTo: CalendarDate | null,
    readonly rate: string,
    readonly taxAccount: string,
    readonly reportingKey: string | null,
    readonly mechanism: string = 'standard',
    readonly inputTaxAccount: string | null = null,
    readonly inputReportingKey: string | null = null,
    readonly baseReportingKey: string | null = null,
  ) {}

  coversDate(date: CalendarDate): boolean {
    if (date.isBefore(this.validFrom)) return false;
    return this.validTo === null || !date.isAfter(this.validTo);
  }
}
