import type { CalendarDate } from '../substrate/calendar-date.js';

/**
 * Regelversion eines Steuerschlüssels mit Gültigkeitszeitraum. Inhalte sind
 * Regelmodul-Daten — Code zitiert kein Gesetz. mechanism `reverse_charge`:
 * USt- und VSt-Position gleichzeitig; `intra_community_supply`: steuerfrei.
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
