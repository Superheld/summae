import { DomainError, rejectedValue } from '../../../domain-error.js';
import type { AccountRepository,
  CostingRunRepository, JournalRepository } from '../../../port.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { TenantConfigStore } from '../../../composition/tenant-config-store.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import { PeriodRef } from '../../../substrate/period-ref.js';
import { Uuid } from '../../../substrate/uuid.js';
import { AccountNumber } from '../../../substrate/account-number.js';
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

interface RateDefinition {
  costCenter: string;
  label: string;
  accounts: string[];
  costCenters: string[];
}

export interface OverheadRate {
  costCenter: string;
  label: string;
  overhead: string;
  base: string;
  rate: string | null;
}

export interface RateWarning {
  costCenter: string;
  reason: string;
}

interface ComponentDefinition {
  id: string;
  treatment: string;
  included: boolean;
  accounts: string[];
  costCenters: string[];
}

export interface ProductionCostComponent {
  id: string;
  amount: string;
  treatment: string;
  included: boolean;
}

export interface ProductionCostResult {
  total: string;
  components: ProductionCostComponent[];
}

/** A percentage with four decimals, commercial half-up (away from zero), like everything else here. */
function formatRate(value: Rational): string {
  const scaled = value.multiply(Rational.of(10n ** 4n));
  const negative = scaled.isNegative();
  const magnitude = negative ? scaled.negate() : scaled;
  const rounded = magnitude.add(Rational.of(1, 2)).floorToBigInt();
  const digits = rounded.toString().padStart(5, '0');
  const decimal = `${digits.slice(0, digits.length - 4)}.${digits.slice(digits.length - 4)}`;

  return `${negative && rounded !== 0n ? '-' : ''}${decimal}`;
}

function stringList(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
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

  /**
   * The pack's answer to "what may enter production cost". Data, never code — which components a
   * jurisdiction requires, permits or forbids is the part that differs, and the summation is not.
   */
  private ruleModule: Record<string, unknown> = {};

  /**
   * Production-cost component definitions and the tenant's election among the optional ones, frozen
   * into each run for the same reason the rates are.
   */
  private productionCostConfig: ComponentDefinition[] | null = null;

  /**
   * Overhead rate definitions, part of the same tenant-level configuration as the scheme.
   *
   * They sit on `setAllocationScheme` rather than on an operation of their own because a rate is
   * computed FROM an allocation and frozen INTO the run: a second operation would need its own
   * freezing rule against the same run, and two rules for one moment is how they drift apart.
   */
  private rateDefinitions: RateDefinition[] = [];
  /** A stored scheme waiting for its first use — see `restoreAllocationScheme`. */
  private pendingScheme: Record<string, unknown> | null = null;
  /**
   * The scheme as it was given, kept for `tenantConfiguration` to report.
   *
   * The raw input rather than the parsed fields, for the same reason the store keeps the raw input:
   * it is exactly what `setAllocationScheme` accepts, so what comes out can be put back in and no
   * second serializer can drift from the first one.
   */
  private schemeData: Record<string, unknown> | null = null;
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    /**
     * Where the runs live (F-KLR-001/004).
     *
     * They used to live in two private `Map`s in this class — the runs themselves and a per-period
     * version counter — which meant a released run was gone with the process that produced it and
     * the version restarted at 1 after every restart. The requirements had said otherwise all
     * along: runs are versioned per period, and the BAB and the rates are a projection *of a
     * released run*. A run no later process can read satisfies neither; it satisfies them inside
     * one process, which is not what a repository port is for.
     */
    private readonly runs: CostingRunRepository,
    private readonly ids: IdGenerator,
    // The allocation scheme is a tenant-level singleton — see TaxService for why the
    // audit record names the tenant as its object (F-CORE-014 "Profile").
    private readonly tenantId: Uuid | null = null,
    private readonly audit: AuditWriter | null = null,
    /** Where the scheme is kept, so it outlives this object (SPEC-015). */
    private readonly configStore: TenantConfigStore | null = null,
  ) {}

  /** The resolved pack bundle (`productionCost` is read here). */
  setRuleModule(ruleModule: Record<string, unknown>): void {
    this.ruleModule = ruleModule;
  }

  /**
   * Hands back a stored scheme when a tenant is opened (SPEC-015).
   *
   * **Deferred on purpose.** A scheme can reference production-cost treatments, which only the pack
   * answers — and the pack arrives through `setRuleModule`, *after* the factory has built the
   * tenant. Applying it here would make opening the books fail on a scheme that was perfectly valid
   * when it was set, which is the wrong moment to find out and the wrong thing to block: reading a
   * journal does not need an allocation scheme.
   *
   * So it is applied on first use — `setAllocationScheme` and `run` are the only entry points that
   * read it. A stored scheme that the *current* pack no longer accepts then fails when somebody runs
   * a costing, with the error the operation itself would have given.
   */
  restoreAllocationScheme(input: Record<string, unknown>): void {
    this.pendingScheme = input;
  }

  /** Applies what `restoreAllocationScheme` handed back, once, at the moment it is first needed. */
  private applyPendingScheme(): void {
    if (this.pendingScheme === null) return;
    const pending = this.pendingScheme;
    this.pendingScheme = null;
    this.applyAllocationScheme(pending);
  }

  setAllocationScheme(input: Record<string, unknown>): Record<string, unknown> {
    this.applyPendingScheme();
    const previousStepCount = this.schemeSteps.length;
    const previousRateCount = this.rateDefinitions.length;
    const result = this.applyAllocationScheme(input);

    if (this.audit !== null && this.tenantId !== null) {
      this.audit.record(this.audit.actorOf(input), 'allocationScheme', this.tenantId, 'changed', {
        method: { from: null, to: result.method },
        stepCount: { from: previousStepCount, to: result.stepCount },
        rateCount: { from: previousRateCount, to: result.rateCount },
      });
    }
    // The raw input, not the parsed fields: it is exactly what this method accepts, so reloading it
    // on the next open runs the same validation again rather than a second, drifting reader.
    this.configStore?.rememberAllocationScheme(input);

    return result;
  }

  /**
   * The allocation scheme in force, as it was set — what `tenantConfiguration` reports, or null when
   * none was ever set.
   *
   * Reads the *pending* scheme first and deliberately does not apply it. A stored scheme may name
   * production-cost treatments only the current pack answers, so applying it is what
   * `restoreAllocationScheme` defers to first use — and a projection is the wrong place to find out:
   * reporting what a tenant is configured as must not fail on a scheme that was valid when it was
   * set. Reporting it unapplied is the honest answer, because unapplied is what it is.
   */
  allocationScheme(): Record<string, unknown> | null {
    return this.pendingScheme ?? this.schemeData;
  }

  private applyAllocationScheme(input: Record<string, unknown>): {
    valid: boolean;
    method: string;
    stepCount: number;
    rateCount: number;
    productionCostComponents: number;
  } {
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

    const rates: RateDefinition[] = [];
    for (const rawRate of Array.isArray(input.rates) ? input.rates : []) {
      if (!isRecord(rawRate) || typeof rawRate.costCenter !== 'string') {
        throw new DomainError('E_INPUT_INVALID', 'setAllocationScheme: an overhead rate requires "costCenter"', {
          field: 'rates',
        });
      }

      const base = isRecord(rawRate.base) ? rawRate.base : {};
      rates.push({
        costCenter: rawRate.costCenter,
        label: typeof rawRate.label === 'string' ? rawRate.label : rawRate.costCenter,
        accounts: stringList(base.accounts),
        costCenters: stringList(base.costCenters),
      });
    }

    let productionCost: ComponentDefinition[] | null = null;
    if (isRecord(input.productionCost)) {
      productionCost = this.resolveProductionCost(input.productionCost);
    }

    if (method === 'step_ladder') this.assertAcyclic(edges);
    this.schemeSteps = steps;
    this.method = method;
    this.rateDefinitions = rates;
    this.productionCostConfig = productionCost;
    this.schemeData = input;

    return {
      valid: true,
      method,
      stepCount: steps.length,
      rateCount: rates.length,
      productionCostComponents: productionCost === null ? 0 : productionCost.length,
    };
  }

  run(input: Record<string, unknown>): CostingRun {
    this.applyPendingScheme();
    const fiscalYear = typeof input.fiscalYear === 'number' ? input.fiscalYear : 0;
    const period = typeof input.period === 'number' ? input.period : 0;
    const periodRef = new PeriodRef(fiscalYear, period);

    const zero = Money.zero(this.baseCurrency);
    const primary = new Map<string, Money>();
    const accountTotals = new Map<string, Money>();

    for (const entry of this.journal.forFiscalYear(fiscalYear)) {
      if (entry.periodRef.period !== period) continue;
      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null) continue;

        // Direct costs — the denominator of an overhead rate — are booked WITHOUT a cost centre:
        // they belong to the product, not to a department. So they are collected per account here,
        // in the same pass, and not through the costCenter dimension.
        const number = account.number.toString();
        const signedLine = line.side === 'debit' ? line.money : line.money.negate();
        accountTotals.set(number, (accountTotals.get(number) ?? zero).add(signedLine));

        if (account.type !== 'expense') continue;
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

    const computed = this.computeRates(after, accountTotals);
    const productionCost = this.computeProductionCost(after, accountTotals);

    // The next version comes from what is stored, not from a counter in this object: a counter
    // starts at zero in every new process, so the second run of a period would have claimed to be
    // its first.
    const version = this.nextVersionFor(fiscalYear, period);

    const run = new CostingRun(
      this.ids.next(),
      periodRef,
      version,
      primary,
      after,
      grandTotal,
      this.method,
      computed.rates,
      computed.warnings,
      productionCost,
    );
    this.runs.add(run);
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
    this.runs.save(run);
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
   * Overhead rates of a run (F-KLR-004: "BAB *und Kalkulationssätze*").
   *
   * A rate answers the question the allocation sheet cannot: the sheet says what a cost centre ended
   * up carrying, a rate says how that attaches to a product. Numerator is the centre after allocation,
   * denominator is a base the scheme declares — direct-cost ACCOUNTS, other cost CENTRES, or both. The
   * classic four fall straight out of that: material and production overhead over their direct costs,
   * administration and sales overhead over cost of production, which is "the direct-cost accounts plus
   * the two centres" and needs no special case.
   *
   * Rounded to four decimals, half-up away from zero — a rate is a published figure, not money, and
   * four places is where the pack schema already puts a percentage.
   *
   * Note what this deliberately does NOT do: refuse a draft run. F-KLR-001 says evaluations read
   * released runs only, and the fixture `parameter-effect` reads a draft sheet — an append-only
   * contract that says otherwise. Bending the fixture would rewrite what the contract always said, so
   * the rule is followed as the contract has it and the contradiction is recorded (FINDINGS-CLOSED.md).
   * `status` is in the answer, so nobody has to guess which they got.
   */
  overheadRates(params: Record<string, unknown>): Record<string, unknown> {
    const run = this.requireRun(params.runId);

    return {
      runId: run.id.value,
      status: run.status(),
      version: run.version,
      method: run.method,
      rates: run.rates,
      warnings: run.rateWarnings,
    };
  }

  /**
   * Production cost of a run, component by component (F-KLR-004's balance-sheet neighbour).
   *
   * Every configured component appears, whether it was counted or not, with the pack's treatment next
   * to it — `mandatory`, `optional` or `forbidden` — and whether it went in. A valuation that shows
   * only its own total is unauditable; this one shows what it left out and on whose authority.
   */
  productionCost(params: Record<string, unknown>): Record<string, unknown> {
    const run = this.requireRun(params.runId);

    if (run.productionCost === null) {
      throw new DomainError(
        'E_INPUT_INVALID',
        `run ${run.id.value} has no production-cost components — declare them in setAllocationScheme before the run`,
        { runId: run.id.value },
      );
    }

    return {
      runId: run.id.value,
      status: run.status(),
      version: run.version,
      total: run.productionCost.total,
      components: run.productionCost.components,
    };
  }

  /**
   * Production cost of the period — the figure inventory is carried at.
   *
   * This is the one piece of cost accounting with balance-sheet effect, so what may be counted into it
   * is law rather than preference. The split falls exactly along the socket/plug line the rest of the
   * core uses — **this method sums, the pack says what may enter.** One jurisdiction requires material
   * and production cost with their overhead and the production-related depreciation, leaves general
   * administration to the preparer, and forbids research and distribution; another treats general
   * administration as a period charge and so reaches a different inventory value from identical books.
   * None of that is written here, and none of it should be: which of the three treatments a component
   * gets is a row in the pack, not a branch in this method.
   *
   * Three rules, and each refuses rather than guesses:
   *
   * - a component the pack does not declare is `E_PACK_INCOHERENT` — an unknown component silently
   *   counted or silently dropped would move the balance sheet either way;
   * - electing a component the pack forbids is `E_INPUT_INVALID`, not a quiet exclusion, because the
   *   caller has said something about their books that is not allowed;
   * - asking for the figure without configuring the components is `E_INPUT_INVALID` rather than 0.00,
   *   since a valuation nobody set up is not a valuation of zero.
   *
   * What this deliberately does NOT do is divide by a quantity. Production cost per unit needs produced
   * quantities, and the core carries no quantities at all — goods movements and production orders are
   * the embedding application's data. summae answers what the components add up to and why; the
   * division is arithmetic on top of that.
   */
  private computeProductionCost(
    after: Map<string, Money>,
    accountTotals: Map<string, Money>,
  ): ProductionCostResult | null {
    if (this.productionCostConfig === null) return null;

    const zero = Money.zero(this.baseCurrency);
    let total = zero;
    const components: ProductionCostComponent[] = [];

    for (const component of this.productionCostConfig) {
      let amount = zero;

      for (const number of component.accounts) {
        if (this.accounts.byNumber(AccountNumber.of(number)) === null) {
          throw new DomainError(
            'E_ACCOUNT_UNKNOWN',
            `production-cost component "${component.id}" names account ${number}, which does not exist`,
            { account: number },
          );
        }
        amount = amount.add(accountTotals.get(number) ?? zero);
      }

      for (const code of component.costCenters) amount = amount.add(after.get(code) ?? zero);

      if (component.included) total = total.add(amount);

      // Excluded components stay in the answer with their amount and the reason. A valuation that
      // shows only what it counted cannot be checked against the law it claims to follow.
      components.push({
        id: component.id,
        amount: amount.amountAsString(),
        treatment: component.treatment,
        included: component.included,
      });
    }

    return { total: total.amountAsString(), components };
  }

  /**
   * Applies the pack's treatment table to a production-cost configuration — at the moment the
   * configuration is set, not at the moment the run happens, so a caller learns about a refusal where
   * they can still do something about it.
   */
  private resolveProductionCost(raw: Record<string, unknown>): ComponentDefinition[] {
    const treatments = this.treatmentTable();
    const elected = stringList(raw.include);

    for (const componentId of elected) {
      const treatment = treatments.get(componentId);

      if (treatment === undefined) {
        throw new DomainError(
          'E_PACK_INCOHERENT',
          `production-cost component "${componentId}" was elected, but the pack declares no treatment for it`,
          { component: componentId },
        );
      }

      if (treatment === 'forbidden') {
        throw new DomainError(
          'E_INPUT_INVALID',
          `production-cost component "${componentId}" may not be capitalised under this pack`,
          { component: componentId },
        );
      }
    }

    const components: ComponentDefinition[] = [];
    for (const rawComponent of Array.isArray(raw.components) ? raw.components : []) {
      if (!isRecord(rawComponent) || typeof rawComponent.id !== 'string') {
        throw new DomainError('E_INPUT_INVALID', 'setAllocationScheme: a production-cost component requires "id"', {
          field: 'productionCost.components',
        });
      }

      const treatment = treatments.get(rawComponent.id);

      if (treatment === undefined) {
        throw new DomainError(
          'E_PACK_INCOHERENT',
          `production-cost component "${rawComponent.id}" is not declared by the pack`,
          { component: rawComponent.id },
        );
      }

      const base = isRecord(rawComponent.base) ? rawComponent.base : {};
      components.push({
        id: rawComponent.id,
        treatment,
        included: treatment === 'mandatory' || (treatment === 'optional' && elected.includes(rawComponent.id)),
        accounts: stringList(base.accounts),
        costCenters: stringList(base.costCenters),
      });
    }

    return components;
  }

  /** component id -> mandatory | optional | forbidden */
  private treatmentTable(): Map<string, string> {
    const module = isRecord(this.ruleModule.productionCost) ? this.ruleModule.productionCost : null;

    if (module === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'production cost was asked for, but the pack declares no production-cost treatments',
        { field: 'productionCost' },
      );
    }

    const table = new Map<string, string>();
    for (const row of Array.isArray(module.treatments) ? module.treatments : []) {
      if (!isRecord(row) || typeof row.component !== 'string' || typeof row.treatment !== 'string') continue;
      table.set(row.component, row.treatment);
    }

    return table;
  }

  private computeRates(
    after: Map<string, Money>,
    accountTotals: Map<string, Money>,
  ): { rates: OverheadRate[]; warnings: RateWarning[] } {
    const zero = Money.zero(this.baseCurrency);
    const rates: OverheadRate[] = [];
    const warnings: RateWarning[] = [];

    // Definition order, not alphabetical: an administration rate takes cost of production as its base,
    // so the order the caller wrote is the order that reads correctly. It is deterministic for the
    // same reason the steps are — it comes from the input.
    for (const definition of this.rateDefinitions) {
      const overhead = after.get(definition.costCenter) ?? zero;
      let base = zero;

      for (const number of definition.accounts) {
        if (this.accounts.byNumber(AccountNumber.of(number)) === null) {
          throw new DomainError(
            'E_ACCOUNT_UNKNOWN',
            `overhead rate for cost center "${definition.costCenter}" names account ${number}, which does not exist`,
            { account: number },
          );
        }
        base = base.add(accountTotals.get(number) ?? zero);
      }

      for (const code of definition.costCenters) base = base.add(after.get(code) ?? zero);

      let rate: string | null = null;
      if (base.isZero()) {
        // A rate over an empty base is not zero and not infinite, it is undefined — and an undefined
        // number returned as 0.00 would be applied to products as if it meant something. Named
        // instead, in the same shape the cash-basis report uses for its gaps.
        warnings.push({
          costCenter: definition.costCenter,
          reason: 'the base is zero, so no rate can be computed',
        });
      } else {
        rate = formatRate(
          Rational.fromDecimalString(overhead.amountAsString())
            .divide(Rational.fromDecimalString(base.amountAsString()))
            .multiply(Rational.of(100)),
        );
      }

      rates.push({
        costCenter: definition.costCenter,
        label: definition.label,
        overhead: overhead.amountAsString(),
        base: base.amountAsString(),
        rate,
      });
    }

    return { rates, warnings };
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

  /**
   * The next version for a period: one more than the highest stored for it.
   *
   * Derived rather than counted, because a counter in this object starts at zero in every new
   * process — the second run of a period would have claimed to be its first, and F-KLR-001's
   * "versioned per period" would have held only until a restart.
   */
  private nextVersionFor(fiscalYear: number, period: number): number {
    let highest = 0;
    for (const run of this.runs.all()) {
      if (run.period.fiscalYear !== fiscalYear || run.period.period !== period) continue;
      if (run.version > highest) highest = run.version;
    }
    return highest + 1;
  }

  private requireRun(runId: unknown): CostingRun {
    let run: CostingRun | null = null;
    if (typeof runId === 'string' && runId !== '') {
      try {
        run = this.runs.byId(Uuid.fromString(runId));
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
