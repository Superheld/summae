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
  kind: string;
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

  /**
   * True once an unplanned write-down has rewritten the remaining plan. From then on the schedule IS
   * the plan and may not be re-derived from the acquisition cost — the whole point of the write-down
   * is that the cost is no longer the basis.
   */
  private scheduleRevised = false;

  private reportedUnitsValue = 0;

  /**
   * The schedule as it stood before any write-down rebased it — the *shadow* plan.
   *
   * It exists for one purpose: the write-up ceiling. A write-down does not only reduce the book
   * value, it lowers every remaining planned instalment, so the book value drifts *above* what it
   * would have been without the write-down as the plan runs on. Reversing the write-down in full
   * would therefore carry the asset higher than its amortised acquisition cost, which no write-up
   * may do. The ceiling is `cost − Σ original shares of the booked months`, and the original shares
   * are recoverable from nothing else once a rebase has happened: for a declining-balance or
   * units-of-production plan they were never a flat allocate to begin with.
   */
  private originalSchedule: Money[] = [];

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
    public monthlySchedule: Money[],
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
    /**
     * How the schedule was built. Straight line spreads the cost flat, so the yearly run can
     * re-derive a year's share from the month counts; declining balance cannot, because each year
     * depends on what is left after the one before. The schedule then IS the plan and has to be read
     * rather than recomputed — that is the only thing this field decides.
     *
     * Null means straight line, so assets written before the field existed rehydrate unchanged.
     */
    readonly depreciationMethod: string | null = null,
    /**
     * An additional allowance running ALONGSIDE the plan, not instead of it.
     *
     * Some jurisdictions let a business deduct an extra share of the cost within the first few years,
     * freely distributed over them. It is not a depreciation method — the ordinary plan carries on
     * untouched on the original basis while the window is open — so it cannot be expressed as a
     * different schedule. It is a budget: this much may still be taken, until this fiscal year. Both
     * numbers come from the pack; the asset only remembers them.
     *
     * Null means no such allowance was elected, which is every asset written before this existed.
     */
    readonly specialDepreciationBudget: Money | null = null,
    readonly specialDepreciationWindowEnd: number | null = null,
    /**
     * Total expected output of the asset, where depreciation follows use rather than time —
     * kilometres for a lorry, operating hours for a press, copies for a machine.
     *
     * It changes what "a year" means. Time-based depreciation knows at acquisition what every future
     * period will take; output-based depreciation cannot, because the number comes from outside the
     * books. So there is no schedule to build and the yearly run has nothing to do here: usage is
     * reported as it happens, and each report books the difference between what the asset has now
     * given and what has already been written off.
     */
    readonly totalUnits: number | null = null,
  ) {
    // The shadow plan starts as the plan. It only diverges when a write-down rebases the live one,
    // which is exactly when the write-up ceiling starts to need it.
    this.originalSchedule = [...monthlySchedule];
  }

  /** Units reported so far — never more than `totalUnits`, which is what caps the last booking. */
  reportedUnits(): number {
    return this.reportedUnitsValue;
  }

  recordUsage(date: CalendarDate, amount: Money, entryId: Uuid, unitsAfter: number): void {
    this.reportedUnitsValue = unitsAfter;
    this.depreciations.push({ planMonth: 0, date, amount, entryId, kind: 'usage' });
  }

  /** What is left of the additional allowance. */
  specialDepreciationRemaining(): Money | null {
    if (this.specialDepreciationBudget === null) return null;

    let used = this.specialDepreciationBudget.subtract(this.specialDepreciationBudget);
    for (const booking of this.depreciations) {
      if (booking.kind === 'special') used = used.add(booking.amount);
    }

    return this.specialDepreciationBudget.subtract(used);
  }

  hasSpecialDepreciation(): boolean {
    if (this.specialDepreciationBudget === null) return false;
    return this.depreciations.some((booking) => booking.kind === 'special');
  }

  recordSpecialDepreciation(date: CalendarDate, amount: Money, entryId: Uuid): void {
    // No re-spreading here, unlike a write-down. While the window is open the ordinary plan runs on
    // the original basis — that is what "alongside" means, and lowering it now would quietly take back
    // part of the allowance the same year it was granted. The plan is re-based once, after the window
    // closes.
    this.depreciations.push({ planMonth: 0, date, amount, entryId, kind: 'special' });
  }

  /**
   * Spreads whatever book value is left over the plan months not yet booked.
   *
   * Two occasions need exactly this: an unplanned write-down, where the basis fell; and the end of an
   * additional allowance's window, where part of the cost has already been deducted outside the plan
   * and the rest has to last for the remaining life. Same arithmetic, and it should stay the same
   * arithmetic — two spreadings that drifted apart would be two different answers to one question.
   */
  rebaseRemainingPlan(openPlanMonths: number[]): void {
    this.scheduleRevised = true;

    if (openPlanMonths.length === 0) return;

    const remaining = this.acquisitionCost.subtract(this.accumulatedDepreciationAt(null));
    const shares = remaining.allocate(...openPlanMonths.map(() => 1));

    openPlanMonths.forEach((planMonth, index) => {
      this.monthlySchedule[planMonth - 1] = shares[index]!;
    });
  }

  /** Straight line unless the pack offered, and the caller chose, something else. */
  method(): string {
    return this.depreciationMethod ?? 'straight_line';
  }

  /** A schedule that cannot be re-derived from month counts and must be read as it stands. */
  scheduleIsAuthoritative(): boolean {
    return this.method() !== 'straight_line' || this.scheduleRevised;
  }

  scheduleWasRevised(): boolean {
    return this.scheduleRevised;
  }

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

  recordDepreciation(planMonth: number, date: CalendarDate, amount: Money, entryId: Uuid, kind = 'planned'): void {
    this.depreciations.push({ planMonth, date, amount, entryId, kind });
  }

  /**
   * An unplanned write-down: the amount lowers the book value at once, and what is left is spread over
   * the plan months that have not been booked yet.
   *
   * Re-spreading is the part that is easy to leave out and wrong to leave out. Continuing the old plan
   * after a write-down would depreciate more than the asset is still worth — the invariant says the
   * book value never goes below zero — and stopping early instead would finish the asset before its
   * life is over. Neither is what a lasting impairment means: the reduced value is carried on over the
   * REMAINING life, which is exactly what this does.
   *
   * `planMonth: 0` marks it as belonging to no plan month; plan months are 1-based, so nothing reads
   * it as one, while the accumulated depreciation picks it up like any other booking.
   */
  recordWriteDown(date: CalendarDate, amount: Money, entryId: Uuid, openPlanMonths: number[]): void {
    this.depreciations.push({ planMonth: 0, date, amount, entryId, kind: 'unplanned' });
    this.rebaseRemainingPlan(openPlanMonths);
  }

  /**
   * A write-up reverses part of an earlier write-down. It is recorded as a negative "unplanned"
   * booking, so every existing reader — accumulated depreciation, the book value, the register —
   * picks it up without a special case, and the plan is rebased upward the same way a write-down
   * rebases it downward.
   */
  recordWriteUp(date: CalendarDate, amount: Money, entryId: Uuid, openPlanMonths: number[]): void {
    this.depreciations.push({ planMonth: 0, date, amount: amount.negate(), entryId, kind: 'writeUp' });
    this.rebaseRemainingPlan(openPlanMonths);
  }

  /**
   * What the book value would be if no write-down had ever happened — the ceiling a write-up may
   * not cross.
   *
   * Every month the live plan has booked is charged at its **original** share instead of the reduced
   * one. An asset that was never written down has an identical shadow, so this equals the ordinary
   * book value and the ceiling binds nothing.
   */
  amortisedCostCeiling(): Money {
    let shadowAccumulated = this.acquisitionCost.subtract(this.acquisitionCost);

    for (const booking of this.depreciations) {
      // A write-down or a usage report is not part of any plan — the shadow ignores it, which is
      // the whole point: the shadow is the plan that never saw the write-down.
      if (booking.planMonth < 1) continue;
      const share = this.originalSchedule[booking.planMonth - 1];
      if (share !== undefined) shadowAccumulated = shadowAccumulated.add(share);
    }

    return this.acquisitionCost.subtract(shadowAccumulated);
  }

  /** What has been written down and not yet written back — nothing may be reversed twice. */
  unreversedWriteDowns(): Money {
    let sum = this.acquisitionCost.subtract(this.acquisitionCost);

    for (const booking of this.depreciations) {
      // A write-up booking is already negative, so adding it subtracts.
      if (booking.kind === 'unplanned' || booking.kind === 'writeUp') sum = sum.add(booking.amount);
    }

    return sum;
  }

  originalScheduleShares(): Money[] {
    return this.originalSchedule;
  }

  /** Depreciation history in persistable form — counterpart to PHP's `depreciationsForPersistence`. */
  depreciationsForPersistence(): Array<{
    planMonth: number;
    date: string;
    amount: { amount: string; currency: string };
    entryId: string;
    kind: string;
  }> {
    return this.depreciations.map((booking) => ({
      planMonth: booking.planMonth,
      date: booking.date.iso,
      amount: booking.amount.toJSON(),
      entryId: booking.entryId.value,
      kind: booking.kind,
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
    depreciations: ReadonlyArray<{ planMonth: number; date: CalendarDate; amount: Money; entryId: Uuid; kind?: string }>,
    disposed: boolean,
    disposedOn: CalendarDate | null,
    dimensions: ReadonlyArray<{ type: string; code: string }> = [],
    depreciationStart: CalendarDate | null = null,
    depreciationMethod: string | null = null,
    scheduleRevised = false,
    specialDepreciationBudget: Money | null = null,
    specialDepreciationWindowEnd: number | null = null,
    totalUnits: number | null = null,
    reportedUnits = 0,
    // Null for an asset written before the shadow plan existed — and that is the right answer rather
    // than a fallback: such an asset either has no write-down (so the shadow IS the live plan) or one
    // booked before a write-up was possible at all.
    originalSchedule: Money[] | null = null,
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
      depreciationMethod,
      specialDepreciationBudget,
      specialDepreciationWindowEnd,
      totalUnits,
    );
    asset.reportedUnitsValue = reportedUnits;
    for (const booking of depreciations) {
      asset.depreciations.push({
        planMonth: booking.planMonth,
        date: booking.date,
        amount: booking.amount,
        entryId: booking.entryId,
        // A booking written before write-downs existed is a planned one — that is what it was.
        kind: booking.kind ?? 'planned',
      });
    }
    asset.scheduleRevised = scheduleRevised;
    asset.originalSchedule = originalSchedule ?? [...monthlySchedule];
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
      depreciationMethod: this.method(),
    };
  }
}
