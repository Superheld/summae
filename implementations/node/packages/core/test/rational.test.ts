import { describe, it, expect } from 'vitest';
import { InvalidValue } from '../src/substrate/errors.js';
import { Rational } from '../src/substrate/rational.js';

/**
 * Rational exists so that the simultaneous-equation solver never rounds. These tests pin the two
 * properties that claim depends on — exactness and floor semantics — plus the refusals, because a
 * silent fallback here would be invisible in the numbers it produced. PHP twin: `RationalTest.php`.
 */
describe('Rational — exactness', () => {
  it('keeps thirds exact', () => {
    const third = Rational.of(1, 3);
    const whole = third.add(third).add(third);
    expect(whole.numerator).toBe(1n);
    expect(whole.denominator).toBe(1n);
  });

  it('normalises sign and reduces to lowest terms', () => {
    const value = Rational.of(4, -8);
    expect(value.numerator).toBe(-1n);
    expect(value.denominator).toBe(2n);
  });

  it('reads decimal strings exactly', () => {
    const value = Rational.fromDecimalString('0.25');
    expect(value.numerator).toBe(1n);
    expect(value.denominator).toBe(4n);
    expect(Rational.fromDecimalString('-3').compareTo(Rational.of(-3))).toBe(0);
  });
});

describe('Rational — floor semantics', () => {
  /**
   * Floor, not truncation. -0.5 becomes -1 so that the remainder stays in [0, 1) on both sides of
   * zero — the property that lets largest-remainder rounding work unchanged for a cost centre
   * carrying a credit.
   */
  it('floors towards negative infinity', () => {
    expect(Rational.of(-1, 2).floorToBigInt()).toBe(-1n);
    expect(Rational.of(1, 2).floorToBigInt()).toBe(0n);
    expect(Rational.of(-3).floorToBigInt()).toBe(-3n);

    const fraction = Rational.of(-1, 2).fractionalPart();
    expect(fraction.numerator).toBe(1n);
    expect(fraction.denominator).toBe(2n);
  });

  it('compares, subtracts and divides', () => {
    expect(Rational.of(2, 3).compareTo(Rational.of(1, 2))).toBe(1);
    expect(Rational.of(1, 2).compareTo(Rational.of(2, 3))).toBe(-1);
    expect(Rational.of(2, 4).compareTo(Rational.of(1, 2))).toBe(0);
    expect(Rational.of(1, 2).subtract(Rational.of(1, 2)).isZero()).toBe(true);
    expect(Rational.of(-1, 2).isNegative()).toBe(true);
    expect(Rational.of(1, 2).divide(Rational.of(1, 4)).compareTo(Rational.of(2))).toBe(0);
  });
});

describe('Rational — refusals', () => {
  it('refuses a zero denominator', () => {
    expect(() => Rational.of(1, 0)).toThrow(InvalidValue);
  });

  it('refuses division by zero', () => {
    expect(() => Rational.of(1).divide(Rational.zero())).toThrow(InvalidValue);
  });

  it('refuses a non-numeric string', () => {
    expect(() => Rational.fromDecimalString('1e3')).toThrow(InvalidValue);
  });
});
