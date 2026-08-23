import { DomainError, rejectedValue } from '../../../domain-error.js';
import type { AccountRepository, JournalRepository } from '../../../port.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import { PeriodRef } from '../../../substrate/period-ref.js';
import { Uuid } from '../../../substrate/uuid.js';
import { Rational } from '../../../substrate/rational.js';
import { CostingRun } from './costing-run.js';
import { solveSimultaneously } from './simultaneous-allocation.js';

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
/**
 * The two ways this core allocates internal services. A method it cannot perform is refused rather
 * than approximated: until now `method` was read, echoed back in the answer and then ignored, so
 * asking for the simultaneous method returned step-ladder numbers under the name of a different
 * procedure — the worst shape a defect can take, because the answer asserts it did what was asked.
 */
const METHODS = ['step_ladder', 'simultaneous'];

export class CostingService {
  private schemeSteps: Step[] = [];
  private method = 'step_ladder';
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

    if (!METHODS.includes(method)) {
      throw new DomainError(
        'E_INPUT_INVALID',
        `setAllocationScheme: unknown allocation method "${method}" — this core allocates by ${METHODS.join(' or ')}`,
        { method: rejectedValue(method) },
      );
    }

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
    this.method = method;

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

    // Allocation: distribute, never create — by either method.
    const after =
      this.method === 'simultaneous'
        ? this.allocateSimultaneously(primary)
        : this.allocateByStepLadder(primary);

    let grandTotal = zero;
    for (const total of after.values()) grandTotal = grandTotal.add(total);

    const key = `${fiscalYear}-${period}`;
    const version = (this.versions.get(key) ?? 0) + 1;
    this.versions.set(key, version);

    const run = new CostingRun(this.ids.next(), periodRef, version, primary, after, grandTotal, this.method);
    this.runs.set(run.id.value, run);
    if (this.audit !== null) {
      this.audit.record(this.audit.actorOf(input), 'costingRun', run.id, 'created', {
        period: { from: null, to: `${periodRef.fiscalYear}/${periodRef.period}` },
        method: { from: null, to: this.method },
        version: { from: null, to: version },
        status: { from: null, to: run.status() },
      });
    }

    return run;
  }

  release(input: Record<string, unknown>): CostingRun {
    const run = this.requireRun(input.runId);
    const before = run.status();
    run.release();
    if (this.audit !== null) {
      this.audit.record(this.audit.actorOf(input), 'costingRun', run.id, 'released', {
        status: { from: before, to: run.status() },
      });
    }

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
      method: run.method,
      primary: this.serializeTotals(run.primary),
      afterAllocation: this.serializeTotals(run.afterAllocation),
      grandTotal: run.grandTotal.amountAsString(),
    };
  }

  /**
   * One pass in step order. Cheap, and wrong the moment two centres serve each other — which is why a
   * cycle is refused here rather than resolved by picking an order.
   */
  private allocateByStepLadder(primary: Map<string, Money>): Map<string, Money> {
    const zero = Money.zero(this.baseCurrency);
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

    return after;
  }

  /**
   * All centres at once, solved exactly (solveSimultaneously) and only then turned back into money.
   *
   * The order matters and is the reason this is not simply "solve and round": the solution is a vector
   * of exact fractions whose sum is the primary total to the last cent, and rounding each one on its
   * own would break that — a cent appears or vanishes, and the sheet no longer says that allocation
   * distributes rather than creates. So the fractions are floored and the difference handed out by
   * largest remainder, ties to the earlier cost centre, which is `Money.allocate`'s rule applied to a
   * vector instead of a single amount.
   */
  private allocateSimultaneously(primary: Map<string, Money>): Map<string, Money> {
    const seen = new Set<string>(primary.keys());
    for (const step of this.schemeSteps) {
      seen.add(step.sender);
      for (const receiver of step.receivers) seen.add(receiver.code);
    }

    const codes = [...seen].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    if (codes.length === 0) return new Map();

    // Minor units throughout: the solver knows nothing about currencies, and an integer count of
    // cents is the one representation in which "the total is preserved" is checkable.
    const scale = this.baseCurrency.scale;
    const toMinor = Rational.of(10n ** BigInt(scale));

    const primaryMinor = new Map<string, Rational>();
    let totalMinor = 0n;
    for (const [code, money] of primary) {
      const value = Rational.fromDecimalString(money.amountAsString()).multiply(toMinor);
      primaryMinor.set(code, value);
      totalMinor += value.floorToBigInt();
    }

    const solved = solveSimultaneously(codes, primaryMinor, this.schemeSteps);
    const senderSet = new Set(solved.senders);
    const keepers = codes.filter((code) => !senderSet.has(code));

    const floors = keepers.map((code) => solved.totals.get(code)!.floorToBigInt());
    const assigned = floors.reduce((sum, value) => sum + value, 0n);

    const order = keepers.map((_, position) => position);
    order.sort((a, b) => {
      const byRemainder = solved.totals
        .get(keepers[b]!)!
        .fractionalPart()
        .compareTo(solved.totals.get(keepers[a]!)!.fractionalPart());
      return byRemainder !== 0 ? byRemainder : a - b;
    });

    const leftover = Number(totalMinor - assigned);
    for (let i = 0; i < leftover; i++) floors[order[i]!] = floors[order[i]!]! + 1n;

    const zero = Money.zero(this.baseCurrency);
    const after = new Map<string, Money>();
    for (const code of codes) after.set(code, zero);
    keepers.forEach((code, position) => {
      after.set(code, this.moneyFromMinor(floors[position]!, scale));
    });

    return after;
  }

  /** Minor units back to Money, exactly — no rounding is left to do at this point. */
  private moneyFromMinor(minor: bigint, scale: number): Money {
    const negative = minor < 0n;
    const digits = (negative ? -minor : minor).toString().padStart(scale + 1, '0');
    const decimal =
      scale === 0 ? digits : `${digits.slice(0, digits.length - scale)}.${digits.slice(digits.length - scale)}`;

    return Money.of(`${negative ? '-' : ''}${decimal}`, this.baseCurrency);
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
