import { DomainError } from '../../../domain-error.js';
import type { AccountNumber } from '../../../substrate/account-number.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Money } from '../../../substrate/money.js';
import type { Uuid } from '../../../substrate/uuid.js';
import type { AssetRoute } from './asset-route.js';

interface Depreciation {
  planMonth: number;
  date: CalendarDate;
  amount: Money;
  entryId: Uuid;
}

/** Last day of the month `monthsToAdd` after `base` (1-based plan months). */
function lastDayOfMonthAfter(base: CalendarDate, monthsToAdd: number): CalendarDate {
  const totalMonth0 = base.month() - 1 + monthsToAdd;
  const year = base.year() + Math.floor(totalMonth0 / 12);
  const month0 = ((totalMonth0 % 12) + 12) % 12;
  const lastDay = new Date(Date.UTC(year, month0 + 1, 0)).getUTCDate();
  return CalendarDate.of(
    `${String(year).padStart(4, '0')}-${String(month0 + 1).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`,
  );
}

/**
 * Asset (assets-modell.md): master data + depreciation schedule + history. Invariants:
 * book value = acquisition cost − Σ depreciations, never < 0; no depreciation before acquisition/after disposal.
 */
export class Asset {
  private readonly depreciations: Depreciation[] = [];
  private disposed = false;
  private disposedOn: CalendarDate | null = null;

  constructor(
    readonly id: Uuid,
    readonly name: string,
    readonly assetClass: string,
    readonly assetAccount: AccountNumber,
    readonly acquisitionCost: Money,
    readonly acquiredOn: CalendarDate,
    readonly route: AssetRoute,
    readonly usefulLifeMonths: number | null,
    readonly monthlySchedule: Money[],
    readonly voucherId: Uuid,
    /**
     * Cost centre and friends, carried by the asset itself (IMPL-023). Depreciation is booked by the
     * machine, month after month, for years — nobody is there to name a dimension at that moment,
     * and a mandatory one on the depreciation account would otherwise make the run impossible. The
     * master record answering it once is also how it works in practice: an asset belongs to a cost
     * centre, and its depreciation belongs there with it.
     */
    readonly dimensions: ReadonlyArray<{ type: string; code: string }> = [],
    /**
     * First month of the depreciation plan. Normally the month of acquisition — pro rata temporis,
     * which is what linear depreciation asks for in most jurisdictions.
     *
     * A pooled asset can be different: where a jurisdiction dissolves its pool in equal *fiscal-year*
     * fractions, the first year is not shortened by the acquisition month, so the plan starts at the
     * beginning of the fiscal year the asset was acquired in. Which of the two applies is pack data
     * (`poolProRataInFirstYear`), never a decision of this class — the asset is simply told where its
     * plan begins.
     *
     * Null means "same as acquisition", so persisted assets written before this field existed
     * rehydrate to exactly the behaviour they had.
     */
    readonly depreciationStart: CalendarDate | null = null,
  ) {}

  /** Where the depreciation plan begins — the acquisition month unless the pack moved it. */
  planStart(): CalendarDate {
    return this.depreciationStart ?? this.acquiredOn;
  }

  isDisposed(): boolean {
    return this.disposed;
  }

  assertActive(): void {
    if (this.disposed) {
      throw new DomainError(
        'E_ASSET_DISPOSED',
        `asset ${this.id.value} is already disposed (${this.disposedOn?.iso ?? '?'})`,
        { assetId: this.id.value },
      );
    }
  }

  dispose(disposedOn: CalendarDate): void {
    this.assertActive();
    this.disposed = true;
    this.disposedOn = disposedOn;
  }

  planMonthDate(planMonth: number): CalendarDate {
    return lastDayOfMonthAfter(this.planStart(), planMonth - 1);
  }

  isMonthBooked(planMonth: number): boolean {
    return this.depreciations.some((booking) => booking.planMonth === planMonth);
  }

  recordDepreciation(planMonth: number, date: CalendarDate, amount: Money, entryId: Uuid): void {
    this.depreciations.push({ planMonth, date, amount, entryId });
  }

  /** Depreciation history in persistable form — counterpart to PHP's `depreciationsForPersistence`. */
  depreciationsForPersistence(): Array<{
    planMonth: number;
    date: string;
    amount: { amount: string; currency: string };
    entryId: string;
  }> {
    return this.depreciations.map((booking) => ({
      planMonth: booking.planMonth,
      date: booking.date.iso,
      amount: booking.amount.toJSON(),
      entryId: booking.entryId.value,
    }));
  }

  /**
   * Restore from persistence: set master data + depreciation history + disposal
   * status directly (no re-validation) — counterpart to PHP's `Asset::restore`.
   */
  static restore(
    id: Uuid,
    name: string,
    assetClass: string,
    assetAccount: AccountNumber,
    acquisitionCost: Money,
    acquiredOn: CalendarDate,
    route: AssetRoute,
    usefulLifeMonths: number | null,
    monthlySchedule: Money[],
    voucherId: Uuid,
    depreciations: ReadonlyArray<{ planMonth: number; date: CalendarDate; amount: Money; entryId: Uuid }>,
    disposed: boolean,
    disposedOn: CalendarDate | null,
    dimensions: ReadonlyArray<{ type: string; code: string }> = [],
    depreciationStart: CalendarDate | null = null,
  ): Asset {
    const asset = new Asset(
      id,
      name,
      assetClass,
      assetAccount,
      acquisitionCost,
      acquiredOn,
      route,
      usefulLifeMonths,
      monthlySchedule,
      voucherId,
      dimensions,
      depreciationStart,
    );
    for (const booking of depreciations) {
      asset.depreciations.push({
        planMonth: booking.planMonth,
        date: booking.date,
        amount: booking.amount,
        entryId: booking.entryId,
      });
    }
    asset.disposed = disposed;
    asset.disposedOn = disposedOn;
    return asset;
  }

  accumulatedDepreciationAt(asOf: CalendarDate | null): Money {
    let sum = this.acquisitionCost.subtract(this.acquisitionCost); // 0 in tenant currency
    for (const booking of this.depreciations) {
      if (asOf !== null && booking.date.isAfter(asOf)) continue;
      sum = sum.add(booking.amount);
    }
    return sum;
  }

  /**
   * Carrying amount = cost less what has been depreciated (IMPL-024).
   *
   * Only an immediately expensed asset has no carrying amount — it was never capitalised. A
   * *pooled* one was: it sits on the pool account and is written down over the pack's term, so
   * reporting zero for it made the fixed-asset schedule (F-AST-005) understate the balance sheet
   * it is supposed to explain. The old shortcut was invisible while nothing consumed the value
   * for pooled assets; the disposal write-off does now.
   */
  bookValueAt(asOf: CalendarDate | null): Money {
    if (this.route === 'immediate_expense') return this.acquisitionCost.subtract(this.acquisitionCost);
    return this.acquisitionCost.subtract(this.accumulatedDepreciationAt(asOf));
  }

  scheduleSummary(): Record<string, string> {
    if (this.monthlySchedule.length === 0) return {};

    const summary: Record<string, string> = {};
    let total = this.acquisitionCost.subtract(this.acquisitionCost);
    let runStart = 1;

    this.monthlySchedule.forEach((amount, index) => {
      total = total.add(amount);
      const isLast = index === this.monthlySchedule.length - 1;
      const next = isLast ? null : this.monthlySchedule[index + 1]!;
      if (next !== null && next.equals(amount)) return;
      summary[`months${runStart}to${index + 1}`] = amount.amountAsString();
      runStart = index + 2;
    });

    summary.total = total.amountAsString();
    return summary;
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      name: this.name,
      assetClass: this.assetClass,
      assetAccount: this.assetAccount.value,
      route: this.route,
      acquisitionCost: this.acquisitionCost.toJSON(),
      acquiredOn: this.acquiredOn.iso,
      usefulLifeMonths: this.usefulLifeMonths,
      status: this.disposed ? 'disposed' : 'active',
      disposedOn: this.disposedOn?.iso ?? null,
      voucherId: this.voucherId.value,
      dimensions: this.dimensions.map((d) => ({ type: d.type, code: d.code })),
    };
  }
}
