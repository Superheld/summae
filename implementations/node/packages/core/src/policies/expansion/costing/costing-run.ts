import { DomainError } from '../../../domain-error.js';
import type { OverheadRate, ProductionCostResult, RateWarning } from './costing-service.js';
import type { Money } from '../../../substrate/money.js';
import type { PeriodRef } from '../../../substrate/period-ref.js';
import type { Uuid } from '../../../substrate/uuid.js';

/**
 * Costing run (costing-modell.md aggregate 1): unique per period + version;
 * repetition creates a new version. draft → released.
 */
export class CostingRun {
  private runStatus = 'draft';

  constructor(
    readonly id: Uuid,
    readonly period: PeriodRef,
    readonly version: number,
    readonly primary: Map<string, Money>,
    readonly afterAllocation: Map<string, Money>,
    readonly grandTotal: Money,
    // Which procedure produced these numbers. It belongs in the run because the two answer the same
    // question differently, and a sheet that does not say how it was allocated cannot be checked
    // against anything.
    readonly method: string = 'step_ladder',
    // Frozen with the run, not recomputed on read: the configuration can change after a release, and
    // a released run that answers differently tomorrow is not released.
    readonly rates: OverheadRate[] = [],
    readonly rateWarnings: RateWarning[] = [],
    readonly productionCost: ProductionCostResult | null = null,
  ) {}

  status(): string {
    return this.runStatus;
  }

  release(): void {
    if (this.runStatus === 'released') {
      throw new DomainError(
        'E_COSTING_RUN_RELEASED',
        `run ${this.id.value} is already released — changes create a new version`,
        { runId: this.id.value },
      );
    }
    this.runStatus = 'released';
  }

  /**
   * Persistable form (F-KLR-001/004).
   *
   * A run used to live in a `Map` inside the service, which meant a released run was gone with the
   * process that made it. The requirements had said otherwise all along — runs are versioned per
   * period, and the BAB and the rates are a projection *of a released run* — so a run no later
   * process can read satisfies neither. Everything the three projections read is in here, and
   * nothing else is: what a run answers must not depend on configuration that has moved since.
   */
  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      period: this.period.toJSON(),
      version: this.version,
      status: this.runStatus,
      method: this.method,
      primary: CostingRun.totalsToJSON(this.primary),
      afterAllocation: CostingRun.totalsToJSON(this.afterAllocation),
      grandTotal: this.grandTotal.toJSON(),
      rates: this.rates,
      rateWarnings: this.rateWarnings,
      productionCost: this.productionCost,
    };
  }

  /** Restore from persistence — status taken over directly, no re-validation. */
  static restore(
    id: Uuid,
    period: PeriodRef,
    version: number,
    status: string,
    primary: Map<string, Money>,
    afterAllocation: Map<string, Money>,
    grandTotal: Money,
    method: string,
    rates: OverheadRate[],
    rateWarnings: RateWarning[],
    productionCost: ProductionCostResult | null,
  ): CostingRun {
    const run = new CostingRun(
      id,
      period,
      version,
      primary,
      afterAllocation,
      grandTotal,
      method,
      rates,
      rateWarnings,
      productionCost,
    );
    run.runStatus = status;
    return run;
  }

  /**
   * Cost-centre totals as an object, keys sorted by code point.
   *
   * A JSON object preserves insertion order, so writing the map as it happens to be iterated would
   * make the stored bytes depend on the order the postings arrived in — and the export has to be
   * byte-identical across implementations (SF-15).
   */
  private static totalsToJSON(totals: Map<string, Money>): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    for (const code of [...totals.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0))) {
      out[code] = totals.get(code)!.toJSON();
    }
    return out;
  }
}
