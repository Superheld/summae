import { DomainError } from '../../domain-error.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import { AccountNumber } from '../../substrate/account-number.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';

/**
 * Kontoblatt: alle Bewegungen eines Kontos im Geschäftsjahr mit laufendem Saldo.
 * Anfangsbestand = kumulierte Vorjahre für Bestandskonten, null für Erfolgskonten.
 * Ordnung: sequenceNumber (determinismus.md §3).
 */
export class AccountSheetProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const number = typeof params.account === 'string' ? params.account : '';
    const fiscalYear = typeof params.fiscalYear === 'number' ? params.fiscalYear : 0;
    const throughPeriod =
      typeof params.throughPeriod === 'number' ? params.throughPeriod : Number.MAX_SAFE_INTEGER;

    const account = this.accounts.byNumber(AccountNumber.of(number));
    if (account === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `Konto ${number} existiert nicht`);
    }

    let opening = Money.zero(this.baseCurrency);
    if (isBalanceCarrying(account.type)) {
      for (const entry of this.journal.all()) {
        if (entry.periodRef.fiscalYear >= fiscalYear) continue;
        for (const line of entry.lines()) {
          if (!line.accountId.equals(account.id)) continue;
          opening = line.side === 'debit' ? opening.add(line.money) : opening.subtract(line.money);
        }
      }
    }

    let running = opening;
    const lines: Array<Record<string, unknown>> = [];
    for (const entry of this.journal.forFiscalYear(fiscalYear)) {
      if (entry.periodRef.period > throughPeriod) continue;
      for (const line of entry.lines()) {
        if (!line.accountId.equals(account.id)) continue;
        running = line.side === 'debit' ? running.add(line.money) : running.subtract(line.money);
        lines.push({
          sequenceNumber: entry.sequenceNumber,
          entryDate: entry.entryDate.iso,
          text: entry.text(),
          side: line.side,
          money: line.money.toJSON(),
          runningBalance: running.amountAsString(),
        });
      }
    }

    return {
      account: account.number.value,
      name: account.name,
      openingBalance: opening.amountAsString(),
      lines,
      closingBalance: running.amountAsString(),
    };
  }
}
