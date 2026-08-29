import Big from 'big.js';
import { DomainError } from '../../../domain-error.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { Ledger } from '../../../ledger/ledger.js';
import type {
  AccountRepository,
  CostingRunRepository,
  InventoryValuationRepository,
  JournalRepository,
  VoucherRepository,
} from '../../../port.js';
import { Voucher } from '../../../records/voucher.js';
import { AccountNumber } from '../../../substrate/account-number.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import { PeriodRef } from '../../../substrate/period-ref.js';
import { Rational } from '../../../substrate/rational.js';
import { Uuid } from '../../../substrate/uuid.js';
import type { CostingRun } from '../costing/costing-run.js';
import { InventoryValuation, type InventoryCategoryRow } from './inventory-valuation.js';

/** Digits after the decimal point a derived unit value is carried at, before the product is rounded. */
const UNIT_SCALE = 6;

/**
 * A decimal string echoed with **its own** scale.
 *
 * The PHP side gets this for free — `BigDecimal::of("12.00")` prints `12.00` — while `Big("12.00")`
 * normalises to `12`. A quantity that reads back as a different string in the two languages breaks
 * byte parity at the first export, which is why the scale of the input is carried rather than
 * inferred from the value.
 */
function decimalString(value: Big, raw: string): string {
  const dot = raw.indexOf('.');
  return value.toFixed(dot === -1 ? 0 : raw.length - dot - 1);
}

/** Half-up away from zero at `UNIT_SCALE`, done exactly — the same rounding brick/math applies. */
function unitValueString(value: Rational): string {
  const scaled = value.multiply(Rational.of(10n ** BigInt(UNIT_SCALE)));
  const negative = scaled.isNegative();
  const magnitude = negative ? scaled.negate() : scaled;
  const rounded = magnitude.add(Rational.of(1n, 2n)).floorToBigInt();
  const digits = rounded.toString().padStart(UNIT_SCALE + 1, '0');
  const decimal = `${digits.slice(0, digits.length - UNIT_SCALE)}.${digits.slice(digits.length - UNIT_SCALE)}`;
  return negative ? `-${decimal}` : decimal;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Stock: measurement and posting (F-CORE-050).
 *
 * **The link that was missing.** Cost accounting justifies itself through a chain — cost centres →
 * allocation → overhead rates → production cost → *inventory valuation* → balance sheet — and only
 * the last link makes it bookkeeping rather than controlling. The middle was built and good; both
 * ends were missing. This is the outer one: the production cost the costing run computes now
 * reaches an account, and that account now reaches a balance-sheet position.
 *
 * **Socket and plug, as everywhere else.** The core knows *that* stock is carried at the lower of
 * its cost and a value the caller supplies, that the difference against what the books already
 * carry is booked, and that the counter-account is whatever the pack names for that category. It
 * knows nothing about *which* accounts, which categories a jurisdiction distinguishes, or whether
 * a change in raw materials belongs with material expense while a change in finished goods is its
 * own line — all of that is the `inventory` module.
 *
 * **The division the costing service refuses to do.** `computeProductionCost` deliberately does not
 * divide by a quantity, on the ground that the core carries no quantities. That is still true: it
 * carries none. Here a quantity is *handed in*, together with the produced quantity the run's total
 * relates to, and the division happens where both are declared inputs of one call. Nothing is
 * stored as a stock and nothing is carried forward.
 *
 * **Lower of cost or market, and why the mechanism is jurisdiction-free while the duty is not.**
 * Given a `marketValue` per unit, the lower of it and the unit cost is used, and the row says so.
 * Whether comparing is a duty, an option or forbidden is the pack's business; the arithmetic of
 * "take the lower of two numbers and say which one you took" is not. A row that only showed its
 * result would be unauditable, exactly as a production cost showing only its total would be.
 */
export class InventoryService {
  private ruleModule: Record<string, unknown>;

  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly vouchers: VoucherRepository,
    private readonly runs: CostingRunRepository,
    private readonly valuations: InventoryValuationRepository,
    private readonly ledger: Ledger,
    private readonly ids: IdGenerator,
    ruleModule: Record<string, unknown> = {},
    private readonly tenantId: Uuid | null = null,
    private readonly audit: AuditWriter | null = null,
  ) {
    this.ruleModule = ruleModule;
  }

  setRuleModule(ruleModule: Record<string, unknown>): void {
    this.ruleModule = ruleModule;
  }

  /** Value the stock of one period and book the change (`valuateInventory`). */
  valuate(input: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof input.fiscalYear === 'number' ? input.fiscalYear : null;
    const period = typeof input.period === 'number' ? input.period : null;

    if (fiscalYear === null || period === null) {
      throw new DomainError('E_INPUT_INVALID', 'valuateInventory: fiscalYear and period are required', {
        field: 'fiscalYear',
      });
    }

    const periodRef = new PeriodRef(fiscalYear, period);
    const valuationDate = this.requireDate(input.valuationDate);
    const categoryAccounts = this.packCategories();
    const run = this.releasedRun(input.runId);
    const producedQuantity = this.optionalDecimal(input.producedQuantity, 'producedQuantity');

    const rows: InventoryCategoryRow[] = [];
    const lines: Array<Record<string, unknown>> = [];
    let closingTotal = Money.zero(this.baseCurrency);
    let change = Money.zero(this.baseCurrency);

    const categories = this.requireCategories(input.categories);
    for (let index = 0; index < categories.length; index += 1) {
      const row = this.valuateCategory(
        categories[index] as Record<string, unknown>,
        index,
        categoryAccounts,
        run,
        producedQuantity,
        periodRef,
      );
      rows.push(row);

      closingTotal = closingTotal.add(Money.of(row.closingValue, this.baseCurrency));
      const rowChange = Money.of(row.change, this.baseCurrency);
      change = change.add(rowChange);

      if (rowChange.isZero()) continue;

      // An increase debits the stock account and credits the account the pack names; a decrease
      // does the reverse. One entry for the whole valuation, because it is one act: splitting it
      // per category would produce a set of entries an auditor has to recognise as belonging
      // together from their dates.
      const stockSide = rowChange.isPositive() ? 'debit' : 'credit';
      const counterSide = rowChange.isPositive() ? 'credit' : 'debit';
      const absolute = rowChange.abs().toJSON();

      lines.push({ account: row.account, side: stockSide, money: absolute });
      lines.push({ account: row.changeAccount, side: counterSide, money: absolute });
    }

    const entryId = lines.length === 0 ? null : this.postMachineEntry(valuationDate, periodRef, lines);

    const valuation = new InventoryValuation(
      this.ids.next(),
      periodRef,
      this.nextVersion(periodRef),
      valuationDate,
      run === null ? null : run.id,
      rows,
      closingTotal,
      change,
      entryId,
    );
    this.valuations.add(valuation);

    if (this.audit !== null && this.tenantId !== null) {
      this.audit.record(this.audit.actorOf(input), 'inventoryValuation', valuation.id, 'valued', {
        fiscalYear: { from: null, to: fiscalYear },
        period: { from: null, to: period },
        closingTotal: { from: null, to: closingTotal.amountAsString() },
        change: { from: null, to: change.amountAsString() },
      });
    }

    return {
      valuationId: valuation.id.value,
      version: valuation.version,
      closingTotal: closingTotal.toJSON(),
      change: change.toJSON(),
      // `false` is an answer, not a failure: a second valuation of an unchanged period books
      // nothing, which is what makes repeating one harmless.
      posted: entryId !== null,
      entryId: entryId?.value ?? null,
    };
  }

  /** What was valued, how, and out of what (`inventoryValuation`). */
  valuationReport(params: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : null;
    const period = typeof params.period === 'number' ? params.period : null;

    const rows = this.valuations
      .all()
      .filter((valuation) => fiscalYear === null || valuation.period.fiscalYear === fiscalYear)
      .filter((valuation) => period === null || valuation.period.period === period)
      .map((valuation) => ({
        valuationId: valuation.id.value,
        fiscalYear: valuation.period.fiscalYear,
        period: valuation.period.period,
        version: valuation.version,
        valuationDate: valuation.valuationDate.iso,
        runId: valuation.runId?.value ?? null,
        categories: valuation.categories,
        closingTotal: valuation.closingTotal.amountAsString(),
        change: valuation.change.amountAsString(),
        entryId: valuation.entryId?.value ?? null,
      }));

    return { valuations: rows };
  }

  private valuateCategory(
    raw: Record<string, unknown>,
    index: number,
    categoryAccounts: Map<string, string>,
    run: CostingRun | null,
    producedQuantity: Big | null,
    periodRef: PeriodRef,
  ): InventoryCategoryRow {
    const number = typeof raw.account === 'string' ? raw.account : '';
    if (number === '') {
      throw new DomainError('E_INPUT_INVALID', `valuateInventory: categories[${index}] requires "account"`, {
        field: 'categories',
      });
    }

    const account = this.accounts.byNumber(AccountNumber.of(number));
    if (account === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `valuateInventory: account ${number} does not exist`, {
        account: number,
      });
    }

    // The reader that earns `inventory` its place in the closed subtype repertoire. Valuing stock
    // onto an account that is not a stock account would balance, satisfy every invariant and put
    // the figure in the wrong balance-sheet position — the inert-annotation defect the repertoire
    // was closed for, with a wrong number instead of a missing one.
    if (account.subtype !== 'inventory') {
      throw new DomainError(
        'E_INVENTORY_ACCOUNT_INVALID',
        `valuateInventory: account ${number} is not a stock account (subtype "inventory")`,
        { account: number, subtype: account.subtype },
      );
    }

    const changeAccount = categoryAccounts.get(number);
    if (changeAccount === undefined) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        `the pack declares no change account for stock account ${number}`,
        { account: number },
      );
    }

    const quantityRaw = this.requireRawString(raw.quantity, `categories[${index}].quantity`);
    const quantity = this.requireDecimal(quantityRaw, `categories[${index}].quantity`);
    if (quantity.lt(0)) {
      throw new DomainError('E_INPUT_INVALID', `valuateInventory: categories[${index}] has a negative quantity`, {
        field: 'categories',
        account: number,
      });
    }

    const [unitCost, unitCostText, source] = this.unitCost(raw, index, run, producedQuantity);
    const marketRaw = raw.marketValue === null || raw.marketValue === undefined
      ? null
      : this.requireRawString(raw.marketValue, `categories[${index}].marketValue`);
    const marketValue = marketRaw === null ? null : this.requireDecimal(marketRaw, `categories[${index}].marketValue`);
    const marketText = marketValue === null || marketRaw === null ? null : decimalString(marketValue, marketRaw);

    const writtenDown = marketValue !== null && marketValue.lt(unitCost);
    const unitValue = writtenDown && marketValue !== null ? marketValue : unitCost;
    const unitValueText = writtenDown && marketText !== null ? marketText : unitCostText;

    // One rounding, on the product — not a rounded unit value multiplied by a quantity. With 3,000
    // units the two differ by up to fifteen euros, and the difference lands in the balance sheet.
    const closingValue = Money.fromCalculation(quantity.times(unitValue), this.baseCurrency);
    const openingValue = this.carryingAmount(number, periodRef);

    return {
      account: number,
      quantity: decimalString(quantity, quantityRaw),
      unitCost: unitCostText,
      marketValue: marketText,
      unitValue: unitValueText,
      source,
      openingValue: openingValue.amountAsString(),
      closingValue: closingValue.amountAsString(),
      change: closingValue.subtract(openingValue).amountAsString(),
      changeAccount,
      writtenDownToMarket: writtenDown,
    };
  }

  /** Where a unit value comes from, and it is never guessed. */
  private unitCost(
    raw: Record<string, unknown>,
    index: number,
    run: CostingRun | null,
    producedQuantity: Big | null,
  ): [Big, string, string] {
    if (raw.unitCost !== null && raw.unitCost !== undefined) {
      const text = this.requireRawString(raw.unitCost, `categories[${index}].unitCost`);
      const value = this.requireDecimal(text, `categories[${index}].unitCost`);
      return [value, decimalString(value, text), 'input'];
    }

    if (run === null) {
      throw new DomainError(
        'E_INPUT_INVALID',
        `valuateInventory: categories[${index}] has no unitCost and no released costing run to derive one from`,
        { field: 'categories' },
      );
    }

    if (run.productionCost === null) {
      throw new DomainError(
        'E_INPUT_INVALID',
        `costing run ${run.id.value} carries no production cost — declare the components in setAllocationScheme before the run`,
        { runId: run.id.value },
      );
    }

    if (producedQuantity === null || producedQuantity.lte(0)) {
      throw new DomainError(
        'E_INPUT_INVALID',
        'valuateInventory: deriving a unit cost from a costing run needs a positive producedQuantity',
        { field: 'producedQuantity' },
      );
    }

    // Exact division and one exact rounding, via Rational rather than Big: a unit cost is not Money
    // — it is an intermediate — and rounding it to cents here would be the error the comment on
    // `closingValue` avoids, moved one line up. Rational is what keeps the result bit-for-bit
    // identical to the PHP side's `dividedBy(q, 6, HalfUp)`.
    const total = Rational.fromDecimalString(run.productionCost.total);
    const divisor = Rational.fromDecimalString(producedQuantity.toFixed());
    const text = unitValueString(total.divide(divisor));

    return [new Big(text), text, 'productionCost'];
  }

  /**
   * What the books already carry on this account, up to and including the valuation period.
   *
   * Cumulative across fiscal years, because a stock account is a balance-carrying account and
   * carries forward implicitly (F-CORE-021) — there is no opening entry to read instead.
   */
  private carryingAmount(number: string, periodRef: PeriodRef): Money {
    let total = Money.zero(this.baseCurrency);

    for (const entry of this.journal.all()) {
      const entryYear = entry.periodRef.fiscalYear;
      if (entryYear > periodRef.fiscalYear) continue;
      if (entryYear === periodRef.fiscalYear && entry.periodRef.period > periodRef.period) continue;

      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null || account.number.toString() !== number) continue;
        total = line.side === 'debit' ? total.add(line.money) : total.subtract(line.money);
      }
    }

    return total;
  }

  /** stock account -> change account */
  private packCategories(): Map<string, string> {
    const module = isRecord(this.ruleModule.inventory) ? this.ruleModule.inventory : null;
    if (module === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'inventory was valued, but the pack declares no inventory categories',
        { field: 'inventory' },
      );
    }

    const map = new Map<string, string>();
    for (const category of Array.isArray(module.categories) ? module.categories : []) {
      if (!isRecord(category)) continue;
      if (typeof category.account === 'string' && typeof category.changeAccount === 'string') {
        map.set(category.account, category.changeAccount);
      }
    }

    return map;
  }

  private releasedRun(runId: unknown): CostingRun | null {
    if (typeof runId !== 'string' || runId === '') return null;

    let run: CostingRun | null;
    try {
      run = this.runs.byId(Uuid.fromString(runId));
    } catch (error) {
      if (!(error instanceof InvalidValue)) throw error;
      run = null;
    }

    if (run === null) {
      throw new DomainError('E_COSTING_RUN_UNKNOWN', `costing run ${runId} does not exist`, { runId });
    }

    // A draft is a working figure. Valuing the balance sheet out of one would put a number in the
    // books that its own producer has not stood behind, and the two conditions need opposite
    // corrections — release it, or name a different run — which is why this is not
    // E_COSTING_RUN_UNKNOWN.
    if (run.status() !== 'released') {
      throw new DomainError(
        'E_COSTING_RUN_NOT_RELEASED',
        `costing run ${runId} is a draft — release it before valuing stock from it`,
        { runId, status: run.status() },
      );
    }

    return run;
  }

  private requireCategories(raw: unknown): Array<Record<string, unknown>> {
    if (!Array.isArray(raw) || raw.length === 0) {
      throw new DomainError(
        'E_INPUT_INVALID',
        'valuateInventory: at least one category is required — a valuation of nothing is not a valuation of zero',
        { field: 'categories' },
      );
    }

    return raw.map((entry) => {
      if (!isRecord(entry)) {
        throw new DomainError('E_INPUT_INVALID', 'valuateInventory: every category must be an object', {
          field: 'categories',
        });
      }
      return entry;
    });
  }

  private requireDate(raw: unknown): CalendarDate {
    if (typeof raw !== 'string' || raw === '') {
      throw new DomainError('E_INPUT_INVALID', 'valuateInventory: valuationDate is required', {
        field: 'valuationDate',
      });
    }

    try {
      return CalendarDate.of(raw);
    } catch (error) {
      if (!(error instanceof InvalidValue)) throw error;
      throw new DomainError('E_INPUT_INVALID', 'valuateInventory: valuationDate is not a calendar date', {
        field: 'valuationDate',
      });
    }
  }

  /**
   * Strings only, exactly as Money takes strings only. A JSON number has already lost the argument
   * before it arrives: 0.1 is not 0.1, and a quantity that reads back differently in the two
   * languages breaks byte parity at the first export.
   */
  private requireRawString(raw: unknown, field: string): string {
    if (typeof raw !== 'string' || raw === '') {
      throw new DomainError('E_INPUT_INVALID', `valuateInventory: ${field} must be a decimal string`, { field });
    }
    return raw;
  }

  private requireDecimal(raw: string, field: string): Big {
    try {
      return new Big(raw);
    } catch {
      throw new DomainError('E_INPUT_INVALID', `valuateInventory: ${field} is not a decimal number`, { field });
    }
  }

  private optionalDecimal(raw: unknown, field: string): Big | null {
    if (raw === null || raw === undefined) return null;
    return this.requireDecimal(this.requireRawString(raw, field), field);
  }

  private nextVersion(periodRef: PeriodRef): number {
    let version = 0;
    for (const valuation of this.valuations.all()) {
      if (
        valuation.period.fiscalYear === periodRef.fiscalYear &&
        valuation.period.period === periodRef.period
      ) {
        version = Math.max(version, valuation.version);
      }
    }
    return version + 1;
  }

  private postMachineEntry(
    date: CalendarDate,
    periodRef: PeriodRef,
    lines: Array<Record<string, unknown>>,
  ): Uuid {
    const voucher = new Voucher({
      id: this.ids.next(),
      voucherNumber: `INV-${periodRef.fiscalYear}-${String(periodRef.period).padStart(2, '0')}`,
      voucherDate: date,
      kind: 'internal',
    });
    this.vouchers.add(voucher);

    const result = this.ledger.post({
      entryDate: date.iso,
      voucherId: voucher.id.value,
      text: `Inventory valuation ${periodRef.fiscalYear}/${String(periodRef.period).padStart(2, '0')}`,
      lines,
    });

    // Machine-generated, like a depreciation run: finalized immediately, because a hand correction
    // of a valuation posting would leave the record and the books disagreeing.
    this.ledger.finalize({ entryId: result.entry.id.value });

    return result.entry.id;
  }
}
