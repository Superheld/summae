import { DomainError } from '../../../domain-error.js';
import type { OverheadRate, RateWarning } from './costing-service.js';
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
}
