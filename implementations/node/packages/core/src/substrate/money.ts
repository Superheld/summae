import Big from 'big.js';
import { Currency } from './currency.js';
import { CurrencyMismatch, InvalidValue } from './errors.js';

// Determinism appendix §2: commercial HALF_UP, away from zero at exactly .5
// (NOT banker's). big.js `roundHalfUp` (=1) rounds away-from-zero.
const HALF_UP = Big.roundHalfUp;

/** Decimal places of a Big value (big.js holds coefficient `c` + exponent `e`). */
function decimalPlaces(value: Big): number {
  return Math.max(0, value.c.length - value.e - 1);
}

/**
 * Amount = decimal value + currency, never float (Glossary `money`).
 *
 * Determinism rules (determinismus.md §2):
 * - rounding: commercial half-up, away from zero at exactly .5.
 * - allocate: largest-remainder, tie → first part; Σ parts = amount, always.
 *
 * The amount internally always lies exactly on the currency scale.
 */
export class Money {
  private constructor(
    private readonly amount: Big,
    readonly currency: Currency,
  ) {}

  private static currencyOf(currency: Currency | string): Currency {
    return currency instanceof Currency ? currency : Currency.of(currency);
  }

  /**
   * Exact amount on the currency scale. More decimal places than the currency
   * allows is an error — nothing is ever silently rounded here.
   */
  static of(amount: string, currency: Currency | string): Money {
    const cur = Money.currencyOf(currency);
    let value: Big;
    try {
      value = new Big(amount);
    } catch {
      throw new InvalidValue(`Invalid amount "${amount}" for currency ${cur.code} (scale ${cur.scale})`);
    }
    const scaled = value.round(cur.scale, HALF_UP);
    if (!scaled.eq(value)) {
      throw new InvalidValue(`Invalid amount "${amount}" for currency ${cur.code} (scale ${cur.scale})`);
    }
    return new Money(scaled, cur);
  }

  /**
   * Bring the result of a calculation to the currency scale: half-up
   * (2.225 → 2.23, -2.345 → -2.35). The only path on which Money rounds.
   */
  static fromCalculation(value: Big | string, currency: Currency | string): Money {
    const cur = Money.currencyOf(currency);
    let big: Big;
    try {
      big = value instanceof Big ? value : new Big(value);
    } catch {
      throw new InvalidValue(`Invalid calculation value for currency ${cur.code}`);
    }
    return new Money(big.round(cur.scale, HALF_UP), cur);
  }

  static zero(currency: Currency | string): Money {
    return new Money(new Big(0), Money.currencyOf(currency));
  }

  add(other: Money): Money {
    this.assertSameCurrency(other);
    return new Money(this.amount.plus(other.amount), this.currency);
  }

  subtract(other: Money): Money {
    this.assertSameCurrency(other);
    return new Money(this.amount.minus(other.amount), this.currency);
  }

  negate(): Money {
    return new Money(this.amount.times(-1), this.currency);
  }

  abs(): Money {
    return new Money(this.amount.abs(), this.currency);
  }

  /** -1, 0 oder 1 */
  compareTo(other: Money): number {
    this.assertSameCurrency(other);
    return this.amount.cmp(other.amount);
  }

  equals(other: Money): boolean {
    return this.currency.equals(other.currency) && this.amount.eq(other.amount);
  }

  isZero(): boolean {
    return this.amount.eq(0);
  }

  isPositive(): boolean {
    return this.amount.gt(0);
  }

  isNegative(): boolean {
    return this.amount.lt(0);
  }

  /**
   * Distributes the amount by weights (determinismus.md §2): largest-remainder,
   * tie → first part. Σ parts = amount, always.
   *
   * Weights: non-negative decimal values (number or string), sum > 0.
   * Negative amounts are distributed as a negated mirror image.
   */
  allocate(...weights: Array<number | string>): Money[] {
    if (weights.length === 0) {
      throw new InvalidValue('allocate needs at least one weight');
    }

    if (this.isNegative()) {
      return this.negate()
        .allocate(...weights)
        .map((part) => part.negate());
    }

    const scale = this.currency.scale;
    const integerWeights = Money.normalizeWeights(weights);

    let weightSum = 0n;
    for (const weight of integerWeights) {
      weightSum += weight;
    }
    if (weightSum === 0n) {
      throw new InvalidValue('Weight sum must be > 0');
    }

    const factor = new Big(10).pow(scale);
    const totalMinor = BigInt(this.amount.times(factor).toFixed(0));

    const parts = integerWeights.map((weight) => {
      const product = totalMinor * weight;
      return { base: product / weightSum, remainder: product % weightSum };
    });

    let assigned = 0n;
    for (const part of parts) {
      assigned += part.base;
    }

    // Remainder distribution by largest remainder; tie → smallest index.
    const leftover = Number(totalMinor - assigned);
    const order = parts.map((part, index) => ({ remainder: part.remainder, index }));
    order.sort((a, b) =>
      a.remainder > b.remainder ? -1 : a.remainder < b.remainder ? 1 : a.index - b.index,
    );
    for (let i = 0; i < leftover; i++) {
      parts[order[i]!.index]!.base += 1n;
    }

    return parts.map((part) => Money.fromMinor(part.base, scale, this.currency));
  }

  /** Distribution into n equal parts (collective-item fifths, depreciation monthly rates). */
  allocateEvenly(parts: number): Money[] {
    if (parts < 1) {
      throw new InvalidValue('allocateEvenly needs at least one part');
    }
    return this.allocate(...new Array<number>(parts).fill(1));
  }

  /** Amount as a string decimal with fixed scale, e.g. "1234.56" (datenformat.md). */
  amountAsString(): string {
    return this.amount.toFixed(this.currency.scale);
  }

  toJSON(): { amount: string; currency: string } {
    return { amount: this.amountAsString(), currency: this.currency.code };
  }

  toString(): string {
    return `${this.amountAsString()} ${this.currency.code}`;
  }

  private static fromMinor(minor: bigint, scale: number, currency: Currency): Money {
    const value = new Big(minor.toString()).div(new Big(10).pow(scale));
    return new Money(value.round(scale, HALF_UP), currency);
  }

  /** Bring decimal weights losslessly to integer weights of the same scale. */
  private static normalizeWeights(weights: Array<number | string>): bigint[] {
    const decimals: Big[] = [];
    let maxScale = 0;

    for (const weight of weights) {
      let decimal: Big;
      try {
        decimal = new Big(weight);
      } catch {
        throw new InvalidValue(`Invalid weight "${weight}"`);
      }
      if (decimal.lt(0)) {
        throw new InvalidValue('Weights must not be negative');
      }
      decimals.push(decimal);
      maxScale = Math.max(maxScale, decimalPlaces(decimal));
    }

    const factor = new Big(10).pow(maxScale);
    return decimals.map((decimal) => BigInt(decimal.times(factor).toFixed(0)));
  }

  private assertSameCurrency(other: Money): void {
    if (!this.currency.equals(other.currency)) {
      throw new CurrencyMismatch(
        `Currencies do not mix: ${this.currency.code} vs. ${other.currency.code}`,
      );
    }
  }
}
