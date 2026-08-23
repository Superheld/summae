import { DomainError, rejectedValue } from '../../../domain-error.js';
import type { AccountRepository, JournalRepository } from '../../../port.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import { PeriodRef } from '../../../substrate/period-ref.js';
import { Uuid } from '../../../substrate/uuid.js';
import { CostingRun } from './costing-run.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

interface Receiver {
  code: string;
  share: string;
}
interface Step {
  sender: string;
  receivers: Receiver[];
}

/**
 * Cost accounting (costing-modell.md): own accounting circle — the financial-accounting
 * journal stays untouched. Primary-cost intake via the costCenter dimension, allocation by
 * step ladder (acyclic, E_COSTING_CYCLE), distribution by Money.allocate.
 */
export class CostingService {
  private schemeSteps: Step[] = [];
  private readonly runs = new Map<string, CostingRun>();
  private readonly versions = new Map<string, number>();

  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly ids: IdGenerator,
    // The allocation scheme is a tenant-level singleton — see TaxService for why the
    // audit record names the tenant as its object (F-CORE-014 "Profile").
    private readonly tenantId: Uuid | null = null,
    private readonly audit: AuditWriter | null = null,
  ) {}

  setAllocationScheme(input: Record<string, unknown>): Record<string, unknown> {
    const previousStepCount = this.schemeSteps.length;
    const method = typeof input.method === 'string' ? input.method : 'step_ladder';
    const steps: Step[] = [];
    const edges = new Map<string, string[]>();

    for (const rawStep of Array.isArray(input.steps) ? input.steps : []) {
      if (!isRecord(rawStep) || typeof rawStep.sender !== 'string') {
        throw new InvalidValue('allocation step requires sender');
      }
      const sender = rawStep.sender;
      const receivers: Receiver[] = [];
      for (const rawReceiver of Array.isArray(rawStep.receivers) ? rawStep.receivers : []) {
        if (!isRecord(rawReceiver) || typeof rawReceiver.code !== 'string') continue;
        receivers.push({ code: rawReceiver.code, share: typeof rawReceiver.share === 'string' ? rawReceiver.share : '1' });
        const list = edges.get(sender) ?? [];
        list.push(rawReceiver.code);
        edges.set(sender, list);
      }
      steps.push({ sender, receivers });
    }

    if (method === 'step_ladder') this.assertAcyclic(edges);
    this.schemeSteps = steps;

    if (this.audit !== null && this.tenantId !== null) {
      this.audit.record(this.audit.actorOf(input), 'allocationScheme', this.tenantId, 'changed', {
        method: { from: null, to: method },
        stepCount: { from: previousStepCount, to: steps.length },
      });
    }

    return { valid: true, method, stepCount: steps.length };
  }

  run(input: Record<string, unknown>): CostingRun {
    const fiscalYear = typeof input.fiscalYear === 'number' ? input.fiscalYear : 0;
    const period = typeof input.period === 'number' ? input.period : 0;
    const periodRef = new PeriodRef(fiscalYear, period);

    const zero = Money.zero(this.baseCurrency);
    const primary = new Map<string, Money>();

    for (const entry of this.journal.forFiscalYear(fiscalYear)) {
      if (entry.periodRef.period !== period) continue;
      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null || account.type !== 'expense') continue;
        for (const dimension of line.dimensions) {
          if (dimension.type !== 'costCenter') continue;
          const signed = line.side === 'debit' ? line.money : line.money.negate();
          primary.set(dimension.code, (primary.get(dimension.code) ?? zero).add(signed));
        }
      }
    }

    const after = new Map(primary);
    for (const step of this.schemeSteps) {
      const senderTotal = after.get(step.sender) ?? zero;
      if (senderTotal.isZero() || step.receivers.length === 0) continue;
      const weights = step.receivers.map((receiver) => receiver.share);
      const parts = senderTotal.allocate(...weights);
      step.receivers.forEach((receiver, index) => {
        after.set(receiver.code, (after.get(receiver.code) ?? zero).add(parts[index]!));
      });
      after.set(step.sender, zero);
    }

    let grandTotal = zero;
    for (const total of after.values()) grandTotal = grandTotal.add(total);

    const key = `${fiscalYear}-${period}`;
    const version = (this.versions.get(key) ?? 0) + 1;
    this.versions.set(key, version);

    const run = new CostingRun(this.ids.next(), periodRef, version, primary, after, grandTotal);
    this.runs.set(run.id.value, run);
    return run;
  }

  release(input: Record<string, unknown>): CostingRun {
    const run = this.requireRun(input.runId);
    run.release();
    return run;
  }

  costAllocationSheet(params: Record<string, unknown>): Record<string, unknown> {
    const run = this.requireRun(params.runId);

    // The run already fixes fiscal year and period. Passing them alongside was accepted and
    // ignored, so a caller could ask for period 2, receive period 1's numbers, and have nothing
    // in the answer to contradict them. If they are given, they have to agree.
    if (params.fiscalYear !== undefined && params.fiscalYear !== null && params.fiscalYear !== run.period.fiscalYear) {
      throw new DomainError('E_INPUT_INVALID', `costAllocationSheet: the run belongs to fiscal year ${run.period.fiscalYear}`, {
        fiscalYear: rejectedValue(params.fiscalYear),
      });
    }
    if (params.period !== undefined && params.period !== null && params.period !== run.period.period) {
      throw new DomainError('E_INPUT_INVALID', `costAllocationSheet: the run belongs to period ${run.period.period}`, {
        period: rejectedValue(params.period),
      });
    }

    return {
      runId: run.id.value,
      status: run.status(),
      version: run.version,
      primary: this.serializeTotals(run.primary),
      afterAllocation: this.serializeTotals(run.afterAllocation),
      grandTotal: run.grandTotal.amountAsString(),
    };
  }

  private serializeTotals(totals: Map<string, Money>): Array<{ costCenter: string; total: string }> {
    const codes = [...totals.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    return codes.map((code) => ({ costCenter: code, total: totals.get(code)!.amountAsString() }));
  }

  private requireRun(runId: unknown): CostingRun {
    let run: CostingRun | null = null;
    if (typeof runId === 'string' && runId !== '') {
      try {
        run = this.runs.get(Uuid.fromString(runId).value) ?? null;
      } catch (error) {
        if (!(error instanceof InvalidValue)) throw error;
      }
    }
    if (run === null) {
      throw new DomainError('E_COSTING_RUN_UNKNOWN', `costing run ${typeof runId === 'string' ? runId : '?'} does not exist`);
    }
    return run;
  }

  private assertAcyclic(edges: Map<string, string[]>): void {
    const visiting = new Set<string>();
    const done = new Set<string>();

    const visit = (node: string): void => {
      if (done.has(node)) return;
      if (visiting.has(node)) {
        throw new DomainError(
          'E_COSTING_CYCLE',
          `allocation cycle via cost center "${node}" — step ladder requires acyclicity`,
          { costCenter: node },
        );
      }
      visiting.add(node);
      for (const next of edges.get(node) ?? []) visit(next);
      visiting.delete(node);
      done.add(node);
    };

    for (const node of edges.keys()) visit(node);
  }
}
