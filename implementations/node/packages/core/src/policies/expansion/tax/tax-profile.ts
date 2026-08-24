import { DomainError } from '../../../domain-error.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';

interface Segment {
  validFrom: CalendarDate;
  value: boolean;
}

/**
 * Tenant tax profile (tax-modell.md aggregate 2): taxation method,
 * small-business status with validity period (mid-year change,
 * SF-11), VAT filing period.
 */
export class TaxProfile {
  private constructor(
    private readonly method: string,
    private smallBusiness: Segment[],
    private readonly period: string,
  ) {}

  /**
   * The two documented sets, refused rather than approximated (F-TAX-003).
   *
   * `taxationMethod` used to be `=== 'cash' ? 'cash' : 'accrual'` and `vatPeriod`
   * `=== 'monthly' ? 'monthly' : 'quarterly'`, so a typo, a `null` or an object all arrived as a
   * valid-looking profile that books differently, and nothing said so. This is not a value object
   * built from a trusted internal source: `fromData` is fed from an embedding's configuration file,
   * which is exactly where a typo lives — and the value it decides is whether VAT falls due on
   * invoice or on payment.
   *
   * **The two sets are not the same kind of thing, and only one of them is safely here.**
   * `taxationMethod` is substrate mechanism: accrual and cash are the two ways this engine can time
   * a tax liability, and it implements both. `vatPeriod` is a *label* — it records which window a
   * tenant files in and selects nothing (`vatReturn` takes its own window). Which filing windows
   * exist is a question jurisdictions answer differently, so a closed list of them in the substrate
   * is a claim the substrate has no business making. `yearly` is added here because the previous
   * list was wrong in a way that lost data silently, not because the list is now right. Open, with
   * the reasoning: SPEC-016.
   */
  private static readonly METHODS = ['accrual', 'cash'];

  private static readonly PERIODS = ['monthly', 'quarterly', 'yearly'];

  static fromData(data: Record<string, unknown>): TaxProfile {
    const method = TaxProfile.oneOf(data.taxationMethod, TaxProfile.METHODS, 'taxationMethod', 'accrual');
    const period = TaxProfile.oneOf(data.vatPeriod, TaxProfile.PERIODS, 'vatPeriod', 'quarterly');

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

  /** Absent keeps the documented default; anything else must be one of the documented values. */
  private static oneOf(value: unknown, allowed: string[], field: string, fallback: string): string {
    if (value === undefined) return fallback;
    if (typeof value === 'string' && allowed.includes(value)) return value;
    throw new DomainError(
      'E_INPUT_INVALID',
      `taxProfile.${field} must be one of ${allowed.map((a) => `"${a}"`).join(', ')}`,
      { [field]: value === null ? null : typeof value === 'string' ? value : typeof value },
    );
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
