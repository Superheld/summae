import { describe, expect, it } from 'vitest';
import { DomainError, TaxProfile } from '../src/index.js';

/**
 * The pack's filing windows **replace** the substrate's, they do not extend them (SPEC-016).
 *
 * The fixture `xx-7-pack-declares-filing-periods` proves the half that is visible from outside: a
 * jurisdiction can name a window the core never learned. This proves the other half, which no
 * fixture can reach because it needs a pack that *excludes* something — a pack whose jurisdiction
 * has no quarterly filing must not have quarterly quietly available, or the substrate's list is
 * still the real one and the finding is only half closed.
 *
 * The PHP twin is `VatPeriodsFromPackTest`.
 */
function errorCodeOf(call: () => unknown): string {
  try {
    call();
  } catch (error) {
    return error instanceof DomainError ? error.errorCode : 'NOT_A_DOMAIN_ERROR';
  }
  return 'NO_ERROR';
}

describe('the pack declares its filing windows (SPEC-016)', () => {
  it('can name a window the substrate never learned', () => {
    expect(TaxProfile.fromData({ vatPeriod: 'bi-monthly' }, ['bi-monthly', 'yearly']).vatPeriod()).toBe('bi-monthly');
  });

  it('replaces the substrate list rather than extending it', () => {
    expect(errorCodeOf(() => TaxProfile.fromData({ vatPeriod: 'quarterly' }, ['bi-monthly', 'yearly']))).toBe(
      'E_INPUT_INVALID',
    );
  });

  it('takes the fallback from whoever owns the list', () => {
    expect(TaxProfile.fromData({}).vatPeriod()).toBe('quarterly');
    expect(TaxProfile.fromData({}, ['bi-monthly', 'yearly']).vatPeriod()).toBe('bi-monthly');
  });

  it('behaves exactly as before for a pack that declares nothing', () => {
    expect(TaxProfile.fromData({ vatPeriod: 'yearly' }, null).vatPeriod()).toBe('yearly');
    expect(TaxProfile.fromData({ vatPeriod: 'yearly' }, []).vatPeriod()).toBe('yearly');
    expect(errorCodeOf(() => TaxProfile.fromData({ vatPeriod: 'zweimonatlich' }, null))).toBe('E_INPUT_INVALID');
  });

  /** A stored profile is rebuilt, never re-judged — a pack that drops a window must not lock books. */
  it('restores a stored profile without revalidating it', () => {
    const restored = TaxProfile.restore({ taxationMethod: 'cash', vatPeriod: 'bi-monthly', smallBusiness: [] });
    expect(restored.vatPeriod()).toBe('bi-monthly');
    expect(restored.taxationMethod()).toBe('cash');
  });
});
