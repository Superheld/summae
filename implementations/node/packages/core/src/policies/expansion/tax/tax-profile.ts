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
   * is a claim the substrate has no business making — **which is why the pack answers it now**
   * (SPEC-016): `packPolicy.vatPeriods` declares the windows a jurisdiction files in, and a pack
   * that declares them overrides the substrate's list entirely.
   */
  private static readonly METHODS = ['accrual', 'cash'];

  /**
   * The filing windows assumed when a pack declares none — a **default, not a definition**
   * (SPEC-016). `packPolicy.vatPeriods` overrides it entirely, which is what lets a jurisdiction
   * that files bi-monthly say so without the substrate learning the word.
   */
  private static readonly PERIODS = ['monthly', 'quarterly', 'yearly'];

  /**
   * @param vatPeriods what the pack recognises; absent = the substrate default (SPEC-016). Which
   * filing windows exist is a question jurisdictions answer differently, so a closed list of them
   * in the substrate is a claim the substrate has no business making — the pack answers it now, and
   * a pack that declares nothing keeps exactly the behaviour it had.
   */
  static fromData(data: Record<string, unknown>, vatPeriods?: readonly string[] | null): TaxProfile {
    const method = TaxProfile.oneOf(data.taxationMethod, TaxProfile.METHODS, 'taxationMethod', 'accrual');
    const declared = vatPeriods === undefined || vatPeriods === null || vatPeriods.length === 0 ? null : vatPeriods;
    const periods = declared ?? TaxProfile.PERIODS;
    // The substrate default keeps its documented `quarterly`; a declaring pack gets its own first
    // entry, because a pack that does not file quarterly should not have it as a fallback.
    const fallback = declared === null ? 'quarterly' : (declared[0] as string);
    const period = TaxProfile.oneOf(data.vatPeriod, periods, 'vatPeriod', fallback);

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

  /**
   * A profile summae itself stored, rebuilt without re-validating it.
   *
   * Validation belongs at the boundary, and this is not one: these values were checked when they
   * arrived. Re-checking them on the way *out* of our own store would mean a tenant whose pack
   * later drops a filing window can no longer be opened — a rule change reaching backwards into
   * books that were kept correctly under the old one.
   */
  static restore(data: Record<string, unknown>): TaxProfile {
    const method = typeof data.taxationMethod === 'string' ? data.taxationMethod : 'accrual';
    const period = typeof data.vatPeriod === 'string' ? data.vatPeriod : 'quarterly';

    const segments: Segment[] = [];
    for (const segment of Array.isArray(data.smallBusiness) ? data.smallBusiness : []) {
      if (segment === null || typeof segment !== 'object') continue;
      const s = segment as Record<string, unknown>;
      if (typeof s.validFrom !== 'string') continue;
      segments.push({ validFrom: CalendarDate.of(s.validFrom), value: s.value === true });
    }

    return new TaxProfile(method, TaxProfile.sorted(segments), period);
  }

  /** Absent keeps the documented default; anything else must be one of the documented values. */
  private static oneOf(value: unknown, allowed: readonly string[], field: string, fallback: string): string {
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
