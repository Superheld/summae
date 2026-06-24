import type { AccountRepository, JournalRepository, VoucherRepository } from '../../port.js';
import type { Currency } from '../../substrate/currency.js';
import type { EntryLine } from '../../substrate/entry-line.js';
import type { JournalEntry } from '../../substrate/journal-entry.js';
import { Money } from '../../substrate/money.js';

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * AICPA Audit Data Standard (General Ledger) export — the US counterpart to `journalExport`
 * (GoBD-Z3, German) and `datevExport` (DATEV, German). The US has no statutory GL export format;
 * the AICPA ADS is the voluntary standard a US auditor expects. Emits the three GL streams with
 * the standard's field names (JSON form, per the AICPA-ADS/AuditData-API schema):
 *   - `journals`      (GLDetail)         — journal entries + line items
 *   - `trialBalance`  (GLAccountBalance) — beginning/ending account balances
 *   - `accounts`      (chart of accounts)
 * ADS line amounts are **signed** (debit positive, credit negative) — there is no debit/credit
 * indicator. Counterpart to the German exports; a different jurisdiction, a different "Werk".
 */
export class AuditDataExportProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly journal: JournalRepository,
    private readonly accounts: AccountRepository,
    private readonly vouchers: VoucherRepository,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : null;
    const inScope = fiscalYear === null ? this.journal.all() : this.journal.forFiscalYear(fiscalYear);
    const prior =
      fiscalYear === null ? [] : this.journal.all().filter((entry) => entry.periodRef.fiscalYear < fiscalYear);
    const asOf = asString(params.asOf) ?? this.latestDate(inScope);

    return {
      standard: 'aicpa-ads-gl',
      currency: this.baseCurrency.code,
      journals: inScope.map((entry) => this.journalRow(entry)),
      trialBalance: this.trialBalanceRows(prior, inScope, asOf, fiscalYear),
      accounts: this.accountRows(),
    };
  }

  private journalRow(entry: JournalEntry): Record<string, unknown> {
    const voucher = this.vouchers.byId(entry.voucherId);
    return {
      journalId: entry.id.value,
      effectiveDate: entry.entryDate.iso,
      fiscalYear: entry.periodRef.fiscalYear,
      period: entry.periodRef.period,
      jeHeaderDescription: entry.text(),
      source: voucher === null ? null : voucher.voucherNumber,
      enteredDate: entry.recordedAt,
      reversalIndicator: entry.reverses !== null,
      reversalJournalId: entry.reverses?.value ?? null,
      glLineItems: entry.lines().map((line, index) => ({
        glAccountNumber: line.account.value,
        journalIdLineNumber: `${entry.id.value}-${index + 1}`,
        jeLineDescription: entry.text(),
        transactionAmount: this.signed(line).amountAsString(),
        transactionCurrency: this.baseCurrency.code,
      })),
    };
  }

  /** ADS convention: debit positive, credit negative. */
  private signed(line: EntryLine): Money {
    return line.side === 'debit' ? line.money : line.money.negate();
  }

  private trialBalanceRows(
    prior: JournalEntry[],
    current: JournalEntry[],
    asOf: string | null,
    fiscalYear: number | null,
  ): Array<Record<string, unknown>> {
    const beginning = this.signedSums(prior);
    const ending = this.signedSums([...prior, ...current]);
    const numbers = [...ending.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const zero = Money.zero(this.baseCurrency);
    return numbers.map((number) => ({
      glAccountNumber: number,
      balanceAsOfDate: asOf,
      fiscalYear,
      amountBeginning: (beginning.get(number) ?? zero).amountAsString(),
      amountEnding: (ending.get(number) ?? zero).amountAsString(),
      amountCurrency: this.baseCurrency.code,
    }));
  }

  private signedSums(entries: JournalEntry[]): Map<string, Money> {
    const sums = new Map<string, Money>();
    for (const entry of entries) {
      for (const line of entry.lines()) {
        const key = line.account.value;
        sums.set(key, (sums.get(key) ?? Money.zero(this.baseCurrency)).add(this.signed(line)));
      }
    }
    return sums;
  }

  private accountRows(): Array<Record<string, unknown>> {
    return this.accounts
      .all()
      .slice()
      .sort((a, b) => (a.number.value < b.number.value ? -1 : a.number.value > b.number.value ? 1 : 0))
      .map((account) => ({
        glAccountNumber: account.number.value,
        glAccountName: account.name,
        accountType: account.type,
        accountSubtype: account.subtype,
        parentGLAccountNumber: null,
      }));
  }

  private latestDate(entries: JournalEntry[]): string | null {
    let latest: string | null = null;
    for (const entry of entries) {
      if (latest === null || entry.entryDate.iso > latest) latest = entry.entryDate.iso;
    }
    return latest;
  }
}
