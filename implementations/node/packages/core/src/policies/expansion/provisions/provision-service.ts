import Big from 'big.js';
import { DomainError } from '../../../domain-error.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { Ledger } from '../../../ledger/ledger.js';
import type { AccountRepository, ProvisionRepository, VoucherRepository } from '../../../port.js';
import { Voucher } from '../../../records/voucher.js';
import { AccountNumber } from '../../../substrate/account-number.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import { Uuid } from '../../../substrate/uuid.js';
import { Provision } from './provision.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Provisions: formation, use, release, re-measurement (F-CORE-051).
 *
 * **Why this is a duty and not a feature.** A provision is the one balance-sheet item a business
 * must recognise for something that has *not yet happened* — an obligation whose amount or timing
 * is uncertain. Leaving it out overstates the result and the equity, which is why the law makes it
 * mandatory rather than optional. summae had nothing: no account, no position, no operation, zero
 * occurrences in either core. A balance sheet without it is not merely incomplete, it is wrong in a
 * direction that flatters.
 *
 * **Four operations, because there are four events and they mean different things.** Recognising is
 * an expense for a future obligation. *Using* it is that obligation coming true. *Releasing* it is
 * the obligation going away — income the business never had to pay. *Re-measuring* is the estimate
 * moving while the obligation stands. A design with one "adjust" would net these into a number that
 * answers none of the questions an auditor asks, which is the same defect as reading a provision
 * off an account balance.
 *
 * **Socket and plug.** The core knows *that* a provision is recognised as an expense against a
 * liability account, that using it settles against something else, that releasing it is income, and
 * that a long-dated one is discounted. Which accounts, whether discounting applies at all and from
 * what remaining term — all pack.
 *
 * **The discount rate is deliberately not shipped, and that is a decision rather than a gap.** The
 * pack declares the *rule* (discount from a remaining term of n months, compounded annually) and
 * cites its basis; the *rate* arrives per act as an input. In Germany it is an average of the last
 * seven years' market rates, published **monthly** — a number that would be stale in a pack file
 * before anybody upgraded, and a stale legal rate that looks authoritative is worse than an absent
 * one. So a provision that must be discounted and carries no rate is refused, by name, rather than
 * recognised undiscounted.
 */
export class ProvisionService {
  private ruleModule: Record<string, unknown>;

  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly vouchers: VoucherRepository,
    private readonly provisions: ProvisionRepository,
    private readonly ledger: Ledger,
    private readonly ids: IdGenerator,
    ruleModule: Record<string, unknown> = {},
    private readonly audit: AuditWriter | null = null,
  ) {
    this.ruleModule = ruleModule;
  }

  setRuleModule(ruleModule: Record<string, unknown>): void {
    this.ruleModule = ruleModule;
  }

  /** Recognise a provision (`recognizeProvision`). */
  recognize(input: Record<string, unknown>): Record<string, unknown> {
    const module = this.packModule();
    const number = this.requireString(input.account, 'account');
    const declared = this.declaredAccount(module, number);

    const reason = this.requireString(input.reason, 'reason');
    const recognizedOn = this.requireDate(input.recognizedOn, 'recognizedOn');
    const dueDate = this.optionalDate(input.dueDate, 'dueDate');
    const settlementAmount = this.requireMoney(input.amount, 'amount');

    if (!settlementAmount.isPositive()) {
      throw new DomainError(
        'E_INPUT_INVALID',
        'recognizeProvision: amount must be positive — a provision of nothing is not a provision',
        { field: 'amount' },
      );
    }

    const [recognized, rate] = this.discount(module, settlementAmount, recognizedOn, dueDate, input);

    const provision = new Provision(
      this.ids.next(),
      reason,
      AccountNumber.of(number),
      AccountNumber.of(declared.expenseAccount),
      AccountNumber.of(declared.releaseAccount),
      recognizedOn,
      dueDate,
      settlementAmount,
      recognized,
      rate,
    );

    const entryId = this.post(recognizedOn, `Provision ${reason}`, 'RST', [
      { account: declared.expenseAccount, side: 'debit', money: recognized.toJSON() },
      { account: number, side: 'credit', money: recognized.toJSON() },
    ]);

    provision.record('recognized', recognizedOn, recognized, entryId);
    this.provisions.add(provision);
    this.trace(input, provision, 'recognized', {
      reason: { from: null, to: reason },
      amount: { from: null, to: recognized.amountAsString() },
    });

    return {
      provisionId: provision.id.value,
      settlementAmount: settlementAmount.toJSON(),
      carryingAmount: recognized.toJSON(),
      discounted: rate !== null,
      discountRate: rate,
      entryId: entryId.value,
    };
  }

  /** The obligation came true (`useProvision`). */
  use(input: Record<string, unknown>): Record<string, unknown> {
    const provision = this.require(input.provisionId);
    const date = this.requireDate(input.date, 'date');
    const amount = this.requireMoney(input.amount, 'amount');
    const settlementAccount = this.requireExistingAccount(input.settlementAccount, 'settlementAccount');

    if (!amount.isPositive()) {
      throw new DomainError('E_INPUT_INVALID', 'useProvision: amount must be positive', { field: 'amount' });
    }

    const carrying = provision.carryingAmount();
    // The overshoot is the case worth getting right, and it is common: the invoice arrives larger
    // than the estimate. What was provided for is taken out of the provision; the rest is an expense
    // of the year the invoice arrived, NOT a retroactive correction of the year the provision was
    // formed. Netting the two would move an expense across a closed year.
    const fromProvision = amount.compareTo(carrying) > 0 ? carrying : amount;
    const excess = amount.subtract(fromProvision);

    const lines: Array<Record<string, unknown>> = [];
    if (!fromProvision.isZero()) {
      lines.push({ account: provision.account.toString(), side: 'debit', money: fromProvision.toJSON() });
    }
    if (!excess.isZero()) {
      lines.push({ account: provision.expenseAccount.toString(), side: 'debit', money: excess.toJSON() });
    }
    lines.push({ account: settlementAccount, side: 'credit', money: amount.toJSON() });

    const entryId = this.post(date, `Provision used: ${provision.reason}`, 'RST-V', lines);

    provision.moveCarryingAmount(fromProvision.negate());
    provision.record(
      'used',
      date,
      fromProvision,
      entryId,
      excess.isZero()
        ? null
        : `settled at ${amount.amountAsString()}, ${excess.amountAsString()} more than provided for`,
    );
    this.provisions.save(provision);
    this.trace(input, provision, 'used', {
      carryingAmount: { from: carrying.amountAsString(), to: provision.carryingAmount().amountAsString() },
    });

    return {
      provisionId: provision.id.value,
      usedFromProvision: fromProvision.toJSON(),
      excessExpense: excess.toJSON(),
      carryingAmount: provision.carryingAmount().toJSON(),
      status: provision.status(),
      entryId: entryId.value,
    };
  }

  /** The reason ceased (`releaseProvision`). */
  release(input: Record<string, unknown>): Record<string, unknown> {
    const provision = this.require(input.provisionId);
    const date = this.requireDate(input.date, 'date');
    const carrying = provision.carryingAmount();
    const amount =
      input.amount === null || input.amount === undefined ? carrying : this.requireMoney(input.amount, 'amount');

    if (amount.isNegative()) {
      throw new DomainError('E_INPUT_INVALID', 'releaseProvision: amount must not be negative', { field: 'amount' });
    }

    if (amount.compareTo(carrying) > 0) {
      throw new DomainError(
        'E_PROVISION_EXCEEDS_CARRYING',
        `provision ${provision.id.value} carries ${carrying.amountAsString()} — ` +
          `${amount.amountAsString()} cannot be released from it`,
        { provisionId: provision.id.value, carryingAmount: carrying.amountAsString() },
      );
    }

    let entryId: Uuid | null = null;
    if (!amount.isZero()) {
      entryId = this.post(date, `Provision released: ${provision.reason}`, 'RST-A', [
        { account: provision.account.toString(), side: 'debit', money: amount.toJSON() },
        { account: provision.releaseAccount.toString(), side: 'credit', money: amount.toJSON() },
      ]);
      provision.moveCarryingAmount(amount.negate());
    }

    provision.record('released', date, amount, entryId);
    this.provisions.save(provision);
    this.trace(input, provision, 'released', {
      carryingAmount: { from: carrying.amountAsString(), to: provision.carryingAmount().amountAsString() },
    });

    return {
      provisionId: provision.id.value,
      released: amount.toJSON(),
      carryingAmount: provision.carryingAmount().toJSON(),
      status: provision.status(),
      entryId: entryId?.value ?? null,
    };
  }

  /** The estimate moved while the obligation stands (`remeasureProvision`). */
  remeasure(input: Record<string, unknown>): Record<string, unknown> {
    const provision = this.require(input.provisionId);
    const date = this.requireDate(input.date, 'date');
    const target = this.requireMoney(input.amount, 'amount');

    if (target.isNegative()) {
      throw new DomainError('E_INPUT_INVALID', 'remeasureProvision: amount must not be negative', { field: 'amount' });
    }

    const [targetCarrying, rate] = this.discount(this.packModule(), target, date, provision.dueDate, input);

    const carrying = provision.carryingAmount();
    const delta = targetCarrying.subtract(carrying);

    let entryId: Uuid | null = null;
    if (!delta.isZero()) {
      // An increase is a further expense; a decrease is income under the release account, because
      // that is what a partial reversal of a provision IS — the same account a full release books
      // to, so the two cannot be told apart in the accounts by accident.
      const lines = delta.isPositive()
        ? [
            { account: provision.expenseAccount.toString(), side: 'debit', money: delta.toJSON() },
            { account: provision.account.toString(), side: 'credit', money: delta.toJSON() },
          ]
        : [
            { account: provision.account.toString(), side: 'debit', money: delta.abs().toJSON() },
            { account: provision.releaseAccount.toString(), side: 'credit', money: delta.abs().toJSON() },
          ];

      entryId = this.post(date, `Provision remeasured: ${provision.reason}`, 'RST-B', lines);
      provision.moveCarryingAmount(delta);
    }

    provision.record('remeasured', date, delta, entryId, rate === null ? null : `discounted at ${rate}%`);
    this.provisions.save(provision);
    this.trace(input, provision, 'remeasured', {
      carryingAmount: { from: carrying.amountAsString(), to: provision.carryingAmount().amountAsString() },
    });

    return {
      provisionId: provision.id.value,
      change: delta.toJSON(),
      carryingAmount: provision.carryingAmount().toJSON(),
      discounted: rate !== null,
      discountRate: rate,
      status: provision.status(),
      entryId: entryId?.value ?? null,
    };
  }

  /** The register (`provisionRegister`). */
  register(params: Record<string, unknown>): Record<string, unknown> {
    const status = typeof params.status === 'string' ? params.status : null;
    const asOf = this.optionalDate(params.asOf, 'asOf');

    const rows: Array<Record<string, unknown>> = [];
    let total = Money.zero(this.baseCurrency);

    for (const provision of this.provisions.all()) {
      if (status !== null && provision.status() !== status) continue;
      if (asOf !== null && provision.recognizedOn.iso > asOf.iso) continue;

      const movements = provision
        .movements()
        .filter((movement) => asOf === null || movement.date.iso <= asOf.iso)
        .map((movement) => ({
          kind: movement.kind,
          date: movement.date.iso,
          amount: movement.amount.amountAsString(),
          entryId: movement.entryId?.value ?? null,
          note: movement.note,
        }));

      rows.push({
        provisionId: provision.id.value,
        reason: provision.reason,
        account: provision.account.toString(),
        recognizedOn: provision.recognizedOn.iso,
        dueDate: provision.dueDate?.iso ?? null,
        settlementAmount: provision.settlementAmount.amountAsString(),
        carryingAmount: provision.carryingAmount().amountAsString(),
        discountRate: provision.discountRate,
        status: provision.status(),
        movements,
      });
      total = total.add(provision.carryingAmount());
    }

    return { provisions: rows, total: total.amountAsString() };
  }

  /** Discounting: mechanism here, everything jurisdictional in the pack. */
  private discount(
    module: Record<string, unknown>,
    amount: Money,
    from: CalendarDate,
    dueDate: CalendarDate | null,
    input: Record<string, unknown>,
  ): [Money, string | null] {
    const rule = isRecord(module.discounting) ? module.discounting : null;
    if (rule === null || dueDate === null) return [amount, null];

    const fromMonths = typeof rule.fromMonths === 'number' ? rule.fromMonths : 12;
    const months = monthsBetween(from, dueDate);

    if (months <= fromMonths) return [amount, null];

    const rate = input.discountRate;
    if (typeof rate !== 'string' || rate === '') {
      // Refused rather than recognised undiscounted, and the message says what is needed. The rate
      // is published periodically and is not a pack constant — a stale legal rate that looks
      // authoritative is worse than an absent one.
      throw new DomainError(
        'E_PROVISION_DISCOUNT_RATE_REQUIRED',
        `this provision runs ${months} months and must be discounted — supply discountRate ` +
          `(${typeof rule.basis === 'string' ? rule.basis : 'see the pack'})`,
        { months, fromMonths },
      );
    }

    let percent: Big;
    try {
      percent = new Big(rate);
    } catch {
      throw new DomainError('E_INPUT_INVALID', 'discountRate is not a decimal number', { field: 'discountRate' });
    }

    if (percent.lt(0)) {
      throw new DomainError('E_INPUT_INVALID', 'discountRate must not be negative', { field: 'discountRate' });
    }

    // **The compounding convention, and why it is this one.** Whole years compound; the remaining
    // months of the stub period accrue simple interest:
    //
    //     PV = amount / ( (1 + r)^years x (1 + r x months/12) )
    //
    // The statute prescribes a rate, not a convention, so this is a choice — and it is made for a
    // reason a shared oracle forces: a genuine fractional power (1+r)^(n/12) is a transcendental,
    // and computing one here and in PHP would put the two a cent apart on some inputs. Everything
    // here is exact decimal arithmetic — an integer power, one multiplication, one division — so
    // both languages reach the same cent by construction. The mixed convention is ordinary practice
    // for a stub period and errs on the small side of the discount, which is the prudent direction
    // for a liability.
    const years = Math.trunc(months / 12);
    const stubMonths = months % 12;

    const r = percent.div(100).round(12, Big.roundHalfUp);
    const factor = new Big(1).plus(r).pow(Math.max(0, years));
    const stub = new Big(1).plus(r.times(stubMonths).div(12).round(12, Big.roundHalfUp));

    // `div` runs at big.js's default 20 decimal places, which is the scale the PHP side divides at
    // for exactly this reason. Rounding twice at the same two scales in both languages is what
    // makes the last cent equal; rounding at different intermediate scales would not.
    const value = Money.fromCalculation(new Big(amount.amountAsString()).div(factor.times(stub)), this.baseCurrency);

    // The rate is reported back exactly as it was given, not re-serialised from the parsed number.
    // `BigDecimal` keeps the scale of its input and `Big` does not, so `2.00` would come back as
    // `2` here — a difference that reaches the export and breaks byte parity for no gain at all.
    return [value, rate];
  }

  private declaredAccount(
    module: Record<string, unknown>,
    number: string,
  ): { expenseAccount: string; releaseAccount: string } {
    for (const declared of Array.isArray(module.accounts) ? module.accounts : []) {
      if (!isRecord(declared) || declared.account !== number) continue;

      const expense = declared.expenseAccount;
      const release = declared.releaseAccount;
      if (typeof expense !== 'string' || typeof release !== 'string') {
        throw new DomainError(
          'E_PACK_INCOHERENT',
          `the pack declares provision account ${number} without an expense or release account`,
          { account: number },
        );
      }

      return { expenseAccount: expense, releaseAccount: release };
    }

    // Two guards, and this is the one that blames the right party. The subtype check below says
    // "you named the wrong account"; this one says "your pack has nothing to say about this
    // account", which needs a different fix.
    const account = this.accounts.byNumber(AccountNumber.of(number));
    if (account === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `recognizeProvision: account ${number} does not exist`, {
        account: number,
      });
    }

    if (account.subtype !== 'provision') {
      throw new DomainError(
        'E_PROVISION_ACCOUNT_INVALID',
        `recognizeProvision: account ${number} is not a provision account (subtype "provision")`,
        { account: number, subtype: account.subtype },
      );
    }

    throw new DomainError(
      'E_PACK_INCOHERENT',
      `the pack declares no expense and release account for provision account ${number}`,
      { account: number },
    );
  }

  private packModule(): Record<string, unknown> {
    const module = isRecord(this.ruleModule.provisions) ? this.ruleModule.provisions : null;
    if (module === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'a provision was recognised, but the pack declares no provision accounts',
        { field: 'provisions' },
      );
    }
    return module;
  }

  require(provisionId: unknown): Provision {
    let provision: Provision | null = null;

    if (typeof provisionId === 'string' && provisionId !== '') {
      try {
        provision = this.provisions.byId(Uuid.fromString(provisionId));
      } catch (error) {
        if (!(error instanceof InvalidValue)) throw error;
        provision = null;
      }
    }

    if (provision === null) {
      throw new DomainError(
        'E_PROVISION_UNKNOWN',
        `provision ${typeof provisionId === 'string' ? provisionId : ''} does not exist`,
        { provisionId },
      );
    }

    return provision;
  }

  private trace(
    input: Record<string, unknown>,
    provision: Provision,
    action: string,
    changes: Record<string, { from: unknown; to: unknown }>,
  ): void {
    this.audit?.record(this.audit.actorOf(input), 'provision', provision.id, action, changes);
  }

  private post(
    date: CalendarDate,
    text: string,
    voucherPrefix: string,
    lines: Array<Record<string, unknown>>,
  ): Uuid {
    const voucher = new Voucher({
      id: this.ids.next(),
      voucherNumber: `${voucherPrefix}-${date.iso.replaceAll('-', '')}`,
      voucherDate: date,
      kind: 'internal',
    });
    this.vouchers.add(voucher);

    const result = this.ledger.post({
      entryDate: date.iso,
      voucherId: voucher.id.value,
      text,
      lines,
    });
    // Machine-generated, like a depreciation run and a stock valuation: finalized immediately,
    // because a hand correction would leave the register and the books disagreeing.
    this.ledger.finalize({ entryId: result.entry.id.value });

    return result.entry.id;
  }

  private requireString(raw: unknown, field: string): string {
    if (typeof raw !== 'string' || raw.trim() === '') {
      throw new DomainError('E_INPUT_INVALID', `${field} is required`, { field });
    }
    return raw;
  }

  private requireExistingAccount(raw: unknown, field: string): string {
    const number = this.requireString(raw, field);

    if (this.accounts.byNumber(AccountNumber.of(number)) === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `account ${number} does not exist`, { account: number });
    }

    return number;
  }

  private requireDate(raw: unknown, field: string): CalendarDate {
    const date = this.optionalDate(raw, field);
    if (date === null) {
      throw new DomainError('E_INPUT_INVALID', `${field} is required`, { field });
    }
    return date;
  }

  private optionalDate(raw: unknown, field: string): CalendarDate | null {
    if (raw === null || raw === undefined) return null;

    if (typeof raw !== 'string' || raw === '') {
      throw new DomainError('E_INPUT_INVALID', `${field} is not a calendar date`, { field });
    }

    try {
      return CalendarDate.of(raw);
    } catch (error) {
      if (!(error instanceof InvalidValue)) throw error;
      throw new DomainError('E_INPUT_INVALID', `${field} is not a calendar date`, { field });
    }
  }

  private requireMoney(raw: unknown, field: string): Money {
    if (!isRecord(raw) || typeof raw.amount !== 'string') {
      throw new DomainError('E_INPUT_INVALID', `${field} must be a money object`, { field });
    }

    const currency = typeof raw.currency === 'string' ? raw.currency : this.baseCurrency.code;

    try {
      return Money.of(raw.amount, currency);
    } catch (error) {
      if (!(error instanceof InvalidValue)) throw error;
      throw new DomainError('E_INPUT_INVALID', `${field} is not a valid amount`, { field });
    }
  }
}

function monthsBetween(from: CalendarDate, to: CalendarDate): number {
  const fromYear = Number(from.iso.slice(0, 4));
  const fromMonth = Number(from.iso.slice(5, 7));
  const fromDay = Number(from.iso.slice(8, 10));
  const toYear = Number(to.iso.slice(0, 4));
  const toMonth = Number(to.iso.slice(5, 7));
  const toDay = Number(to.iso.slice(8, 10));

  let months = (toYear - fromYear) * 12 + (toMonth - fromMonth);
  if (toDay < fromDay) months -= 1;

  return months;
}
