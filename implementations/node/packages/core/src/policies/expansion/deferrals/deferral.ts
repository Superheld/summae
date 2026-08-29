import type { AccountNumber } from '../../../substrate/account-number.js';
import type { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Money } from '../../../substrate/money.js';
import type { Uuid } from '../../../substrate/uuid.js';

export interface DeferralInstalment {
  fiscalYear: number;
  period: number;
  amount: Money;
}

export interface DeferralRelease extends DeferralInstalment {
  date: CalendarDate;
  entryId: Uuid;
}

export const PREPAID_EXPENSE = 'prepaidExpense';
export const DEFERRED_INCOME = 'deferredIncome';

/** The closed vocabulary — a third kind would be a third direction of posting. */
export const DEFERRAL_KINDS: readonly string[] = [PREPAID_EXPENSE, DEFERRED_INCOME];

function key(fiscalYear: number, period: number): string {
  return `${String(fiscalYear).padStart(4, '0')}-${String(period).padStart(2, '0')}`;
}

/**
 * A prepaid or deferred item and its release plan (F-CORE-053).
 *
 * **What was missing was never the accounts.** Both of them have existed in the shipped German chart
 * from the beginning, and both have had a balance-sheet position. What was missing is the *plan*: an
 * insurance premium paid in December for the following year could be deferred and then had to be
 * released by hand, month after month, from memory. That is precisely the failure mode
 * `runDepreciation` exists to prevent for arithmetic that is identical — an amount spread evenly
 * over a known number of periods — and the two ought not to differ in whether the machine remembers.
 *
 * **The plan is fixed at recognition and never recomputed.** `allocate` distributes the amount over
 * the periods with largest-remainder, so the instalments sum to the amount exactly and the last one
 * carries no drift. Storing the plan rather than re-deriving it is the same decision the asset
 * schedule makes, for the same reason: a plan that is recomputed on read answers differently after a
 * rounding rule moves.
 *
 * **Two kinds, and they are opposites rather than variants.** A *prepaid expense* is money already
 * paid for a service still to come — an asset. A *deferred income* is money already received for a
 * service still to be rendered — a liability. Both defer, in opposite directions, and every posting
 * this class produces flips with the kind.
 */
export class Deferral {
  private released = new Map<string, DeferralRelease>();

  constructor(
    readonly id: Uuid,
    readonly kind: string,
    readonly reason: string,
    readonly account: AccountNumber,
    readonly counterAccount: AccountNumber,
    readonly recognizedOn: CalendarDate,
    readonly amount: Money,
    readonly plan: DeferralInstalment[],
    readonly recognitionEntryId: Uuid | null = null,
  ) {}

  isReleased(fiscalYear: number, period: number): boolean {
    return this.released.has(key(fiscalYear, period));
  }

  recordRelease(fiscalYear: number, period: number, amount: Money, date: CalendarDate, entryId: Uuid): void {
    this.released.set(key(fiscalYear, period), { fiscalYear, period, amount, date, entryId });
  }

  releasedTotal(): Money {
    let sum = this.amount.subtract(this.amount);
    for (const release of this.released.values()) sum = sum.add(release.amount);
    return sum;
  }

  outstanding(): Money {
    return this.amount.subtract(this.releasedTotal());
  }

  isSettled(): boolean {
    return this.outstanding().isZero();
  }

  /** The instalment due for a period, or null where the plan has none. */
  instalmentFor(fiscalYear: number, period: number): Money | null {
    for (const entry of this.plan) {
      if (entry.fiscalYear === fiscalYear && entry.period === period) return entry.amount;
    }
    return null;
  }

  releases(): DeferralRelease[] {
    // Sorted, because a map keyed by period has whatever order the releases happened in and the
    // register must read the same after a restart as before one.
    return [...this.released.values()].sort((a, b) =>
      a.fiscalYear !== b.fiscalYear ? a.fiscalYear - b.fiscalYear : a.period - b.period,
    );
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      kind: this.kind,
      reason: this.reason,
      account: this.account.toString(),
      counterAccount: this.counterAccount.toString(),
      recognizedOn: this.recognizedOn.iso,
      amount: this.amount.toJSON(),
      recognitionEntryId: this.recognitionEntryId?.value ?? null,
      plan: this.plan.map((entry) => ({
        fiscalYear: entry.fiscalYear,
        period: entry.period,
        amount: entry.amount.toJSON(),
      })),
      released: this.releases().map((release) => ({
        fiscalYear: release.fiscalYear,
        period: release.period,
        amount: release.amount.toJSON(),
        date: release.date.iso,
        entryId: release.entryId.value,
      })),
    };
  }

  static restore(
    id: Uuid,
    kind: string,
    reason: string,
    account: AccountNumber,
    counterAccount: AccountNumber,
    recognizedOn: CalendarDate,
    amount: Money,
    plan: DeferralInstalment[],
    released: DeferralRelease[],
    recognitionEntryId: Uuid | null,
  ): Deferral {
    const deferral = new Deferral(
      id,
      kind,
      reason,
      account,
      counterAccount,
      recognizedOn,
      amount,
      plan,
      recognitionEntryId,
    );
    for (const release of released) {
      deferral.released.set(key(release.fiscalYear, release.period), release);
    }
    return deferral;
  }
}
