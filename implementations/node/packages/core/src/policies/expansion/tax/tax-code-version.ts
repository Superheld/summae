import type { CalendarDate } from '../../../substrate/calendar-date.js';
import type { TaxBaseKind } from './tax-bases.js';

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
    /**
     * How the amount handed in splits into base and tax (F-TAX-010) — the expansion's second seam.
     *
     * `net` is the default and what every shipped pack means today: the amount IS the base. A pack
     * that quotes prices with the tax already inside says `inclusive`, and nothing else about the
     * code changes — same account, same reporting key, same mechanism. See `tax-bases.ts` for why
     * this is a socket of its own rather than a fifth mechanism.
     */
    readonly taxBase: TaxBaseKind = 'net',
  ) {}

  coversDate(date: CalendarDate): boolean {
    if (date.isBefore(this.validFrom)) return false;
    return this.validTo === null || !date.isAfter(this.validTo);
  }
}
