import Big from 'big.js';
import { DomainError } from '../domain-error.js';
import type { Mapping } from '../mapping/mapping.js';
import type { MappingRegistry } from '../mapping/mapping-registry.js';
import type {
  AccountRepository,
  FiscalYearRepository,
  JournalRepository,
  OpenItemRepository,
  VoucherRepository,
} from '../port.js';
import { CalendarDate } from '../shared/calendar-date.js';
import type { Currency } from '../shared/currency.js';
import { Money } from '../shared/money.js';
import type { Account } from '../ledger/account.js';
import type { EntryLine } from '../ledger/entry-line.js';
import type { JournalEntry } from '../ledger/journal-entry.js';

const NON_PROFIT_SUBTYPES = new Set(['bank', 'cash', 'transit', 'ar', 'ap']);

/**
 * EÜR als Projektion über das doppische Journal — Regeln R1–R7
 * (euer-projektions-beweis.md). Zahlungswirksamkeit über Geldkonten, OP-Link bei
 * Ausgleich (anteilig), 10-Tage-Regel, USt zahlungswirksam, R7 includeNonCash.
 * Kalenderjahrgebunden: abweichendes GJ → E_CASHBASIS_DEVIATING_FISCAL_YEAR.
 */
export class CashBasisProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly openItems: OpenItemRepository,
    private readonly vouchers: VoucherRepository,
    private readonly fiscalYears: FiscalYearRepository,
    private readonly mappings: MappingRegistry,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const year = typeof params.year === 'number' ? params.year : 0;
    const asOf = typeof params.asOf === 'string' ? CalendarDate.of(params.asOf) : null;
    const mapping = typeof params.mapping === 'string' ? this.mappings.byId(params.mapping) : null;

    this.assertCalendarYearFiscalYears(year);

    const income = new Map<string, Money>();
    const expenses = new Map<string, Money>();
    const addTo = (bucket: Map<string, Money>, label: string, amount: Money): void => {
      bucket.set(label, (bucket.get(label) ?? Money.zero(this.baseCurrency)).add(amount));
    };

    for (const entry of this.journal.all()) {
      if (asOf !== null && entry.entryDate.isAfter(asOf)) continue;

      const bankFlow = this.bankFlow(entry);

      if (bankFlow.isZero()) {
        // R7: nicht zahlungswirksame Pflichtkategorien (Buchungsjahr).
        if (mapping === null || entry.entryDate.year() !== year) continue;
        for (const line of entry.lines()) {
          const account = this.accounts.byId(line.accountId);
          if (account === null) continue;
          const leaf = mapping.leafFor(account.number.value);
          if (leaf === null || !leaf.includeNonCash) continue;

          if (account.type === 'revenue' || account.subtype === 'tax_out') {
            const signed = line.side === 'credit' ? line.money : line.money.negate();
            addTo(income, leaf.label, signed);
          } else if (account.type === 'expense' || account.subtype === 'tax_in') {
            const signed = line.side === 'debit' ? line.money : line.money.negate();
            addTo(expenses, leaf.label, signed);
          }
        }
        continue;
      }

      // R1: zahlungswirksam — Zieljahr (R2), Quelle ggf. via OP-Link.
      if (this.assignYear(entry) !== year) continue;
      const inflow = bankFlow.isPositive();

      for (const sourced of this.sourceLines(entry)) {
        const account = this.accounts.byId(sourced.line.accountId);
        if (account === null || (account.subtype !== null && NON_PROFIT_SUBTYPES.has(account.subtype))) {
          continue;
        }
        const amount = this.proportional(sourced.line.money, sourced.ratio);

        if (account.subtype === 'tax_out') {
          addTo(inflow ? income : expenses, inflow ? 'Vereinnahmte USt' : 'USt-Zahlung an FA', amount);
        } else if (account.subtype === 'tax_in') {
          addTo(expenses, 'Gezahlte Vorsteuer', amount);
        } else if (account.type === 'revenue') {
          addTo(income, this.label(mapping, account), amount);
        } else if (account.type === 'expense') {
          addTo(expenses, this.label(mapping, account), amount);
        }
        // R4/R5: Anlagen, Darlehen, Privat, durchlaufende Posten — neutral.
      }
    }

    return { income: this.serializeBucket(income), expenses: this.serializeBucket(expenses) };
  }

  private assertCalendarYearFiscalYears(year: number): void {
    const y = String(year).padStart(4, '0');
    const start = CalendarDate.of(`${y}-01-01`);
    const end = CalendarDate.of(`${y}-12-31`);

    for (const fiscalYear of this.fiscalYears.all()) {
      const overlaps = !fiscalYear.end.isBefore(start) && !fiscalYear.start.isAfter(end);
      if (!overlaps) continue;
      const isCalendarYear =
        fiscalYear.start.iso.slice(5) === '01-01' && fiscalYear.end.iso.slice(5) === '12-31';
      if (!isCalendarYear) {
        throw new DomainError(
          'E_CASHBASIS_DEVIATING_FISCAL_YEAR',
          `Geschäftsjahr ${fiscalYear.year} (${fiscalYear.start.iso} bis ${fiscalYear.end.iso}) weicht vom Kalenderjahr ab — EÜR ist kalenderjahrgebunden`,
          { fiscalYear: fiscalYear.year },
        );
      }
    }
  }

  private bankFlow(entry: JournalEntry): Money {
    let flow = Money.zero(this.baseCurrency);
    for (const line of entry.lines()) {
      const account = this.accounts.byId(line.accountId);
      const subtype = account?.subtype ?? null;
      if (subtype !== 'bank' && subtype !== 'cash') continue;
      flow = line.side === 'debit' ? flow.add(line.money) : flow.subtract(line.money);
    }
    return flow;
  }

  private assignYear(entry: JournalEntry): number {
    const voucher = this.vouchers.byId(entry.voucherId);
    if (
      voucher !== null &&
      voucher.recurring &&
      voucher.economicYear !== null &&
      voucher.due !== null &&
      this.inTenDayWindow(entry.entryDate) &&
      this.inTenDayWindow(voucher.due)
    ) {
      return voucher.economicYear;
    }
    return entry.entryDate.year();
  }

  private inTenDayWindow(date: CalendarDate): boolean {
    const month = date.month();
    const day = Number(date.iso.slice(8, 10));
    return (month === 12 && day >= 22) || (month === 1 && day <= 10);
  }

  private sourceLines(entry: JournalEntry): Array<{ line: EntryLine; ratio: Big }> {
    const sourced: Array<{ line: EntryLine; ratio: Big }> = [];

    for (const item of this.openItems.all()) {
      for (const settlement of item.settlements()) {
        if (!settlement.entryId.equals(entry.id)) continue;
        const origin = this.journal.byId(item.originEntryId);
        if (origin === null || item.money.isZero()) continue;
        const ratio = new Big(settlement.money.amountAsString()).div(new Big(item.money.amountAsString()));
        for (const line of origin.lines()) sourced.push({ line, ratio });
      }
    }

    if (sourced.length > 0) return sourced;

    const one = new Big(1);
    for (const line of entry.lines()) sourced.push({ line, ratio: one });
    return sourced;
  }

  private proportional(amount: Money, ratio: Big): Money {
    if (ratio.eq(1)) return amount.abs();
    return Money.fromCalculation(new Big(amount.abs().amountAsString()).times(ratio), this.baseCurrency);
  }

  private label(mapping: Mapping | null, account: Account): string {
    if (mapping !== null) {
      const leaf = mapping.leafFor(account.number.value);
      if (leaf !== null) return leaf.label;
    }
    return account.name;
  }

  private serializeBucket(bucket: Map<string, Money>): Array<{ category: string; amount: string }> {
    const labels = [...bucket.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const rows: Array<{ category: string; amount: string }> = [];
    for (const label of labels) {
      const amount = bucket.get(label)!;
      if (amount.isZero()) continue;
      rows.push({ category: label, amount: amount.amountAsString() });
    }
    return rows;
  }
}
