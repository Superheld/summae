import Big from 'big.js';
import { Currency } from './currency.js';
import { CurrencyMismatch, InvalidValue } from './errors.js';

// Determinismus-Anhang §2: kaufmännisch HALF_UP, von Null weg bei genau .5
// (NICHT banker's). big.js `roundHalfUp` (=1) rundet away-from-zero.
const HALF_UP = Big.roundHalfUp;

/** Nachkommastellen eines Big-Werts (big.js hält Koeffizient `c` + Exponent `e`). */
function decimalPlaces(value: Big): number {
  return Math.max(0, value.c.length - value.e - 1);
}

/**
 * Betrag = Dezimalwert + Währung, nie Float (Glossar `money`).
 *
 * Determinismus-Regeln (determinismus.md §2):
 * - Rundung: kaufmännisch half-up, von Null weg bei genau .5.
 * - allocate: Largest-Remainder, Gleichstand → erster Teil; Σ Teile = Betrag, immer.
 *
 * Der Betrag liegt intern immer exakt auf der Währungsskala.
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
   * Exakter Betrag auf Währungsskala. Mehr Nachkommastellen als die Währung
   * erlaubt sind ein Fehler — hier wird nie still gerundet.
   */
  static of(amount: string, currency: Currency | string): Money {
    const cur = Money.currencyOf(currency);
    let value: Big;
    try {
      value = new Big(amount);
    } catch {
      throw new InvalidValue(`Ungültiger Betrag "${amount}" für Währung ${cur.code} (Skala ${cur.scale})`);
    }
    const scaled = value.round(cur.scale, HALF_UP);
    if (!scaled.eq(value)) {
      throw new InvalidValue(`Ungültiger Betrag "${amount}" für Währung ${cur.code} (Skala ${cur.scale})`);
    }
    return new Money(scaled, cur);
  }

  /**
   * Ergebnis einer Rechnung auf Währungsskala bringen: half-up
   * (2.225 → 2.23, -2.345 → -2.35). Einziger Weg, auf dem Money rundet.
   */
  static fromCalculation(value: Big | string, currency: Currency | string): Money {
    const cur = Money.currencyOf(currency);
    let big: Big;
    try {
      big = value instanceof Big ? value : new Big(value);
    } catch {
      throw new InvalidValue(`Ungültiger Rechenwert für Währung ${cur.code}`);
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
   * Verteilt den Betrag nach Gewichten (determinismus.md §2): Largest-Remainder,
   * Gleichstand → erster Teil. Σ Teile = Betrag, immer.
   *
   * Gewichte: nicht-negative Dezimalwerte (Zahl oder String), Summe > 0.
   * Negative Beträge werden als negiertes Spiegelbild verteilt.
   */
  allocate(...weights: Array<number | string>): Money[] {
    if (weights.length === 0) {
      throw new InvalidValue('allocate braucht mindestens ein Gewicht');
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
      throw new InvalidValue('Gewichtssumme muss > 0 sein');
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

    // Restverteilung nach größtem Rest; Gleichstand → kleinster Index.
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

  /** Verteilung in n gleiche Teile (Sammelposten-Fünftel, AfA-Monatsraten). */
  allocateEvenly(parts: number): Money[] {
    if (parts < 1) {
      throw new InvalidValue('allocateEvenly braucht mindestens einen Teil');
    }
    return this.allocate(...new Array<number>(parts).fill(1));
  }

  /** Betrag als String-Dezimal mit fester Skala, z. B. "1234.56" (datenformat.md). */
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

  /** Dezimalgewichte verlustfrei auf ganzzahlige Gewichte gleicher Skala bringen. */
  private static normalizeWeights(weights: Array<number | string>): bigint[] {
    const decimals: Big[] = [];
    let maxScale = 0;

    for (const weight of weights) {
      let decimal: Big;
      try {
        decimal = new Big(weight);
      } catch {
        throw new InvalidValue(`Ungültiges Gewicht "${weight}"`);
      }
      if (decimal.lt(0)) {
        throw new InvalidValue('Gewichte dürfen nicht negativ sein');
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
        `Währungen mischen sich nicht: ${this.currency.code} vs. ${other.currency.code}`,
      );
    }
  }
}
