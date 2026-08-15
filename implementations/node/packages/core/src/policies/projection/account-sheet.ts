import { DomainError, rejectedValue } from '../../domain-error.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import { AccountNumber } from '../../substrate/account-number.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';
import { integerOr, isIntegerParam } from './parameters.js';

/**
 * Account sheet: all movements of an account in the fiscal year with a running balance.
 * Opening balance = cumulative prior years for balance-carrying accounts, null for income accounts.
 * Order: sequenceNumber (determinismus.md §3).
 */
export class AccountSheetProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    // Both are documented as required. Defaulting them produced an authoritative-looking
    // empty ledger — the resolved account name next to "0.00" reads as a verified statement
    // about the account, not as "you forgot a parameter".
    if (typeof params.account !== 'string' || params.account === '') {
      throw new DomainError('E_INPUT_INVALID', 'accountSheet requires the parameter "account"', {
        account: rejectedValue(params.account),
      });
    }
    if (!isIntegerParam(params.fiscalYear)) {
      throw new DomainError('E_INPUT_INVALID', 'accountSheet requires the parameter "fiscalYear"', {
        fiscalYear: rejectedValue(params.fiscalYear),
      });
    }
    const number = params.account;
    const fiscalYear = params.fiscalYear;
    const throughPeriod = integerOr(params.throughPeriod, Number.MAX_SAFE_INTEGER);

    const account = this.accounts.byNumber(AccountNumber.of(number));
    if (account === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `Account ${number} does not exist`);
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
