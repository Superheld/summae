import { CalendarDate } from '../substrate/calendar-date.js';

interface Segment {
  validFrom: CalendarDate;
  value: boolean;
}

/**
 * Steuerliches Mandantenprofil (tax-modell.md Aggregat 2): Versteuerungsart,
 * Kleinunternehmer-Status mit Gültigkeitszeitraum (unterjähriger Wechsel,
 * SF-11), Voranmeldungszeitraum.
 */
export class TaxProfile {
  private constructor(
    private readonly method: string,
    private smallBusiness: Segment[],
    private readonly period: string,
  ) {}

  static fromData(data: Record<string, unknown>): TaxProfile {
    const method = data.taxationMethod === 'cash' ? 'cash' : 'accrual';
    const period = data.vatPeriod === 'monthly' ? 'monthly' : 'quarterly';

    const segments: Segment[] = [];
    const smallBusiness = data.smallBusiness ?? false;
    if (typeof smallBusiness === 'boolean') {
      if (smallBusiness) segments.push({ validFrom: CalendarDate.of('0001-01-01'), value: true });
    } else if (Array.isArray(smallBusiness)) {
      for (const segment of smallBusiness) {
        if (segment === null || typeof segment !== 'object') continue;
        const s = segment as Record<string, unknown>;
        if (typeof s.validFrom !== 'string') continue;
        segments.push({ validFrom: CalendarDate.of(s.validFrom), value: s.value === true });
      }
    }

    return new TaxProfile(method, TaxProfile.sorted(segments), period);
  }

  static default(): TaxProfile {
    return new TaxProfile('accrual', [], 'quarterly');
  }

  taxationMethod(): string {
    return this.method;
  }

  isCashBasis(): boolean {
    return this.method === 'cash';
  }

  vatPeriod(): string {
    return this.period;
  }

  smallBusinessAt(date: CalendarDate): boolean {
    let value = false;
    for (const segment of this.smallBusiness) {
      if (segment.validFrom.isAfter(date)) break;
      value = segment.value;
    }
    return value;
  }

  setSmallBusiness(validFrom: CalendarDate, value: boolean): void {
    const segments = this.smallBusiness.filter((segment) => !segment.validFrom.equals(validFrom));
    segments.push({ validFrom, value });
    this.smallBusiness = TaxProfile.sorted(segments);
  }

  private static sorted(segments: Segment[]): Segment[] {
    return [...segments].sort((a, b) => a.validFrom.compareTo(b.validFrom));
  }

  toJSON(): Record<string, unknown> {
    return {
      taxationMethod: this.method,
      vatPeriod: this.period,
      smallBusiness: this.smallBusiness.map((segment) => ({
        validFrom: segment.validFrom.iso,
        value: segment.value,
      })),
    };
  }
}
