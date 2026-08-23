import Big from 'big.js';
import type {
  AccountRepository,
  JournalRepository,
  OpenItemRepository,
  VoucherRepository,
} from '../../port.js';
import { AccountNumber } from '../../substrate/account-number.js';
import { mechanismFor } from '../expansion/tax/tax-mechanisms.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import type { JournalEntry } from '../../substrate/journal-entry.js';
import type { OpenItem } from '../../records/open-item.js';
import type { TaxCodeRegistry } from '../expansion/tax/tax-code-registry.js';
import type { TaxProfile } from '../expansion/tax/tax-profile.js';
import { integerOr } from './parameters.js';
import { DomainError, rejectedValue } from '../../domain-error.js';

/**
 * Does a date fall in the requested filing period?
 *
 * Three windows, and which one applies is the caller's to say: a month, a quarter, or — when neither
 * is given — the whole year. The month matters more than it looks: for a business above the threshold
 * the monthly period is not a convenience but the prescribed one, and the only alternative an app had
 * was to call twice cumulatively and subtract. That difference is not the period's figure once
 * cash-basis taxation or a reversal is involved, which is why it had to become a window here rather
 * than arithmetic there.
 */
function inPeriod(date: CalendarDate, year: number, quarter: number, month: number): boolean {
  if (date.year() !== year) return false;
  if (month !== 0) return date.month() === month;
  return quarter === 0 || Math.floor((date.month() - 1) / 3) + 1 === quarter;
}


interface KeyAmount {
  base: Money;
  tax: Money;
}
interface Share {
  key: string;
  base: Money;
  tax: Money;
  settledAt: CalendarDate;
}

/**
 * VAT return reporting keys via taxTags (SF-09). Accrual: posting/supply date; cash:
 * follows OP settlements (proportional half-up, final remainder exact). Tax bases
 * per reporting key rounded down to full euros, tax to the cent (api.md v0.3).
 */
export class VatReturnProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly journal: JournalRepository,
    private readonly openItems: OpenItemRepository,
    private readonly vouchers: VoucherRepository,
    private readonly accounts: AccountRepository,
    private readonly registry: TaxCodeRegistry,
    private readonly profile: TaxProfile,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const year = integerOr(params.year, 0);
    const quarter = integerOr(params.quarter, 0);
    const month = integerOr(params.month, 0);

    // Both would describe two different windows, and picking one silently is how a return gets filed
    // for the wrong period. Absent is still "the whole year".
    if (quarter !== 0 && month !== 0) {
      throw new DomainError('E_INPUT_INVALID', 'vatReturn: give either "quarter" or "month", not both', {
        quarter,
        month,
      });
    }

    if (month !== 0 && (month < 1 || month > 12)) {
      throw new DomainError('E_INPUT_INVALID', 'vatReturn: "month" must be between 1 and 12', {
        month: rejectedValue(params.month),
      });
    }
    const asOf = typeof params.asOf === 'string' ? CalendarDate.of(params.asOf) : null;

    const zero = Money.zero(this.baseCurrency);
    const keys = new Map<string, KeyAmount>();
    const directions = this.registryDirections();

    const add = (key: string, base: Money, tax: Money): void => {
      const current = keys.get(key) ?? { base: zero, tax: zero };
      keys.set(key, { base: current.base.add(base), tax: current.tax.add(tax) });
    };

    if (this.profile.isCashBasis()) {
      for (const item of this.openItems.all()) {
        const origin = this.journal.byId(item.originEntryId);
        if (origin === null || (asOf !== null && origin.entryDate.isAfter(asOf))) continue;
        const contributions = this.entryContributions(origin, directions);
        if (contributions.size === 0) continue;
        for (const share of this.allocateToSettlements(item, contributions)) {
          if (asOf !== null && share.settledAt.isAfter(asOf)) continue;
          if (inPeriod(share.settledAt, year, quarter, month)) add(share.key, share.base, share.tax);
        }
      }

      for (const entry of this.journal.all()) {
        if (!inPeriod(entry.entryDate, year, quarter, month)) continue;
        if (asOf !== null && entry.entryDate.isAfter(asOf)) continue;
        if (this.openItems.byOriginEntry(entry.id).length > 0) continue;
        // IMPL-005: this loop's premise is "no open item ⇒ the money moved at posting time"
        // (a cash sale). A reversal has no open item of its own, but it is not a cash
        // movement either. When the entry it reverses carries open items, its tax already
        // follows those items' settlements above — counting it here would declare a
        // correction for money that never moved: reversing an unpaid invoice would claim
        // back tax that was never due. Reversals of genuinely cash-effective entries
        // (target without open items) still count here, at their own posting date.
        if (entry.reverses !== null && this.openItems.byOriginEntry(entry.reverses).length > 0) {
          continue;
        }
        for (const [key, contribution] of this.entryContributions(entry, directions)) {
          add(key, contribution.base, contribution.tax);
        }
      }
    } else {
      for (const entry of this.journal.all()) {
        let taxDate: CalendarDate;
        if (entry.reverses !== null) {
          // SPEC-011: a tax correction counts by its own posting date.
          taxDate = entry.entryDate;
        } else {
          const voucher = this.vouchers.byId(entry.voucherId);
          taxDate = voucher === null ? entry.entryDate : voucher.taxDate();
        }
        if (!inPeriod(taxDate, year, quarter, month)) continue;
        if (asOf !== null && entry.entryDate.isAfter(asOf)) continue;
        for (const [key, contribution] of this.entryContributions(entry, directions)) {
          add(key, contribution.base, contribution.tax);
        }
      }
    }

    const sortedKeys = [...keys.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const result: Record<string, { base: string; tax: string }> = {};
    let payload = zero;

    for (const key of sortedKeys) {
      const amounts = keys.get(key)!;
      // Official VAT-return convention: round base down to full euros.
      const flooredBase = Money.fromCalculation(
        new Big(amounts.base.amountAsString()).round(0, Big.roundDown),
        this.baseCurrency,
      );
      result[key] = { base: flooredBase.amountAsString(), tax: amounts.tax.amountAsString() };
      const direction = directions.get(key) ?? 'output';
      payload = direction === 'input' ? payload.subtract(amounts.tax) : payload.add(amounts.tax);
    }

    return { keys: result, payload: payload.toJSON(), gapWarnings: this.gapWarnings(year, quarter, month, asOf) };
  }

  /**
   * Postings that touch a tax account without a tax code (F-TAX-013).
   *
   * The return is built from tax-*coded* postings — `line.taxTag` is what carries the reporting key
   * and the base. That is a defensible design and it is silent: posting expense / input tax / bank
   * by hand balances, satisfies every invariant, and shows correct figures on the accounts and in
   * the trial balance. Only `vatReturn` reports zero, because nothing told it which key the amount
   * belongs to. The books look right everywhere except the one place that decides what is filed,
   * and an application's seed script fell into it on the first attempt.
   *
   * So the warning lives here, at the figures, rather than in a projection of its own that whoever
   * files the return may not open. It is **not** a refusal: correction postings legitimately touch
   * these accounts, and a library that blocked them would be wrong more often than the caller.
   *
   * Which accounts count is the pack's answer, not this code's: `tax_in` and `tax_out` are subtypes
   * the chart assigns, so a jurisdiction without input-tax deduction simply has no `tax_in` account
   * and produces no such warning.
   *
   * The window is the posting's tax date in both taxation methods. An untagged line has nothing to
   * attach it to a settlement, so the cash-basis question "when did the money move" has no answer
   * for it — which is part of what makes it worth reporting.
   */
  private gapWarnings(
    year: number,
    quarter: number,
    month: number,
    asOf: CalendarDate | null,
  ): Array<Record<string, unknown>> {
    const warnings: Array<Record<string, unknown>> = [];

    for (const entry of this.journal.all()) {
      const voucher = this.vouchers.byId(entry.voucherId);
      const taxDate = entry.reverses !== null || voucher === null ? entry.entryDate : voucher.taxDate();
      if (!inPeriod(taxDate, year, quarter, month)) continue;
      if (asOf !== null && entry.entryDate.isAfter(asOf)) continue;

      for (const line of entry.lines()) {
        if (line.taxTag !== null) continue;
        const account = this.accounts.byId(line.accountId);
        const subtype = account?.subtype ?? null;
        if (subtype !== 'tax_in' && subtype !== 'tax_out') continue;

        warnings.push({
          reason: 'tax_account_without_tax_code',
          sequenceNumber: entry.sequenceNumber,
          entryDate: entry.entryDate.iso,
          account: account?.number.value ?? null,
          side: line.side,
          money: line.money.toJSON(),
        });
      }
    }

    // Journal order, then account: the order the postings happened in is the order somebody
    // checking them will work through.
    warnings.sort((a, b) => {
      const bySeq = (a.sequenceNumber as number) - (b.sequenceNumber as number);
      if (bySeq !== 0) return bySeq;
      const left = String(a.account ?? '');
      const right = String(b.account ?? '');
      return left < right ? -1 : left > right ? 1 : 0;
    });
    return warnings;
  }

  private registryDirections(): Map<string, string> {
    const directions = new Map<string, string>();
    for (const version of this.registry.allVersions()) {
      if (version.reportingKey !== null) {
        directions.set(version.reportingKey, this.accountDirection(version.taxAccount));
      }
      if (version.inputReportingKey !== null) {
        directions.set(version.inputReportingKey, 'input');
      }
      if (version.baseReportingKey !== null) {
        directions.set(
          version.baseReportingKey,
          mechanismFor(version.mechanism).vatReturnDirection ?? this.accountDirection(version.taxAccount),
        );
      }
    }
    return directions;
  }

  private accountDirection(accountNumber: string): string {
    if (accountNumber === '') return 'output';
    const account = this.accounts.byNumber(AccountNumber.of(accountNumber));
    return account?.subtype === 'tax_in' ? 'input' : 'output';
  }

  private entryContributions(entry: JournalEntry, directions: Map<string, string>): Map<string, KeyAmount> {
    const zero = Money.zero(this.baseCurrency);
    interface Collected {
      baseFromTax: Money;
      hasTaxBase: boolean;
      baseFallback: Money;
      tax: Money;
    }
    const collected = new Map<string, Collected>();

    for (const line of entry.lines()) {
      const tag = line.taxTag;
      if (tag === null) continue;
      const rawKey = tag.reportingKey;
      if (typeof rawKey !== 'string' && typeof rawKey !== 'number') continue;
      const key = String(rawKey);

      const account = this.accounts.byId(line.accountId);
      const subtype = account?.subtype ?? null;
      const entryFor = collected.get(key) ?? {
        baseFromTax: zero,
        hasTaxBase: false,
        baseFallback: zero,
        tax: zero,
      };

      if (subtype === 'tax_out' || subtype === 'tax_in') {
        const positiveSide = subtype === 'tax_out' ? 'credit' : 'debit';
        const signed = line.side === positiveSide ? line.money : line.money.negate();
        entryFor.tax = entryFor.tax.add(signed);

        let baseMoney = this.tagBaseMoney(tag);
        if (baseMoney !== null) {
          if (line.money.isNegative()) baseMoney = baseMoney.negate();
          entryFor.baseFromTax = entryFor.baseFromTax.add(baseMoney);
          entryFor.hasTaxBase = true;
        }
      } else {
        const direction = directions.get(key) ?? 'output';
        const positiveSide = direction === 'input' ? 'debit' : 'credit';
        const signed = line.side === positiveSide ? line.money : line.money.negate();
        entryFor.baseFallback = entryFor.baseFallback.add(signed);
      }
      collected.set(key, entryFor);
    }

    const contributions = new Map<string, KeyAmount>();
    for (const [key, parts] of collected) {
      const base = parts.hasTaxBase ? parts.baseFromTax : parts.baseFallback;
      if (base.isZero() && parts.tax.isZero()) continue;
      contributions.set(key, { base, tax: parts.tax });
    }
    return contributions;
  }

  private tagBaseMoney(tag: Record<string, unknown>): Money | null {
    const baseMoney = tag.baseMoney;
    const amount =
      baseMoney !== null && typeof baseMoney === 'object' && typeof (baseMoney as Record<string, unknown>).amount === 'string'
        ? ((baseMoney as Record<string, unknown>).amount as string)
        : null;
    return amount === null ? null : Money.of(amount, this.baseCurrency);
  }

  private allocateToSettlements(item: OpenItem, contributions: Map<string, KeyAmount>): Share[] {
    const shares: Share[] = [];
    const allocated = new Map<string, KeyAmount>();
    let remaining = item.money;
    const total = new Big(item.money.amountAsString());

    for (const settlement of item.settlements()) {
      // IMPL-008: a cancellation closes the item without any money moving. Counting it here would
      // declare cash-basis VAT for a reversed invoice that was never paid — the exact opposite of
      // what the reversal means. Skipped before `remaining` is touched, so the proportional split
      // of any real payments is unaffected.
      if (settlement.cause === 'cancellation') continue;
      remaining = remaining.subtract(settlement.money);
      const isFinal = remaining.isZero();
      const ratio = new Big(settlement.money.amountAsString());

      for (const [key, contribution] of contributions) {
        const current = allocated.get(key) ?? {
          base: Money.zero(this.baseCurrency),
          tax: Money.zero(this.baseCurrency),
        };

        let base: Money;
        let tax: Money;
        if (isFinal) {
          base = contribution.base.subtract(current.base);
          tax = contribution.tax.subtract(current.tax);
        } else {
          base = this.proportional(contribution.base, ratio, total);
          tax = this.proportional(contribution.tax, ratio, total);
        }

        allocated.set(key, { base: current.base.add(base), tax: current.tax.add(tax) });
        shares.push({ key, base, tax, settledAt: settlement.settledAt });
      }
    }
    return shares;
  }

  private proportional(total: Money, part: Big, whole: Big): Money {
    if (whole.eq(0)) return Money.zero(this.baseCurrency);
    return Money.fromCalculation(
      new Big(total.amountAsString()).times(part).div(whole),
      this.baseCurrency,
    );
  }

}
