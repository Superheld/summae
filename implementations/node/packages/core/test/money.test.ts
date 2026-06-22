import { describe, it, expect } from 'vitest';
import { Money, CurrencyMismatch, InvalidValue } from '../src/index.js';

const amounts = (parts: Money[]): string[] => parts.map((m) => m.amountAsString());

describe('Money — construction & scale', () => {
  it('normalizes to the currency scale', () => {
    expect(Money.of('1234.56', 'EUR').amountAsString()).toBe('1234.56');
    expect(Money.of('5', 'EUR').amountAsString()).toBe('5.00');
    expect(Money.of('5.1', 'EUR').amountAsString()).toBe('5.10');
  });

  it('rejects too many decimal places (no silent rounding)', () => {
    expect(() => Money.of('1234.567', 'EUR')).toThrow(InvalidValue);
  });

  it('rejects garbage', () => {
    expect(() => Money.of('12,34', 'EUR')).toThrow(InvalidValue);
  });
});

describe('Money.fromCalculation — half-up away-from-zero (determinismus.md §2)', () => {
  it.each([
    ['2.225', '2.23'], // half-even trap
    ['2.345', '2.35'], // commercial positive
    ['-2.345', '-2.35'], // commercial negative, away from zero
    ['2.2249', '2.22'], // below the midpoint
    ['2.2251', '2.23'], // above the midpoint
  ])('rounds %s → %s', (input, expected) => {
    expect(Money.fromCalculation(input, 'EUR').amountAsString()).toBe(expected);
  });
});

describe('Money — arithmetic', () => {
  it('adds, subtracts, negates, compares', () => {
    const a = Money.of('100.00', 'EUR');
    const b = Money.of('0.05', 'EUR');

    expect(a.add(b).amountAsString()).toBe('100.05');
    expect(a.subtract(b).amountAsString()).toBe('99.95');
    expect(a.negate().amountAsString()).toBe('-100.00');
    expect(a.negate().abs().amountAsString()).toBe('100.00');
    expect(a.compareTo(b)).toBe(1);
    expect(b.compareTo(a)).toBe(-1);
    expect(a.compareTo(Money.of('100.00', 'EUR'))).toBe(0);
    expect(a.equals(Money.of('100.00', 'EUR'))).toBe(true);
    expect(Money.zero('EUR').isZero()).toBe(true);
    expect(a.isPositive()).toBe(true);
    expect(a.negate().isNegative()).toBe(true);
  });

  it('does not mix currencies', () => {
    expect(() => Money.of('1.00', 'EUR').add(Money.of('1.00', 'USD'))).toThrow(CurrencyMismatch);
  });
});

describe('Money.allocate — Largest-Remainder (determinismus.md §2)', () => {
  it('100.00 into 3 → 33.34/33.33/33.33 (tie → first)', () => {
    expect(amounts(Money.of('100.00', 'EUR').allocateEvenly(3))).toEqual(['33.34', '33.33', '33.33']);
  });

  it('by weights 3:1:1 → 60/20/20', () => {
    expect(amounts(Money.of('100.00', 'EUR').allocate(3, 1, 1))).toEqual(['60.00', '20.00', '20.00']);
  });

  it('accepts decimal weights 0.5:0.25:0.25 → 50/25/25', () => {
    expect(amounts(Money.of('100.00', 'EUR').allocate('0.5', '0.25', '0.25'))).toEqual([
      '50.00',
      '25.00',
      '25.00',
    ]);
  });

  it('distributes scarce minor units 0.05 into 3 → 0.02/0.02/0.01', () => {
    expect(amounts(Money.of('0.05', 'EUR').allocateEvenly(3))).toEqual(['0.02', '0.02', '0.01']);
  });

  it('negative amount mirrors the positive', () => {
    expect(amounts(Money.of('-100.00', 'EUR').allocateEvenly(3))).toEqual([
      '-33.34',
      '-33.33',
      '-33.33',
    ]);
  });

  it('depreciation over 36 months: Σ exact, first 28 get the extra cent', () => {
    const total = Money.of('1000.00', 'EUR');
    const parts = total.allocateEvenly(36);
    expect(parts).toHaveLength(36);

    let sum = Money.zero('EUR');
    for (const part of parts) sum = sum.add(part);
    expect(sum.equals(total)).toBe(true);

    expect(parts[0]!.amountAsString()).toBe('27.78');
    expect(parts[27]!.amountAsString()).toBe('27.78');
    expect(parts[28]!.amountAsString()).toBe('27.77');
    expect(parts[35]!.amountAsString()).toBe('27.77');
  });

  it('zero amount → all zeros', () => {
    expect(amounts(Money.zero('EUR').allocateEvenly(3))).toEqual(['0.00', '0.00', '0.00']);
  });

  it('zero weight gets nothing', () => {
    expect(amounts(Money.of('0.03', 'EUR').allocate(0, 1, 1))).toEqual(['0.00', '0.02', '0.01']);
  });

  it('rejects invalid weights', () => {
    const money = Money.of('1.00', 'EUR');
    expect(() => money.allocate()).toThrow(InvalidValue);
    expect(() => money.allocate(0, 0)).toThrow(InvalidValue);
    expect(() => money.allocate('-1', '2')).toThrow(InvalidValue);
    expect(() => money.allocate('abc')).toThrow(InvalidValue);
  });
});

describe('Money — serialization (datenformat.md)', () => {
  it('toJSON yields {amount, currency}', () => {
    expect(Money.of('1234.56', 'EUR').toJSON()).toEqual({ amount: '1234.56', currency: 'EUR' });
  });
});
