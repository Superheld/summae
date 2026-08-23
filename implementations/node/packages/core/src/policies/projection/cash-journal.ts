import { DomainError, rejectedValue } from '../../domain-error.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isIntegerParam } from './parameters.js';

/**
 * Cash journal — the separation of cash from non-cash transactions, and the cash-count check.
 *
 * GoBD Rz. 57 ff. asks for cash and non-cash transactions to be kept apart. In double-entry
 * that separation is already structural rather than a flag: a transaction *is* a cash
 * transaction exactly when it touches an account of subtype `cash`. So nothing has to be
 * marked and no field has to be added — what was missing is the view that presents the
 * separation, which is the form an auditor asks for (the cash account's sheet *is* the
 * Kassenbuch).
 *
 * The second half is the part that finds real defects: **a cash balance can never be
 * negative.** You cannot hold less than no cash — that is physics, not jurisdiction, which
 * is why it belongs in the substrate and not in a pack. A cash account that goes below zero
 * at any point means the books do not describe what was in the drawer, and it is one of the
 * first things a tax auditor looks for. The running balance is therefore checked at every
 * movement, not only at the end: a day that dips negative and recovers is exactly the case
 * a closing balance hides.
 *
 * Reports, never blocks. Whether a negative balance stops a workflow is the embedding
 * application's decision; the library supplies the finding.
 */
export class CashJournalProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    if (!isIntegerParam(params.fiscalYear)) {
      throw new DomainError('E_INPUT_INVALID', 'cashJournal requires the parameter "fiscalYear"', {
        fiscalYear: rejectedValue(params.fiscalYear),
      });
    }
    const fiscalYear = params.fiscalYear;

    // Ordered by account number so the result does not depend on repository order.
    const cashAccounts = this.accounts
      .all()
      .filter((account) => account.subtype === 'cash')
      .sort((a, b) => (a.number.value < b.number.value ? -1 : a.number.value > b.number.value ? 1 : 0));

    const accounts: Array<Record<string, unknown>> = [];
    const negativeBalances: Array<Record<string, unknown>> = [];

    for (const account of cashAccounts) {
      // Cash is balance-carrying: last year's drawer is this year's opening.
      let opening = Money.zero(this.baseCurrency);
      for (const entry of this.journal.all()) {
        if (entry.periodRef.fiscalYear >= fiscalYear) continue;
        for (const line of entry.lines()) {
          if (!line.accountId.equals(account.id)) continue;
          opening = line.side === 'debit' ? opening.add(line.money) : opening.subtract(line.money);
        }
      }

      let running = opening;
      const movements: Array<Record<string, unknown>> = [];
      for (const entry of this.journal.forFiscalYear(fiscalYear)) {
        for (const line of entry.lines()) {
          if (!line.accountId.equals(account.id)) continue;
          running = line.side === 'debit' ? running.add(line.money) : running.subtract(line.money);
          movements.push({
            sequenceNumber: entry.sequenceNumber,
            entryDate: entry.entryDate.iso,
            voucherId: entry.voucherId.value,
            text: entry.text(),
            side: line.side,
            money: line.money.toJSON(),
            runningBalance: running.amountAsString(),
          });

          if (running.isNegative()) {
            negativeBalances.push({
              account: account.number.value,
              sequenceNumber: entry.sequenceNumber,
              entryDate: entry.entryDate.iso,
              runningBalance: running.amountAsString(),
            });
          }
        }
      }

      accounts.push({
        account: account.number.value,
        name: account.name,
        openingBalance: opening.amountAsString(),
        movements,
        closingBalance: running.amountAsString(),
      });
    }

    return {
      fiscalYear,
      accounts,
      // Named as a finding, not as a boolean flag: an empty list is the statement "no cash
      // balance ever went below zero in this year", which is what the check is for.
      negativeBalances,
      cashCountable: negativeBalances.length === 0,
    };
  }
}
