import { DomainError } from '../../../domain-error.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { Ledger } from '../../../ledger/ledger.js';
import type {
  AccountRepository,
  DeferralRepository,
  FiscalYearRepository,
  VoucherRepository,
} from '../../../port.js';
import { Voucher } from '../../../records/voucher.js';
import { AccountNumber } from '../../../substrate/account-number.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import { Uuid } from '../../../substrate/uuid.js';
import { Deferral, DEFERRAL_KINDS, PREPAID_EXPENSE, type DeferralInstalment } from './deferral.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Prepaid and deferred items: recognition and the release schedule (F-CORE-053).
 *
 * **The accounts were never the gap.** The shipped German chart has carried both from the start and
 * both have had a balance-sheet position. What was missing is the *plan*. An insurance premium paid
 * in December for the following year could be deferred and then had to be released by hand, month
 * after month, from memory — which is exactly the failure `runDepreciation` exists to prevent for
 * arithmetic that is identical: an amount spread evenly over a known number of periods. Two
 * mechanisms that differ only in whether the machine remembers is not a design, it is an omission.
 *
 * **Two kinds, opposites rather than variants.** A *prepaid expense* is money already paid for a
 * service still to come, so it is an asset and its release is an expense. A *deferred income* is
 * money already received for a service still to be rendered, so it is a liability and its release is
 * revenue. Every posting here flips with the kind; nothing else does.
 *
 * **The release run mirrors the depreciation run, deliberately and down to the answer shape.** One
 * period at a time, idempotent because each deferral records which periods it has released,
 * `alreadyRun` where there was nothing left to do. Somebody who has closed a period with
 * `runDepreciation` should not have to learn a second vocabulary for the same act.
 *
 * **Socket and plug.** Which account holds each kind is the pack's; which expense or revenue the
 * amount belongs to is the caller's, because it is a fact about the transaction rather than about
 * the jurisdiction.
 */
export class DeferralService {
  private ruleModule: Record<string, unknown>;

  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly fiscalYears: FiscalYearRepository,
    private readonly vouchers: VoucherRepository,
    private readonly deferrals: DeferralRepository,
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

  /** Defer an amount and fix its release plan (`recognizeDeferral`). */
  recognize(input: Record<string, unknown>): Record<string, unknown> {
    const kind = typeof input.kind === 'string' ? input.kind : '';
    if (!DEFERRAL_KINDS.includes(kind)) {
      throw new DomainError('E_INPUT_INVALID', `recognizeDeferral: "${kind}" is not a deferral kind`, {
        field: 'kind',
        known: [...DEFERRAL_KINDS],
      });
    }

    const account = AccountNumber.of(this.packAccount(kind));
    const counterAccount = AccountNumber.of(this.requireExistingAccount(input.counterAccount, 'counterAccount'));
    const reason = this.requireString(input.reason, 'reason');
    const recognizedOn = this.requireDate(input.recognizedOn, 'recognizedOn');
    const amount = this.requireMoney(input.amount, 'amount');

    if (!amount.isPositive()) {
      throw new DomainError('E_INPUT_INVALID', 'recognizeDeferral: amount must be positive', { field: 'amount' });
    }

    const periods = typeof input.periods === 'number' ? input.periods : 0;
    if (periods < 1) {
      throw new DomainError(
        'E_INPUT_INVALID',
        'recognizeDeferral: "periods" must be at least 1 — a deferral with no release plan is the ' +
          'hand-kept schedule this operation exists to replace',
        { field: 'periods' },
      );
    }

    const firstYear = typeof input.firstFiscalYear === 'number' ? input.firstFiscalYear : 0;
    const firstPeriod = typeof input.firstPeriod === 'number' ? input.firstPeriod : 0;
    if (firstYear < 1 || firstPeriod < 1) {
      throw new DomainError(
        'E_INPUT_INVALID',
        'recognizeDeferral: firstFiscalYear and firstPeriod say when the release starts and are required',
        { field: 'firstFiscalYear' },
      );
    }

    // Largest-remainder, like every other distribution in this library: the instalments sum to the
    // amount exactly, and the drift lands in the earliest periods rather than in the last one, where
    // it would look like a correction.
    const shares = amount.allocate(...Array.from({ length: periods }, () => 1));
    const plan: DeferralInstalment[] = shares.map((share, index) => ({
      ...this.periodAt(firstYear, firstPeriod, index),
      amount: share,
    }));

    // Recognition: a prepaid expense moves value OUT of the expense account and onto the asset; a
    // deferred income moves it out of revenue and onto the liability. The release, later, runs each
    // of these backwards.
    const lines =
      kind === PREPAID_EXPENSE
        ? [
            { account: account.toString(), side: 'debit', money: amount.toJSON() },
            { account: counterAccount.toString(), side: 'credit', money: amount.toJSON() },
          ]
        : [
            { account: counterAccount.toString(), side: 'debit', money: amount.toJSON() },
            { account: account.toString(), side: 'credit', money: amount.toJSON() },
          ];

    const entryId = this.post(recognizedOn, `Deferral ${reason}`, 'RAP', lines);

    const deferral = new Deferral(
      this.ids.next(),
      kind,
      reason,
      account,
      counterAccount,
      recognizedOn,
      amount,
      plan,
      entryId,
    );
    this.deferrals.add(deferral);

    this.audit?.record(this.audit.actorOf(input), 'deferral', deferral.id, 'recognized', {
      kind: { from: null, to: kind },
      amount: { from: null, to: amount.amountAsString() },
      periods: { from: null, to: periods },
    });

    return {
      deferralId: deferral.id.value,
      kind,
      amount: amount.toJSON(),
      periods,
      entryId: entryId.value,
    };
  }

  /**
   * Release what a period owes, for every deferral (`runDeferralRelease`).
   *
   * The depreciation run's shape, on purpose: one period, idempotent, `alreadyRun` when there is
   * nothing left. A period that was already released books nothing a second time, because each
   * deferral records what it has released rather than deriving it from a balance.
   */
  runRelease(input: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof input.fiscalYear === 'number' ? input.fiscalYear : 0;
    const period = typeof input.period === 'number' ? input.period : 0;

    if (fiscalYear < 1 || period < 1) {
      throw new DomainError('E_INPUT_INVALID', 'runDeferralRelease: fiscalYear and period are required', {
        field: 'fiscalYear',
      });
    }

    const date = this.periodEnd(fiscalYear, period);
    let entriesCreated = 0;
    let total = Money.zero(this.baseCurrency);

    for (const deferral of this.deferrals.all()) {
      if (deferral.isReleased(fiscalYear, period)) continue;

      const instalment = deferral.instalmentFor(fiscalYear, period);
      if (instalment === null || instalment.isZero()) continue;

      const lines =
        deferral.kind === PREPAID_EXPENSE
          ? [
              { account: deferral.counterAccount.toString(), side: 'debit', money: instalment.toJSON() },
              { account: deferral.account.toString(), side: 'credit', money: instalment.toJSON() },
            ]
          : [
              { account: deferral.account.toString(), side: 'debit', money: instalment.toJSON() },
              { account: deferral.counterAccount.toString(), side: 'credit', money: instalment.toJSON() },
            ];

      const entryId = this.post(
        date,
        `Deferral release ${deferral.reason} ${fiscalYear}/${String(period).padStart(2, '0')}`,
        'RAP-A',
        lines,
      );

      deferral.recordRelease(fiscalYear, period, instalment, date, entryId);
      this.deferrals.save(deferral);
      entriesCreated += 1;
      total = total.add(instalment);
    }

    // A run that created nothing is still an event, exactly as it is for depreciation: "somebody ran
    // the release for this period and it was already done" is what an auditor reconstructing a
    // timeline wants to see.
    if (this.tenantId !== null) {
      this.audit?.record(this.audit.actorOf(input), 'deferralRelease', this.tenantId, 'completed', {
        fiscalYear: { from: null, to: fiscalYear },
        period: { from: null, to: period },
        entriesCreated: { from: null, to: entriesCreated },
      });
    }

    if (entriesCreated === 0) return { alreadyRun: true, entriesCreated: 0 };

    return { entriesCreated, totalReleased: total.toJSON() };
  }

  /** What is deferred, over what, and how far it has run (`deferralRegister`). */
  register(params: Record<string, unknown>): Record<string, unknown> {
    const kind = typeof params.kind === 'string' ? params.kind : null;
    const status = typeof params.status === 'string' ? params.status : null;

    const rows: Array<Record<string, unknown>> = [];
    let outstanding = Money.zero(this.baseCurrency);

    for (const deferral of this.deferrals.all()) {
      const deferralStatus = deferral.isSettled() ? 'settled' : 'open';
      if (kind !== null && deferral.kind !== kind) continue;
      if (status !== null && deferralStatus !== status) continue;

      rows.push({
        deferralId: deferral.id.value,
        kind: deferral.kind,
        reason: deferral.reason,
        account: deferral.account.toString(),
        counterAccount: deferral.counterAccount.toString(),
        recognizedOn: deferral.recognizedOn.iso,
        amount: deferral.amount.amountAsString(),
        released: deferral.releasedTotal().amountAsString(),
        outstanding: deferral.outstanding().amountAsString(),
        status: deferralStatus,
        plan: deferral.plan.map((entry) => ({
          fiscalYear: entry.fiscalYear,
          period: entry.period,
          amount: entry.amount.amountAsString(),
          // The plan is the answer to "when will this be gone" and the releases are the answer to
          // "what has actually happened". Reporting them as one flag per instalment keeps both in
          // one place without a second list to line up.
          released: deferral.isReleased(entry.fiscalYear, entry.period),
        })),
      });
      outstanding = outstanding.add(deferral.outstanding());
    }

    return { deferrals: rows, outstandingTotal: outstanding.amountAsString() };
  }

  private periodAt(firstYear: number, firstPeriod: number, offset: number): { fiscalYear: number; period: number } {
    const periodsPerYear = this.periodsPerYear(firstYear);
    const zeroBased = firstPeriod - 1 + offset;

    return {
      fiscalYear: firstYear + Math.floor(zeroBased / periodsPerYear),
      period: (zeroBased % periodsPerYear) + 1,
    };
  }

  /**
   * How many periods a year has — read from the fiscal year where there is one, twelve otherwise.
   *
   * A release plan that ran off the end of a year has to know where the next one starts, and
   * assuming twelve for a tenant whose year is divided differently would put instalments in periods
   * that do not exist.
   */
  private periodsPerYear(fiscalYear: number): number {
    const year = this.fiscalYears.byYear(fiscalYear);
    const count = year === null ? 0 : year.periods().length;
    return count > 0 ? count : 12;
  }

  private periodEnd(fiscalYear: number, period: number): CalendarDate {
    const year = this.fiscalYears.byYear(fiscalYear);
    if (year !== null) {
      for (const candidate of year.periods()) {
        if (candidate.number === period) return candidate.end;
      }
    }

    throw new DomainError('E_PERIOD_UNKNOWN', `period ${period} of fiscal year ${fiscalYear} does not exist`, {
      fiscalYear,
      period,
    });
  }

  private packAccount(kind: string): string {
    const module = isRecord(this.ruleModule.deferrals) ? this.ruleModule.deferrals : null;
    if (module === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'a deferral was recognised, but the pack declares no prepaid or deferred accounts',
        { field: 'deferrals' },
      );
    }

    for (const declared of Array.isArray(module.kinds) ? module.kinds : []) {
      if (isRecord(declared) && declared.kind === kind && typeof declared.account === 'string') {
        return declared.account;
      }
    }

    throw new DomainError('E_PACK_INCOHERENT', `the pack declares no account for deferral kind "${kind}"`, {
      kind,
    });
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

    const result = this.ledger.post({ entryDate: date.iso, voucherId: voucher.id.value, text, lines });
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
    if (typeof raw !== 'string' || raw === '') {
      throw new DomainError('E_INPUT_INVALID', `${field} is required`, { field });
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
