import { DomainError } from '../domain-error.js';
import type { Money } from '../substrate/money.js';
import type { PeriodRef } from '../substrate/period-ref.js';
import type { Uuid } from '../substrate/uuid.js';

/**
 * Abrechnungslauf (costing-modell.md Aggregat 1): je Periode + Version eindeutig;
 * Wiederholung erzeugt neue Version. draft → released.
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
  ) {}

  status(): string {
    return this.runStatus;
  }

  release(): void {
    if (this.runStatus === 'released') {
      throw new DomainError(
        'E_COSTING_RUN_RELEASED',
        `Lauf ${this.id.value} ist bereits freigegeben — Änderungen erzeugen eine neue Version`,
        { runId: this.id.value },
      );
    }
    this.runStatus = 'released';
  }
}
