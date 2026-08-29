import type { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Money } from '../../../substrate/money.js';
import type { PeriodRef } from '../../../substrate/period-ref.js';
import type { Uuid } from '../../../substrate/uuid.js';

export interface InventoryCategoryRow {
  account: string;
  quantity: string;
  unitCost: string;
  marketValue: string | null;
  unitValue: string;
  source: string;
  openingValue: string;
  closingValue: string;
  change: string;
  changeAccount: string;
  writtenDownToMarket: boolean;
}

/**
 * One act of valuing stock, recorded (F-CORE-050).
 *
 * **What this is not, first, because that is the whole design.** It is not a stock ledger. summae
 * does not know what is in the warehouse and never claims to: quantities arrive as *input* to a
 * valuation and are not carried forward, there are no goods movements, no bills of material and no
 * product master. Those are the embedding application's data.
 *
 * **What it is** is the same thing `Asset` is one layer over: summae does not own the machine, it
 * owns the register and the postings. Here it owns the *act of valuing* — which accounts, which
 * quantities, at what unit value, where that value came from, what the comparison with a market
 * value did, and which entry it produced. Keeping that is not optional bookkeeping tidiness: an
 * engine that posts a change in stock and keeps no record of how it reached the number has done
 * exactly what this project refuses to let an embedder do, one level down.
 *
 * **Versioned per period, like a costing run, and for the same reason.** Repetition creates a new
 * version rather than overwriting one. The posting is always the *difference* to what the accounts
 * already carry, so a second valuation of an unchanged period books nothing and records that it
 * booked nothing (`entryId: null`) — self-correcting rather than duplicating, without an
 * idempotency key to get wrong.
 *
 * Every figure is a string at the currency's scale. The quantity is a string too: it is not Money,
 * it is not rounded, and it must survive a round trip through JSON byte-identically in both
 * languages.
 */
export class InventoryValuation {
  constructor(
    readonly id: Uuid,
    readonly period: PeriodRef,
    readonly version: number,
    readonly valuationDate: CalendarDate,
    readonly runId: Uuid | null,
    readonly categories: InventoryCategoryRow[],
    readonly closingTotal: Money,
    readonly change: Money,
    readonly entryId: Uuid | null,
  ) {}

  /**
   * Persistable form.
   *
   * Frozen, like a released costing run: the categories carry the unit values the act used, not the
   * ones the configuration would produce today. A valuation that answers differently tomorrow is
   * not a valuation of anything.
   */
  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      period: this.period.toJSON(),
      version: this.version,
      valuationDate: this.valuationDate.iso,
      runId: this.runId?.value ?? null,
      categories: this.categories,
      closingTotal: this.closingTotal.toJSON(),
      change: this.change.toJSON(),
      entryId: this.entryId?.value ?? null,
    };
  }

  static restore(
    id: Uuid,
    period: PeriodRef,
    version: number,
    valuationDate: CalendarDate,
    runId: Uuid | null,
    categories: InventoryCategoryRow[],
    closingTotal: Money,
    change: Money,
    entryId: Uuid | null,
  ): InventoryValuation {
    return new InventoryValuation(
      id,
      period,
      version,
      valuationDate,
      runId,
      categories,
      closingTotal,
      change,
      entryId,
    );
  }
}
