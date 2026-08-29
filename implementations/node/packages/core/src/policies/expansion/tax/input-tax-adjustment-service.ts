import Big from 'big.js';
import { DomainError } from '../../../domain-error.js';
import type { AuditWriter } from '../../../ledger/audit-writer.js';
import type { Ledger } from '../../../ledger/ledger.js';
import type { AccountRepository, VoucherRepository } from '../../../port.js';
import { Voucher } from '../../../records/voucher.js';
import { AccountNumber } from '../../../substrate/account-number.js';
import { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Currency } from '../../../substrate/currency.js';
import { InvalidValue } from '../../../substrate/errors.js';
import type { IdGenerator } from '../../../substrate/id-generator.js';
import { Money } from '../../../substrate/money.js';
import type { Uuid } from '../../../substrate/uuid.js';

/**
 * Percentage points at two decimals, in both languages.
 *
 * `BigDecimal` keeps the scale of its input and `Big` does not, so `100.00 − 60.00` prints `-40.00`
 * on one side and `-40` on the other. The computation uses the full precision that was supplied;
 * only the reported string is normalised.
 */
function percentPoints(value: Big): string {
  return value.toFixed(2);
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/**
 * Correcting a deducted input tax when the use of the thing it was deducted for has changed
 * (F-CORE-056).
 *
 * **Where the boundary actually runs, because this one was argued twice.** The *register* — which
 * assets are under observation, and until when — stays with the embedding application, and for a
 * reason that survives inspection: the trigger is a **change of use**, which is never posted. A
 * library that sees only postings cannot see the day a van starts being driven privately. What does
 * not stay outside is the arithmetic. The argument that put it there was *"a figure produced wrongly
 * would look exactly as authoritative as one produced rightly"* — which is a reason to compute it
 * where figures are fixture-pinned, deterministic and verified across two languages, not a reason to
 * compute it nowhere.
 *
 * **It is an expansion, not a projection, and that was the second correction.** A projection reads
 * the journal; this reads none of it — every input comes from outside. As an expansion it fits
 * exactly, and it is the better design for an unrelated reason: the correction gets **booked**
 * rather than computed and handed back for somebody else to book.
 *
 * **Socket and plug, with an unusually clean seam.** The mechanism is a pro-rata correction over an
 * observation period with de-minimis thresholds — the same shape wherever such a rule exists. Every
 * number is the pack's: how many years the period runs for which kind of thing, the two thresholds,
 * the accounts, and the key the correction is reported under. The `us` pack simply declares no such
 * module, and `adjustInputTax` then says so instead of inventing a period.
 */
export class InputTaxAdjustmentService {
  private ruleModule: Record<string, unknown>;

  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly vouchers: VoucherRepository,
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

  adjust(input: Record<string, unknown>): Record<string, unknown> {
    const module = this.packModule();
    const reason = this.requireString(input.reason, 'reason');
    const date = this.requireDate(input.date, 'date');
    const originalInputTax = this.requireMoney(input.originalInputTax, 'originalInputTax');
    const originalShare = this.requirePercent(input.originalSharePercent, 'originalSharePercent');
    const currentShare = this.requirePercent(input.currentSharePercent, 'currentSharePercent');
    const assetKind = this.requireString(input.assetKind, 'assetKind');
    const years = this.correctionYears(module, assetKind);

    if (!originalInputTax.isPositive()) {
      throw new DomainError(
        'E_INPUT_INVALID',
        'adjustInputTax: originalInputTax must be positive — there is nothing to correct',
        { field: 'originalInputTax' },
      );
    }

    const delta = currentShare.minus(originalShare);

    // Both thresholds, in the order the pack states them. The first is about the thing (too small to
    // observe at all), the second about the change (too small to be worth correcting). Reported as
    // `notDue` with the threshold named, never as a silent zero: "no correction is due" and "we did
    // not compute one" are different answers and only one of them is useful.
    const deMinimis = isRecord(module.deMinimis) ? module.deMinimis : {};

    const inputTaxAtMost = this.optionalMoney(deMinimis.inputTaxAtMost);
    if (inputTaxAtMost !== null && originalInputTax.compareTo(inputTaxAtMost) <= 0) {
      return this.notDue('inputTaxBelowThreshold', originalInputTax, delta, years, inputTaxAtMost.amountAsString());
    }

    const amount = this.yearlyCorrection(originalInputTax, delta, years);

    const sharePointsAtLeast = this.optionalPercent(deMinimis.sharePointsAtLeast);
    const amountAtMost = this.optionalMoney(deMinimis.amountAtMost);
    if (
      sharePointsAtLeast !== null &&
      amountAtMost !== null &&
      delta.abs().lt(sharePointsAtLeast) &&
      amount.abs().compareTo(amountAtMost) <= 0
    ) {
      return this.notDue('changeBelowThreshold', originalInputTax, delta, years, `${sharePointsAtLeast.toString()}%`);
    }

    if (amount.isZero()) {
      return this.notDue('noChange', originalInputTax, delta, years, null);
    }

    const accounts = this.accountsFrom(module);
    const reportingKey = typeof module.reportingKey === 'string' ? module.reportingKey : null;

    // A positive delta means more of the thing is now used in a way that allows the deduction, so
    // more tax may be deducted; a negative one means part of what was deducted has to go back. The
    // tax line carries the pack's reporting key so the correction reaches the return where the
    // jurisdiction expects it — without a tag it would balance, sit correctly on the account, and
    // contribute nothing to what is filed.
    const magnitude = amount.abs();
    const taxTag = reportingKey === null ? null : { reportingKey };
    const lines = amount.isPositive()
      ? [
          { account: accounts.taxAccount, side: 'debit', money: magnitude.toJSON(), taxTag },
          { account: accounts.incomeAccount, side: 'credit', money: magnitude.toJSON() },
        ]
      : [
          { account: accounts.expenseAccount, side: 'debit', money: magnitude.toJSON() },
          { account: accounts.taxAccount, side: 'credit', money: magnitude.toJSON(), taxTag },
        ];

    const entryId = this.post(date, `Input tax adjustment: ${reason}`, lines);

    this.audit?.record(this.audit.actorOf(input), 'inputTaxAdjustment', entryId, 'adjusted', {
      reason: { from: null, to: reason },
      amount: { from: null, to: amount.amountAsString() },
      sharePoints: { from: originalShare.toString(), to: currentShare.toString() },
    });

    return {
      due: true,
      amount: amount.toJSON(),
      correctionYears: years,
      sharePointsChanged: percentPoints(delta),
      reportingKey,
      entryId: entryId.value,
    };
  }

  private notDue(
    reason: string,
    originalInputTax: Money,
    delta: Big,
    years: number,
    threshold: string | null,
  ): Record<string, unknown> {
    return {
      due: false,
      notDueBecause: reason,
      threshold,
      amount: Money.zero(this.baseCurrency).toJSON(),
      correctionYears: years,
      sharePointsChanged: percentPoints(delta),
      originalInputTax: originalInputTax.toJSON(),
      entryId: null,
    };
  }

  /**
   * `originalInputTax × delta% ÷ years`, with one rounding at the end. `div` runs at big.js's
   * default twenty decimal places, which is the scale the PHP side divides at for exactly this
   * reason: rounding twice at the same two scales in both languages is what makes the last cent
   * equal.
   */
  private yearlyCorrection(originalInputTax: Money, delta: Big, years: number): Money {
    const divisor = new Big(100).times(years);
    return Money.fromCalculation(
      new Big(originalInputTax.amountAsString()).times(delta).div(divisor),
      this.baseCurrency,
    );
  }

  private accountsFrom(module: Record<string, unknown>): {
    taxAccount: string;
    expenseAccount: string;
    incomeAccount: string;
  } {
    const declared = isRecord(module.accounts) ? module.accounts : {};
    const out: Record<string, string> = {};

    for (const key of ['taxAccount', 'expenseAccount', 'incomeAccount'] as const) {
      const value = declared[key];
      if (typeof value !== 'string' || value === '') {
        throw new DomainError('E_PACK_INCOHERENT', `the pack declares no ${key} for the input-tax adjustment`, {
          field: `inputTaxAdjustment.accounts.${key}`,
        });
      }
      if (this.accounts.byNumber(AccountNumber.of(value)) === null) {
        throw new DomainError(
          'E_ACCOUNT_UNKNOWN',
          `the input-tax adjustment names account ${value}, which does not exist`,
          { account: value },
        );
      }
      out[key] = value;
    }

    return out as { taxAccount: string; expenseAccount: string; incomeAccount: string };
  }

  private correctionYears(module: Record<string, unknown>, assetKind: string): number {
    for (const period of Array.isArray(module.correctionPeriods) ? module.correctionPeriods : []) {
      if (!isRecord(period) || period.assetKind !== assetKind) continue;
      const years = period.years;
      if (typeof years === 'number' && Number.isInteger(years) && years > 0) return years;
    }

    // Refused rather than defaulted. An observation period is the whole arithmetic: guessing five
    // where the pack means ten halves every correction, and the figure would look exactly as
    // authoritative as a right one.
    throw new DomainError('E_PACK_INCOHERENT', `the pack declares no correction period for asset kind "${assetKind}"`, {
      assetKind,
    });
  }

  private packModule(): Record<string, unknown> {
    const module = isRecord(this.ruleModule.inputTaxAdjustment) ? this.ruleModule.inputTaxAdjustment : null;
    if (module === null) {
      throw new DomainError(
        'E_PACK_INCOHERENT',
        'this pack has no input-tax adjustment — it declares no correction periods',
        { field: 'inputTaxAdjustment' },
      );
    }
    return module;
  }

  private post(date: CalendarDate, text: string, lines: Array<Record<string, unknown>>): Uuid {
    const voucher = new Voucher({
      id: this.ids.next(),
      voucherNumber: `VST-KORR-${date.iso.replaceAll('-', '')}`,
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

  private requireDate(raw: unknown, field: string): CalendarDate {
    const value = this.requireString(raw, field);
    try {
      return CalendarDate.of(value);
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

  private optionalMoney(raw: unknown): Money | null {
    if (typeof raw !== 'string' || raw === '') return null;
    try {
      return Money.of(raw, this.baseCurrency);
    } catch {
      return null;
    }
  }

  private requirePercent(raw: unknown, field: string): Big {
    const value = this.optionalPercent(raw);
    if (value === null) {
      throw new DomainError('E_INPUT_INVALID', `${field} must be a percentage as a decimal string`, { field });
    }
    return value;
  }

  private optionalPercent(raw: unknown): Big | null {
    if (typeof raw !== 'string' || raw === '') return null;
    try {
      return new Big(raw);
    } catch {
      return null;
    }
  }
}
