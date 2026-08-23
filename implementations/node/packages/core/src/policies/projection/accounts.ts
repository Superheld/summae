import type { AccountRepository } from '../../port.js';

/**
 * The chart of accounts as a screen can afford to read it (F-CORE-028).
 *
 * Every field here was already published somewhere, and nowhere a caller could use. `subtype`
 * and `status` live in `journalExport.data.accounts` — behind five streams with a SHA-256 each,
 * an archive format answering a question a list view asks on every page load. `datevExport({
 * kind: 'accounts' })` is cheap but DATEV-shaped: number, name, type, and by definition nothing
 * else, because it is master data for an export format rather than the chart itself.
 *
 * So the two facts an application needs most were the two it could not get. `subtype` is what
 * identifies an account's *role* — which one is the bank, which the cash box, which receivables
 * and payables — and without it an app preselecting a counter account has to read the pack it was
 * created from, which is the chart the tenant *started* with and not the chart it has. `status`
 * is the read side of `lockAccount`: the operation exists, and nothing a screen could afford
 * reported whether it had been used, so the only honest test was to post into the account and
 * read the refusal.
 *
 * Deliberately small: no balances (that is `trialBalance`), no movements (`accountSheet`), no
 * hashes. Sorted by account number over Unicode code points, like every other ordering here.
 */
export class AccountsProjection {
  constructor(private readonly accounts: AccountRepository) {}

  compute(_params: Record<string, unknown>): { accounts: Array<Record<string, unknown>> } {
    const sorted = [...this.accounts.all()].sort((a, b) =>
      a.number.value < b.number.value ? -1 : a.number.value > b.number.value ? 1 : 0,
    );

    return {
      accounts: sorted.map((account) => ({
        number: account.number.value,
        name: account.name,
        type: account.type,
        subtype: account.subtype,
        status: account.status(),
      })),
    };
  }
}
