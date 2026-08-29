import { DomainError } from '../../domain-error.js';
import type { AccountNumber } from '../../substrate/account-number.js';

export interface AccountRange {
  readonly from: string;
  readonly to: string;
}

/**
 * The conditions a rule may be keyed on. **Closed**, and the two that are absent are absent for
 * stated reasons (`docs/proposals/constraint-vocabulary.md`): `smallBusiness` is time-segmented and
 * would need a per-posting-date evaluation for one rule that only catches hand-postings, and an
 * amount condition would restate a threshold that already has an owner in the depreciation module —
 * a second source of truth for a number is worse than no rule.
 */
export const CONDITION_KEYS = ['legalForm', 'taxationMethod'] as const;

export type ConditionKey = (typeof CONDITION_KEYS)[number];

export type AppliesWhen = { readonly [K in ConditionKey]?: readonly string[] };

export interface ConstraintContext {
  readonly legalForm: string | null;
  readonly taxationMethod: string | null;
}

export const EMPTY_CONSTRAINT_CONTEXT: ConstraintContext = { legalForm: null, taxationMethod: null };

export interface AccountCombinationRuleData {
  readonly whenAccountIn: AccountRange;
  readonly requireAccountIn?: AccountRange;
  readonly forbidAccountIn?: AccountRange;
  readonly appliesWhen?: AppliesWhen;
  readonly note?: string;
}

export interface AccountUsageRuleData {
  readonly forbidAccountIn: AccountRange;
  readonly appliesWhen?: AppliesWhen;
  readonly note?: string;
}

interface Rule {
  readonly when: AccountRange;
  readonly require: AccountRange | null;
  readonly forbid: AccountRange | null;
  readonly appliesWhen: AppliesWhen | null;
}

interface UsageRule {
  readonly forbid: AccountRange;
  readonly appliesWhen: AppliesWhen | null;
}

function inRange(account: string, range: AccountRange): boolean {
  // Unicode code points, inclusive, exactly like the dimension rule — account numbers are strings
  // in this format and comparing them any other way would make "0100" and "100" disagree by
  // jurisdiction.
  return account >= range.from && account <= range.to;
}

/**
 * A condition nobody can read is a condition nobody applies, so an unknown key is refused here
 * rather than dropped. `E_PACK_INCOHERENT` because that is what it is: the modules resolve, the
 * bundle asks for a condition that does not exist — the same answer an unknown tax mechanism and an
 * unknown account subtype get.
 */
function parseConditions(value: AppliesWhen | undefined): AppliesWhen | null {
  if (value === undefined || value === null) return null;

  const entries = Object.entries(value);
  if (entries.length === 0) {
    throw new DomainError('E_PACK_INCOHERENT', 'appliesWhen must name at least one condition', {
      known: [...CONDITION_KEYS],
    });
  }

  const out: Record<string, string[]> = {};
  for (const [key, allowed] of entries) {
    if (!(CONDITION_KEYS as readonly string[]).includes(key)) {
      throw new DomainError('E_PACK_INCOHERENT', `Unknown appliesWhen condition: ${key}`, {
        condition: key,
        known: [...CONDITION_KEYS],
      });
    }
    const values = (Array.isArray(allowed) ? allowed : []).filter(
      (one): one is string => typeof one === 'string' && one !== '',
    );
    if (values.length === 0) {
      throw new DomainError('E_PACK_INCOHERENT', `appliesWhen.${key} names no value`, { condition: key });
    }
    out[key] = values;
  }
  return out as AppliesWhen;
}

/**
 * Every named condition must hold; within one condition, any listed value does.
 *
 * **An unknown fact means the rule does not apply**, and that is a decision rather than a fallback.
 * A tenant that never called `setEntityProfile` has no legal form, and a rule keyed on one cannot be
 * evaluated for it — refusing the posting would punish a tenant for not having configured
 * something, and applying the rule anyway would enforce a rule whose precondition is unknown to be
 * true. The library reports the rules in force through `tenantConfiguration`, so a caller can see
 * that a conditional rule is dormant rather than having to infer it.
 */
function applies(conditions: AppliesWhen | null, context: ConstraintContext): boolean {
  if (conditions === null) return true;
  for (const [key, allowed] of Object.entries(conditions)) {
    const actual = context[key as ConditionKey];
    if (typeof actual !== 'string' || !(allowed ?? []).includes(actual)) return false;
  }
  return true;
}

/**
 * The constraint socket's account-facing predicates (F-CORE-042, F-CORE-047).
 *
 * **Why a second predicate mattered more than the rule it carries.** The constraint kind — the one
 * of the three policy kinds that exists to let a jurisdiction say *no* — could express exactly one
 * thought: *this account may not be posted without that dimension*. Every other prohibition a
 * jurisdiction has had to go somewhere else or nowhere, and `docs/gobd-conformance.md` §14 item 6
 * has said so since the socket was built: the shape is settled, the vocabulary is not. A socket
 * with one predicate is not obviously a socket; it might be a feature with a data file. The second
 * one is what makes the first a vocabulary.
 *
 * **Three words now.**
 * - `accountCombinationRules` — an entry that touches an account in `whenAccountIn` must also touch
 *   one in `requireAccountIn`, or must not touch one in `forbidAccountIn`. Exactly one of the two
 *   per rule; a rule that said both would be two rules wearing one name.
 * - `accountUsageRules` — an entry must not touch an account in `forbidAccountIn` **at all**. Not a
 *   combination and deliberately not expressed as one: see below.
 * - `appliesWhen` — either kind of rule may be conditioned on a **closed** set of tenant facts
 *   (`legalForm`, `taxationMethod`). Closed on purpose: the moment conditions become an expression
 *   language, a pack carries logic, and the whole point of the pack/substrate split is gone.
 *
 * **Why "the entry", not "the other side".** The named case is a granted discount that has to carry
 * its tax correction (the app-obligation list calls it A-13), and there both lines sit on the
 * *same* side — the discount is a debit and so is the VAT correction, with the receivable on the
 * credit. A predicate about sides would have missed the case it was built for. "Somewhere in the
 * same entry" is also the weaker claim, and the weaker claim is the one a pack can reason about
 * without knowing how an application splits its lines.
 *
 * **Why usage rules are their own word and not `forbidAccountIn: 0000–9999`.** That trick was the
 * obvious way to say "this account may not be used" with the vocabulary that already existed, and
 * it is wrong twice. It reads as a range, so the next author has to work out that the range is
 * meant to cover everything; and account numbers compare by **code point**, so `0000`–`9999`
 * silently fails to cover a chart whose numbers start with a letter and covers a six-digit chart
 * only by accident. A prohibition whose correctness depends on how a foreign chart happens to
 * number its accounts is not a prohibition.
 *
 * **What all of this deliberately cannot do.** It sees one entry. It cannot say "within ten days",
 * cannot reach across entries, and cannot require that a *settlement* be accompanied by anything —
 * `settle` records an allocation and posts nothing, so there is no entry there to constrain. A-13
 * is reached through the posting the application makes for the discount, which is where the books
 * actually change; the settlement itself stays unconstrained and `docs/gobd-conformance.md` still
 * says so.
 */
export class AccountCombinationRegistry {
  private constructor(
    private readonly rules: readonly Rule[],
    private readonly usageRules: readonly UsageRule[],
  ) {}

  static empty(): AccountCombinationRegistry {
    return new AccountCombinationRegistry([], []);
  }

  static fromData(
    rules: readonly AccountCombinationRuleData[],
    usageRules: readonly AccountUsageRuleData[] = [],
  ): AccountCombinationRegistry {
    return new AccountCombinationRegistry(
      rules.map((rule) => ({
        when: rule.whenAccountIn,
        require: rule.requireAccountIn ?? null,
        forbid: rule.forbidAccountIn ?? null,
        appliesWhen: parseConditions(rule.appliesWhen),
      })),
      usageRules.map((rule) => ({
        forbid: rule.forbidAccountIn,
        appliesWhen: parseConditions(rule.appliesWhen),
      })),
    );
  }

  /** What is in force, for `tenantConfiguration` — the same reason the dimension rules are readable. */
  rulesInForce(): AccountCombinationRuleData[] {
    return this.rules.map((rule) => ({
      whenAccountIn: { ...rule.when },
      ...(rule.require === null ? {} : { requireAccountIn: { ...rule.require } }),
      ...(rule.forbid === null ? {} : { forbidAccountIn: { ...rule.forbid } }),
      ...(rule.appliesWhen === null ? {} : { appliesWhen: rule.appliesWhen }),
    }));
  }

  usageRulesInForce(): AccountUsageRuleData[] {
    return this.usageRules.map((rule) => ({
      forbidAccountIn: { ...rule.forbid },
      ...(rule.appliesWhen === null ? {} : { appliesWhen: rule.appliesWhen }),
    }));
  }

  /**
   * Checked over the whole entry, once, after the lines resolve — a per-line hook could not see the
   * combination, which is the entire subject.
   */
  validateEntry(
    accounts: readonly AccountNumber[],
    context: ConstraintContext = EMPTY_CONSTRAINT_CONTEXT,
  ): void {
    const numbers = accounts.map((account) => account.value);

    for (const rule of this.usageRules) {
      if (!applies(rule.appliesWhen, context)) continue;
      const offender = numbers.find((number) => inRange(number, rule.forbid));
      if (offender !== undefined) {
        throw new DomainError(
          'E_ACCOUNT_USE_FORBIDDEN',
          `Account ${offender} must not be posted to by this tenant`,
          {
            account: offender,
            forbiddenFrom: rule.forbid.from,
            forbiddenTo: rule.forbid.to,
            appliesWhen: rule.appliesWhen,
          },
        );
      }
    }

    for (const rule of this.rules) {
      if (!applies(rule.appliesWhen, context)) continue;

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
