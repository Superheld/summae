import Big from 'big.js';
import { DomainError } from '../../../domain-error.js';
import type { Currency } from '../../../substrate/currency.js';
import { Money } from '../../../substrate/money.js';

export interface BaseSplit {
  /** What the tax is charged on, and what a reporting key receives. */
  readonly base: Money;
  /** The tax itself, rounded by the shared rule (half-up away from zero). */
  readonly tax: Money;
}

/**
 * How a taxable amount splits into base and tax — the **second seam** of the tax expansion
 * (F-TAX-010).
 *
 * **Why this is its own socket and not a fifth mechanism.** The mechanism seam covers *line
 * assembly*: it receives an already-computed, already-rounded tax amount and decides which accounts
 * and reporting keys it lands on. `core/src/CLAUDE.md` has said since 2026-08-16 that the variance
 * which actually differs between jurisdictions sits **before** that and had no socket at all —
 * `base × rate / 100` was written twice inside `TaxService`, once per rounding granularity, and a
 * pack could not reach it. Every tax system that quotes prices with the tax already inside was
 * therefore inexpressible, and that is most of them.
 *
 * **The two kinds, and what separates them.**
 *
 * - `net` — the amount handed in is the base. `tax = amount × rate / 100`. What summae has always
 *   done, and the default when a tax code says nothing, so no shipped pack changes behaviour.
 * - `inclusive` — the amount handed in is the **gross**, tax already inside.
 *   `tax = amount × rate / (100 + rate)`, and the base is what remains. Rounding happens once, on
 *   the tax, and the base is derived by subtraction — the other order lets base and tax fail to add
 *   up to the amount the caller actually posted, which is the one property an inclusive régime
 *   cannot give up: the gross is a fact, the split is arithmetic.
 *
 * **What this seam deliberately does not reach**, because a socket's limits are part of its
 * contract. A *compound* base (Canadian PST computed on a GST-inclusive amount) needs the result of
 * another code and therefore an ordering between codes, which this function cannot see — it is
 * handed one amount and one rate. Tax at payment time (withholding, split payment) is not a base
 * question at all but a timing one, and margin schemes need the purchase price of the thing sold,
 * which is not in the posting. Those stay named and unbuilt; the repertoire question
 * (`core/src/CLAUDE.md`, "what would reopen it") is *not* settled by this change, because a
 * mechanism still is not describable as pure data.
 */
export type TaxBaseKind = 'net' | 'inclusive';

const KINDS: readonly TaxBaseKind[] = ['net', 'inclusive'];

export function isTaxBaseKind(value: unknown): value is TaxBaseKind {
  return typeof value === 'string' && (KINDS as readonly string[]).includes(value);
}

/** The declared repertoire, for the contract test and for `tenantConfiguration`. */
export function allTaxBaseKinds(): readonly TaxBaseKind[] {
  return KINDS;
}

export function splitByBase(kind: TaxBaseKind, amount: Money, rate: string, currency: Currency): BaseSplit {
  if (kind === 'net') {
    return {
      base: amount,
      tax: Money.fromCalculation(new Big(amount.amountAsString()).times(rate).div(100), currency),
    };
  }

  const divisor = new Big(100).plus(rate);
  if (divisor.eq(0)) {
    // A rate of −100 % would make the gross zero for any base, so no split exists. Refused rather
    // than divided: a NaN reaching Money would surface far from the pack that caused it.
    throw new DomainError('E_TAXCODE_INVALID', 'an inclusive tax base needs a rate other than -100', { rate });
  }

  const tax = Money.fromCalculation(new Big(amount.amountAsString()).times(rate).div(divisor), currency);
  return { base: amount.subtract(tax), tax };
}
