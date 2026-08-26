import { DomainError, rejectedValue } from '../../domain-error.js';
import type { AccountRepository, JournalRepository } from '../../port.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';
import type { Ledger } from '../../ledger/ledger.js';
import type { AuditWriter } from '../../ledger/audit-writer.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * Appropriation of profit as a named operation (F-CORE-024/SF-25, expansion).
 *
 * **Why this is not something `closeFiscalYear` does.** Appropriating a result is a *resolution*
 * — § 29 GmbHG, § 174 AktG and their equivalents elsewhere — and which part is distributed, put
 * into reserves or carried forward is not something a library can derive from the books. It is
 * also dated when the resolution is passed, which normally falls in the *following* fiscal year;
 * a close that booked it would have to invent a date it does not have. So summae does not decide,
 * it expands: the caller states the decision, the pack supplies the accounts.
 *
 * Before this operation existed the caller had to know the account numbers — the one part of the
 * bookkeeping where an embedding still had to (`2300 an 2100`). That is exactly where an agent or
 * an application guesses, and a guessed account number is a wrong posting rather than an error
 * message. Here a wrong target is refused by name and a wrong amount by the books.
 *
 * **What may be appropriated** is the result *not yet appropriated*: the cumulative result of all
 * fiscal years up to and including the one named, minus the balance of the `result_allocation`
 * accounts over the whole journal. That is deliberately the same figure the balance sheet reports
 * in its `includesNetIncome` position, so the number a user reads and the number this refuses
 * against cannot drift apart. The allocation accounts are counted over the whole journal and not
 * only up to the named year, because a resolution is dated *after* the year it appropriates —
 * cutting them at the year boundary would make every past appropriation invisible and let the same
 * profit be appropriated twice.
 *
 * A loss appropriates the other way round (allocation account in credit), and the amounts stay
 * positive in the input either way: the direction follows from the books, not from a sign the
 * caller has to get right.
 *
 * The SAME shape lives in the PHP ResultAppropriationService.
 */
export class ResultAppropriationService {
  private ruleModule: Record<string, unknown> = {};

  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly ledger: Ledger,
    private readonly audit: AuditWriter,
  ) {}

  setRuleModule(ruleModule: Record<string, unknown>): void {
    this.ruleModule = ruleModule;
  }

  appropriate(input: Record<string, unknown>): Record<string, unknown> {
    const actor = this.audit.actorOf(input);
    const plug = this.plug();

    const fiscalYear = input.fiscalYear;
    if (typeof fiscalYear !== 'number' || !Number.isInteger(fiscalYear)) {
      throw new DomainError('E_INPUT_INVALID', 'appropriateResult requires the parameter "fiscalYear"', {
        fiscalYear: rejectedValue(input.fiscalYear),
      });
    }

    const requested = this.parseAppropriations(input.appropriations, plug);
    const available = this.unappropriated(fiscalYear);

    // Nothing to appropriate is refused rather than posted as zero: an entry that moves nothing
    // would sit in the books claiming a resolution took effect.
    if (available.isZero()) {
      throw new DomainError(
        'E_APPROPRIATION_EXCEEDS_RESULT',
        `Fiscal year ${fiscalYear} has no unappropriated result`,
        { fiscalYear, available: available.amountAsString() },
      );
    }

    const isProfit = !available.isNegative();
    const capacity = isProfit ? available : available.negate();
    let total = Money.zero(this.baseCurrency);
    for (const item of requested) total = total.add(item.money);

    if (total.compareTo(capacity) > 0) {
      throw new DomainError(
        'E_APPROPRIATION_EXCEEDS_RESULT',
        `Appropriation of ${total.amountAsString()} exceeds the unappropriated result of ${available.amountAsString()}`,
        { requested: total.amountAsString(), available: available.amountAsString(), fiscalYear },
      );
    }

    // A profit leaves the allocation account in debit and reaches its targets in credit; a loss
    // does the same journey backwards. The caller states amounts, never sides.
    const allocationSide = isProfit ? 'debit' : 'credit';
    const targetSide = isProfit ? 'credit' : 'debit';

    const lines: Array<Record<string, unknown>> = [
      { account: plug.allocationAccount, side: allocationSide, money: total.toJSON() },
    ];
    for (const item of requested) {
      lines.push({ account: item.account, side: targetSide, money: item.money.toJSON() });
    }

    const text = asString(input.text) ?? `Appropriation of the result ${fiscalYear}`;
    const result = this.ledger.post({
      entryDate: input.entryDate,
      voucherId: input.voucherId,
      text,
      lines,
      ...(actor === null ? {} : { actor }),
    });

    const remaining = isProfit ? available.subtract(total) : available.add(total);

    return {
      entry: result.entry.toJSON(),
      fiscalYear,
      appropriated: requested.map((item) => ({
        target: item.target,
        account: item.account,
        money: item.money.toJSON(),
      })),
      remaining: remaining.amountAsString(),
    };
  }

  /** What the pack offers, or the refusal that says it offers nothing. */
  private plug(): { allocationAccount: string; targets: Record<string, string> } {
    const data = isRecord(this.ruleModule.resultAppropriation) ? this.ruleModule.resultAppropriation : null;
    const allocationAccount = data === null ? null : asString(data.allocationAccount);
    if (data === null || allocationAccount === null) {
      throw new DomainError(
        'E_APPROPRIATION_UNSUPPORTED',
        'The pack declares no result appropriation, so summae does not know which accounts a resolution books against',
      );
    }
    const targets: Record<string, string> = {};
    for (const [name, target] of Object.entries(isRecord(data.targets) ? data.targets : {})) {
      const account = isRecord(target) ? asString(target.account) : null;
      if (account !== null) targets[name] = account;
    }
    return { allocationAccount, targets };
  }

  private parseAppropriations(
    value: unknown,
    plug: { targets: Record<string, string> },
  ): Array<{ target: string; account: string; money: Money }> {
    const items = Array.isArray(value) ? value : [];
    if (items.length === 0) {
      throw new DomainError('E_INPUT_INVALID', 'appropriateResult without appropriations');
    }

    const parsed: Array<{ target: string; account: string; money: Money }> = [];
    const seen = new Set<string>();
    for (const item of items) {
      const target = isRecord(item) ? asString(item.target) : null;
      if (target === null) {
        throw new DomainError('E_INPUT_INVALID', 'An appropriation without a target', {
          target: rejectedValue(isRecord(item) ? item.target : item),
        });
      }
      const account = plug.targets[target];
      if (account === undefined) {
        // Named rather than validated against a fixed list: which targets exist is the pack's
        // answer, and a jurisdiction that knows no distribution simply does not offer one.
        throw new DomainError(
          'E_APPROPRIATION_UNSUPPORTED',
          `The pack offers no appropriation target "${target}"`,
          { target, offered: Object.keys(plug.targets).sort() },
        );
      }
      // Twice the same target would post two lines on one account in one entry — legal, and a
      // sign the caller lost track of its own decision.
      if (seen.has(target)) {
        throw new DomainError('E_INPUT_INVALID', `Appropriation target "${target}" given twice`, { target });
      }
      seen.add(target);

      const money = this.parseMoney(isRecord(item) ? item.money : null, target);
      parsed.push({ target, account, money });
    }
    return parsed;
  }

  /**
   * Amounts arrive in the same shape every posting line uses, and are refused with the same
   * strictness — including the currency, which v1 pins to the tenant's.
   */
  private parseMoney(value: unknown, target: string): Money {
    const amount = isRecord(value) ? asString(value.amount) : null;
    const currency = isRecord(value) ? asString(value.currency) : null;
    if (amount === null || currency !== this.baseCurrency.code) {
      throw new DomainError('E_INPUT_INVALID', `Appropriation "${target}": money missing or not in ${this.baseCurrency.code}`, {
        target,
        money: rejectedValue(value),
      });
    }
    let money: Money;
    try {
      money = Money.of(amount, this.baseCurrency);
    } catch {
      throw new DomainError('E_INPUT_INVALID', `Appropriation "${target}": "${amount}" is not a valid amount`, {
        target,
        money: rejectedValue(value),
      });
    }
    if (!money.isPositive()) {
      throw new DomainError('E_INPUT_INVALID', `Appropriation "${target}": amount must be > 0`, {
        target,
        money: rejectedValue(value),
      });
    }
    return money;
  }

  /**
   * The result of every year up to and including `fiscalYear`, minus what the result-allocation
   * accounts already carry — the figure the balance sheet publishes as "not yet appropriated".
   */
  private unappropriated(fiscalYear: number): Money {
    let result = Money.zero(this.baseCurrency);
    let allocated = Money.zero(this.baseCurrency);

    for (const entry of this.journal.all()) {
      const withinYear = entry.periodRef.fiscalYear <= fiscalYear;
      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null) continue;

        if (!isBalanceCarrying(account.type)) {
          if (!withinYear) continue;
          result = line.side === 'credit' ? result.add(line.money) : result.subtract(line.money);
          continue;
        }
        if (account.subtype === 'result_allocation') {
          allocated = line.side === 'debit' ? allocated.add(line.money) : allocated.subtract(line.money);
        }
      }
    }

    return result.subtract(allocated);
  }

  /** Which targets this tenant can appropriate to — for a caller that wants to offer a choice. */
  offeredTargets(): string[] {
    const data = isRecord(this.ruleModule.resultAppropriation) ? this.ruleModule.resultAppropriation : null;
    if (data === null) return [];
    return Object.keys(isRecord(data.targets) ? data.targets : {}).sort();
  }
}
