import { DomainError } from '../../domain-error.js';
import type { AccountNumber } from '../../substrate/account-number.js';

export interface AccountRange {
  readonly from: string;
  readonly to: string;
}

export interface AccountCombinationRuleData {
  readonly whenAccountIn: AccountRange;
  readonly requireAccountIn?: AccountRange;
  readonly forbidAccountIn?: AccountRange;
  readonly note?: string;
}

interface Rule {
  readonly when: AccountRange;
  readonly require: AccountRange | null;
  readonly forbid: AccountRange | null;
}

function inRange(account: string, range: AccountRange): boolean {
  // Unicode code points, inclusive, exactly like the dimension rule — account numbers are strings
  // in this format and comparing them any other way would make "0100" and "100" disagree by
  // jurisdiction.
  return account >= range.from && account <= range.to;
}

/**
 * The constraint socket's **second** predicate: which accounts may not, or must not, appear in one
 * entry together (F-CORE-042).
 *
 * **Why a second predicate mattered more than the rule it carries.** The constraint kind — the one
 * of the three policy kinds that exists to let a jurisdiction say *no* — could express exactly one
 * thought: *this account may not be posted without that dimension*. Every other prohibition a
 * jurisdiction has had to go somewhere else or nowhere, and `docs/gobd-conformance.md` §14 item 6
 * has said so since the socket was built: the shape is settled, the vocabulary is not. A socket
 * with one predicate is not obviously a socket; it might be a feature with a data file. The second
 * one is what makes the first a vocabulary.
 *
 * **What it says.** An entry that touches an account in `whenAccountIn` must also touch one in
 * `requireAccountIn`, or must not touch one in `forbidAccountIn`. Exactly one of the two per rule —
 * a rule that said both would be two rules wearing one name.
 *
 * **Why "the entry", not "the other side".** The named case is a granted discount that has to carry
 * its tax correction (the app-obligation list calls it A-13), and there both lines sit on the
 * *same* side — the discount is a debit and so is the VAT correction, with the receivable on the
 * credit. A predicate about sides would have missed the case it was built for. "Somewhere in the
 * same entry" is also the weaker claim, and the weaker claim is the one a pack can reason about
 * without knowing how an application splits its lines.
 *
 * **What it deliberately cannot do.** It sees one entry. It cannot say "within ten days", cannot
 * reach across entries, and cannot require that a *settlement* be accompanied by anything — `settle`
 * records an allocation and posts nothing, so there is no entry there to constrain. A-13 is reached
 * through the posting the application makes for the discount, which is where the books actually
 * change; the settlement itself stays unconstrained and `docs/gobd-conformance.md` still says so.
 */
export class AccountCombinationRegistry {
  private constructor(private readonly rules: readonly Rule[]) {}

  static empty(): AccountCombinationRegistry {
    return new AccountCombinationRegistry([]);
  }

  static fromData(rules: readonly AccountCombinationRuleData[]): AccountCombinationRegistry {
    return new AccountCombinationRegistry(
      rules.map((rule) => ({
        when: rule.whenAccountIn,
        require: rule.requireAccountIn ?? null,
        forbid: rule.forbidAccountIn ?? null,
      })),
    );
  }

  /** What is in force, for `tenantConfiguration` — the same reason the dimension rules are readable. */
  rulesInForce(): AccountCombinationRuleData[] {
    return this.rules.map((rule) => ({
      whenAccountIn: { ...rule.when },
      ...(rule.require === null ? {} : { requireAccountIn: { ...rule.require } }),
      ...(rule.forbid === null ? {} : { forbidAccountIn: { ...rule.forbid } }),
    }));
  }

  /**
   * Checked over the whole entry, once, after the lines resolve — a per-line hook could not see the
   * combination, which is the entire subject.
   */
  validateEntry(accounts: readonly AccountNumber[]): void {
    const numbers = accounts.map((account) => account.value);

    for (const rule of this.rules) {
      const trigger = numbers.find((number) => inRange(number, rule.when));
      if (trigger === undefined) continue;

      if (rule.require !== null) {
        const satisfied = numbers.some((number) => number !== trigger && inRange(number, rule.require!));
        if (!satisfied) {
          throw new DomainError(
            'E_COMBINATION_REQUIRED',
            `Account ${trigger} requires the entry to also touch an account between ${rule.require.from} and ${rule.require.to}`,
            { account: trigger, requiredFrom: rule.require.from, requiredTo: rule.require.to },
          );
        }
      }

      if (rule.forbid !== null) {
        const offender = numbers.find((number) => number !== trigger && inRange(number, rule.forbid!));
        if (offender !== undefined) {
          throw new DomainError(
            'E_COMBINATION_FORBIDDEN',
            `Account ${trigger} must not appear in one entry with ${offender}`,
            { account: trigger, forbidden: offender, forbiddenFrom: rule.forbid.from, forbiddenTo: rule.forbid.to },
          );
        }
      }
    }
  }
}
