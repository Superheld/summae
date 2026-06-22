import Big from 'big.js';
import type {
  AccountRepository,
  JournalRepository,
  OpenItemRepository,
  VoucherRepository,
} from '../../port.js';
import { AccountNumber } from '../../substrate/account-number.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import type { JournalEntry } from '../../ledger/journal-entry.js';
import type { OpenItem } from '../../ledger/open-item.js';
import type { TaxCodeRegistry } from '../expansion/tax/tax-code-registry.js';
import type { TaxProfile } from '../expansion/tax/tax-profile.js';

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
 * USt-VA-Kennzahlen über taxTags (SF-09). Soll: Buchungs-/Leistungsdatum; Ist:
 * folgt OP-Ausgleichen (anteilig half-up, Schlussrest exakt). Bemessungsgrund-
 * lagen je Kennzahl auf volle Euro abgerundet, Steuer centgenau (api.md v0.3).
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
    const year = typeof params.year === 'number' ? params.year : 0;
    const quarter = typeof params.quarter === 'number' ? params.quarter : 0;
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
          if (this.inQuarter(share.settledAt, year, quarter)) add(share.key, share.base, share.tax);
        }
      }

      for (const entry of this.journal.all()) {
        if (!this.inQuarter(entry.entryDate, year, quarter)) continue;
        if (asOf !== null && entry.entryDate.isAfter(asOf)) continue;
        if (this.openItems.byOriginEntry(entry.id).length > 0) continue;
        for (const [key, contribution] of this.entryContributions(entry, directions)) {
          add(key, contribution.base, contribution.tax);
        }
      }
    } else {
      for (const entry of this.journal.all()) {
        let taxDate: CalendarDate;
        if (entry.reverses !== null) {
          // F-011: § 17-Korrektur zählt nach eigenem Buchungsdatum.
          taxDate = entry.entryDate;
        } else {
          const voucher = this.vouchers.byId(entry.voucherId);
          taxDate = voucher === null ? entry.entryDate : voucher.taxDate();
        }
        if (!this.inQuarter(taxDate, year, quarter)) continue;
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
      // Amtliche VA-Konvention: Basis auf volle Euro abrunden.
      const flooredBase = Money.fromCalculation(
        new Big(amounts.base.amountAsString()).round(0, Big.roundDown),
        this.baseCurrency,
      );
      result[key] = { base: flooredBase.amountAsString(), tax: amounts.tax.amountAsString() };
      const direction = directions.get(key) ?? 'output';
      payload = direction === 'input' ? payload.subtract(amounts.tax) : payload.add(amounts.tax);
    }

    return { keys: result, payload: payload.toJSON() };
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
          version.mechanism === 'reverse_charge' ? 'input' : this.accountDirection(version.taxAccount),
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

  private inQuarter(date: CalendarDate, year: number, quarter: number): boolean {
    if (date.year() !== year) return false;
    return quarter === 0 || Math.floor((date.month() - 1) / 3) + 1 === quarter;
  }
}
