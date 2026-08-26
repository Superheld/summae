import { DomainError, rejectedValue } from '../../domain-error.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import { ReversalIndex } from './reversal-index.js';
import { AccountNumber } from '../../substrate/account-number.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';
import { integerOr, isIntegerParam } from './parameters.js';
import type { JournalEntry } from '../../substrate/journal-entry.js';
import type { Side } from '../../substrate/types.js';

/**
 * Account sheet: all movements of an account in the fiscal year with a running balance.
 * Opening balance = cumulative prior years for balance-carrying accounts, null for income accounts.
 * Order: sequenceNumber (determinismus.md §3).
 *
 * Each line carries the **identity of its entry** and the accounts on the other side of it
 * (SPEC-021, reported by an embedding app as its F-31). Without the first, a screen that shows a
 * sheet and lets the reader open a line had to go looking: `journal` with `fromDate` and `toDate`
 * set to the same day, then filter the day's entries by `sequenceNumber` — a search where a lookup
 * belongs, for an entry whose identity the caller had two fields ago. Without the second, a
 * T-account cannot answer the question it raises on every line: *6000 in debit, against what?*
 *
 * `contraAccounts` is a **list** on purpose. For a plain entry it holds one account; as soon as a
 * tax code is involved it holds two or more, and a field called "the counter account" would have to
 * pick one and thereby invent a fact. Forming it is also the one part only the library can do: the
 * sheet is an extract of ONE account and the other lines are not in it, so an embedding combining
 * figures itself is exactly what a bookkeeping API should make unnecessary.
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
    // See cash-journal: a sheet that shows a reversal as an ordinary opposite movement leaves the
    // reader unable to tell a correction from a removal.
    const reversals = ReversalIndex.of(this.journal);
    for (const entry of this.journal.forFiscalYear(fiscalYear)) {
      if (entry.periodRef.period > throughPeriod) continue;
      for (const line of entry.lines()) {
        if (!line.accountId.equals(account.id)) continue;
        running = line.side === 'debit' ? running.add(line.money) : running.subtract(line.money);
        lines.push({
          sequenceNumber: entry.sequenceNumber,
          entryId: entry.id.value,
          entryDate: entry.entryDate.iso,
          text: entry.text(),
          side: line.side,
          money: line.money.toJSON(),
          runningBalance: running.amountAsString(),
          contraAccounts: this.contraAccounts(entry, line.side),
          ...reversals.forEntry(entry),
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

  /**
   * The accounts on the other side of one entry, by number, deduplicated and sorted.
   *
   * "Other side" is decided per line, not per sheet: on a debit line the credit accounts answer the
   * question, and the other way round. An entry that touches the same account on both sides — a
   * correction within one account — therefore names it here too, which is the honest answer rather
   * than an empty list.
   */
  private contraAccounts(entry: JournalEntry, side: Side): Array<Record<string, string>> {
    const seen = new Map<string, string>();
    for (const line of entry.lines()) {
      if (line.side === side) continue;
      const account = this.accounts.byId(line.accountId);
      if (account === null) continue;
      seen.set(account.number.value, account.name);
    }
    return [...seen.entries()]
      .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
      .map(([number, name]) => ({ account: number, name }));
  }
}
