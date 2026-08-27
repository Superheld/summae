import type { AccountRepository, JournalRepository } from '../../port.js';
import type { Currency } from '../../substrate/currency.js';
import { Money } from '../../substrate/money.js';
import { isBalanceCarrying } from '../../substrate/types.js';
import { integerOrNull } from './parameters.js';

/**
 * The result not yet appropriated (F-CORE-024/SF-25) — the read side of `appropriateResult`.
 *
 * Until this projection the figure existed but could not be *asked for*. It was computed on every
 * `appropriateResult` call and left the library only as the `available` detail of a refusal, so an
 * application that wanted to pre-fill a resolution dialog had two ways to learn it: provoke
 * `E_APPROPRIATION_EXCEEDS_RESULT` on purpose, or read the balance-sheet position carrying
 * `includesNetIncome` — which presupposes a mapping and knowledge of which position that is. A
 * number you can only obtain by doing it wrong is not published.
 *
 * **One pot, not one per year.** `result_allocation` accounts carry what has been appropriated, and
 * nothing in them says which year's profit they consumed. So the top-level figures describe the pot
 * as a whole and `byFiscalYear` describes where it came from. Only `available` is per year, and it
 * is exactly what `appropriateResult` would permit for a resolution naming that year — the same
 * function, not a second implementation of it, so the number a user reads and the number the
 * operation refuses against cannot drift apart.
 *
 * Sign convention follows the books, as everywhere else: positive is a profit, negative a loss.
 * Years come from the journal, in ascending order.
 *
 * The SAME shape lives in the PHP UnappropriatedResultProjection.
 */
export class UnappropriatedResultProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const wanted = integerOrNull(params.fiscalYear);
    const scan = this.scan();

    const years = [...scan.perYear.keys()].sort((a, b) => a - b);
    let cumulative = Money.zero(this.baseCurrency);
    const rows: Array<Record<string, unknown>> = [];

    for (const year of years) {
      cumulative = cumulative.add(scan.perYear.get(year) ?? Money.zero(this.baseCurrency));
      if (wanted !== null && year !== wanted) continue;

      rows.push({
        fiscalYear: year,
        result: (scan.perYear.get(year) ?? Money.zero(this.baseCurrency)).amountAsString(),
        cumulativeResult: cumulative.amountAsString(),
        available: this.availableFrom(cumulative, scan.result, scan.allocated).amountAsString(),
      });
    }

    return {
      cumulativeResult: scan.result.amountAsString(),
      appropriated: scan.allocated.amountAsString(),
      unappropriated: scan.result.subtract(scan.allocated).amountAsString(),
      byFiscalYear: rows,
    };
  }

  /**
   * What `appropriateResult` may book for a resolution naming `fiscalYear`. Public because the
   * expansion service asks it rather than computing its own.
   */
  available(fiscalYear: number): Money {
    const scan = this.scan();
    let cumulative = Money.zero(this.baseCurrency);
    for (const [year, result] of scan.perYear) {
      if (year <= fiscalYear) cumulative = cumulative.add(result);
    }

    return this.availableFrom(cumulative, scan.result, scan.allocated);
  }

  /**
   * The pot decides the direction, the named year caps the size (IMPL-033).
   *
   * The figure itself is unchanged and stays the obvious one: what was earned through year Y, minus
   * everything the allocation accounts already carry. Allocations are deliberately not cut at the
   * year boundary — a resolution is dated *after* the year it appropriates, so cutting them would
   * make every past appropriation invisible and let the same profit be appropriated twice.
   *
   * What that figure could not do alone is notice when it has gone past the end. Appropriate 1200 of
   * a 1400 profit naming 2027, and 2026's figure comes out at 900 − 1200 = −300; the operation read
   * that as an unappropriated *loss* of 300 and would book it, charging the books against a pot that
   * held 200. So the pot — everything earned minus everything appropriated — decides the direction
   * and the ceiling: a year figure pointing the other way is nothing to appropriate rather than a
   * loss, and none of them may exceed what is actually left. Where the year figure was already
   * right, which is every case that does not run past the pot, this changes nothing.
   */
  private availableFrom(cumulativeThroughYear: Money, totalResult: Money, allocated: Money): Money {
    const pot = totalResult.subtract(allocated);
    if (pot.isZero()) return pot;

    const year = cumulativeThroughYear.subtract(allocated);
    const towardsPot = pot.isNegative() ? year.negate() : year;
    if (!towardsPot.isPositive()) return Money.zero(this.baseCurrency);

    const capped = towardsPot.compareTo(pot.abs()) > 0 ? pot.abs() : towardsPot;

    return pot.isNegative() ? capped.negate() : capped;
  }

  /**
   * One pass over the journal: the result of each fiscal year, and what the `result_allocation`
   * accounts carry over the whole journal (see `availableFrom` for why the whole journal).
   */
  private scan(): { perYear: Map<number, Money>; result: Money; allocated: Money } {
    const perYear = new Map<number, Money>();
    let result = Money.zero(this.baseCurrency);
    let allocated = Money.zero(this.baseCurrency);

    for (const entry of this.journal.all()) {
      const year = entry.periodRef.fiscalYear;
      if (!perYear.has(year)) perYear.set(year, Money.zero(this.baseCurrency));

      for (const line of entry.lines()) {
        const account = this.accounts.byId(line.accountId);
        if (account === null) continue;

        if (!isBalanceCarrying(account.type)) {
          const signed = line.side === 'credit' ? line.money : line.money.negate();
          perYear.set(year, (perYear.get(year) as Money).add(signed));
          result = result.add(signed);
          continue;
        }
        if (account.subtype === 'result_allocation') {
          allocated = line.side === 'debit' ? allocated.add(line.money) : allocated.subtract(line.money);
        }
      }
    }

    return { perYear, result, allocated };
  }
}
