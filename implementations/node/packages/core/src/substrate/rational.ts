import { InvalidValue } from './errors.js';

/**
 * An exact fraction of two integers, always in lowest terms with a positive denominator.
 *
 * It exists for one reason: the simultaneous-equation method of internal cost allocation solves a
 * linear system, and solving one means dividing. Every other number in this core either divides
 * evenly or is distributed with `Money.allocate`, so decimals with a fixed scale have been enough —
 * here they are not. 1/3 has no decimal form, and a solver that rounds mid-computation gives answers
 * that depend on the rounding, which is precisely what byte-identical results across two languages
 * cannot tolerate. Rationals have no such freedom: the arithmetic is exact, so PHP and TypeScript
 * cannot drift even in principle.
 *
 * Money never becomes a Rational and a Rational never becomes Money by rounding on its own — the
 * conversion back happens once, at the end, under the same largest-remainder rule the rest of the
 * core uses. PHP twin: `Substrate/Rational.php`.
 */

const DECIMAL_FORMAT = /^-?\d+(\.\d+)?$/;

function gcd(a: bigint, b: bigint): bigint {
  let x = a < 0n ? -a : a;
  let y = b < 0n ? -b : b;
  while (y !== 0n) {
    [x, y] = [y, x % y];
  }
  return x;
}

export class Rational {
  private constructor(
    readonly numerator: bigint,
    readonly denominator: bigint,
  ) {}

  static of(numerator: bigint | number, denominator: bigint | number = 1n): Rational {
    let num = BigInt(numerator);
    let den = BigInt(denominator);

    if (den === 0n) throw new InvalidValue('Rational: denominator must not be zero');

    if (den < 0n) {
      num = -num;
      den = -den;
    }

    if (num === 0n) return new Rational(0n, 1n);

    const divisor = gcd(num, den);
    return new Rational(num / divisor, den / divisor);
  }

  static zero(): Rational {
    return new Rational(0n, 1n);
  }

  /**
   * A decimal string ("0.25", "-3", "1.5") as an exact fraction. Weights arrive as strings because
   * that is how the data format carries them; nothing here rounds.
   */
  static fromDecimalString(value: string): Rational {
    if (!DECIMAL_FORMAT.test(value)) {
      throw new InvalidValue(`Rational: "${value}" is not a decimal number`);
    }

    const dot = value.indexOf('.');
    if (dot === -1) return Rational.of(BigInt(value));

    const decimals = value.length - dot - 1;
    return Rational.of(BigInt(value.replace('.', '')), 10n ** BigInt(decimals));
  }

  add(other: Rational): Rational {
    return Rational.of(
      this.numerator * other.denominator + other.numerator * this.denominator,
      this.denominator * other.denominator,
    );
  }

  subtract(other: Rational): Rational {
    return this.add(other.negate());
  }

  multiply(other: Rational): Rational {
    return Rational.of(this.numerator * other.numerator, this.denominator * other.denominator);
  }

  divide(other: Rational): Rational {
    if (other.isZero()) throw new InvalidValue('Rational: division by zero');
    return Rational.of(this.numerator * other.denominator, this.denominator * other.numerator);
  }

  negate(): Rational {
    return new Rational(-this.numerator, this.denominator);
  }

  isZero(): boolean {
    return this.numerator === 0n;
  }

  isNegative(): boolean {
    return this.numerator < 0n;
  }

  compareTo(other: Rational): number {
    const left = this.numerator * other.denominator;
    const right = other.numerator * this.denominator;
    return left < right ? -1 : left > right ? 1 : 0;
  }

  /**
   * The largest integer not greater than this fraction — floor, not truncation, so that -0.5 becomes
   * -1 and the fractional remainder stays in [0, 1) on both sides of zero. That property is what lets
   * largest-remainder rounding work unchanged for negative amounts, and cost centres do carry
   * negative balances (a credit note, a correction).
   */
  floorToBigInt(): bigint {
    const quotient = this.numerator / this.denominator;
    const remainder = this.numerator % this.denominator;
    return remainder < 0n ? quotient - 1n : quotient;
  }

  /** This fraction minus its floor: always in [0, 1). */
  fractionalPart(): Rational {
    return this.subtract(Rational.of(this.floorToBigInt()));
  }
}
