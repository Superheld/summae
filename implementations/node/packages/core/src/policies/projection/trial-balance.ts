import type { AccountRepository, JournalRepository } from '../../port.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';

interface Totals {
  opening: Money;
  debit: Money;
  credit: Money;
  touched: boolean;
}

/**
 * Summen- und Saldenliste (SuSa) — Spalten verbindlich (api.md v0.4):
 * - openingBalance: kumulierter Saldo VOR dem GJ (0 bei Erfolgskonten)
 * - debitTotal/creditTotal: Verkehrszahlen des Zeitraums (GJ bis Periode)
 * - balance = openingBalance + debitTotal − creditTotal (Soll-Salden positiv)
 * Sortierung: Kontonummer nach Codepoints (determinismus.md §3).
 */
export class TrialBalanceProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
  ) {}

  compute(params: Record<string, unknown>): { rows: Array<Record<string, string>> } {
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : 0;
    const throughPeriod =
      typeof params.throughPeriod === 'number' ? params.throughPeriod : Number.MAX_SAFE_INTEGER;
    const includeZeroBalances = params.includeZeroBalances === true;

    const zero = Money.zero(this.baseCurrency);
    const totals = new Map<string, Totals>();

    for (const entry of this.journal.all()) {
      const entryYear = entry.periodRef.fiscalYear;
      const entryPeriod = entry.periodRef.period;
      const isPriorYear = entryYear < fiscalYear;
      const isCurrentScope = entryYear === fiscalYear && entryPeriod <= throughPeriod;
      if (!isPriorYear && !isCurrentScope) continue;

      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null) continue;
        // Erfolgskonten starten je Geschäftsjahr bei null (G1).
        if (isPriorYear && !isBalanceCarrying(account.type)) continue;

        const key = account.number.value;
        let total = totals.get(key);
        if (total === undefined) {
          total = { opening: zero, debit: zero, credit: zero, touched: false };
          totals.set(key, total);
        }

        if (isPriorYear) {
          total.opening =
            line.side === 'debit' ? total.opening.add(line.money) : total.opening.subtract(line.money);
          continue;
        }

        if (line.side === 'debit') total.debit = total.debit.add(line.money);
        else total.credit = total.credit.add(line.money);
        total.touched = true;
      }
    }

    if (includeZeroBalances) {
      for (const account of this.accounts.all()) {
        if (!totals.has(account.number.value)) {
          totals.set(account.number.value, { opening: zero, debit: zero, credit: zero, touched: false });
        }
      }
    }

    const numbers = [...totals.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    const rows: Array<Record<string, string>> = [];
    for (const number of numbers) {
      const total = totals.get(number)!;
      const balance = total.opening.add(total.debit).subtract(total.credit);
      if (!includeZeroBalances && balance.isZero() && !total.touched) continue;
      rows.push({
        account: number,
        openingBalance: total.opening.amountAsString(),
        debitTotal: total.debit.amountAsString(),
        creditTotal: total.credit.amountAsString(),
        balance: balance.amountAsString(),
      });
    }
    return { rows };
  }
}
