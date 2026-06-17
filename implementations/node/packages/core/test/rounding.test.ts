import { describe, it, expect } from 'vitest';
import { roundHalfUp } from '../src/money/rounding.js';

describe('roundHalfUp — half-up away-from-zero (Determinismus-Anhang)', () => {
  it('rundet .5 von Null weg, nicht banker (2.225 → 2.23)', () => {
    expect(roundHalfUp('2.225', 2)).toBe('2.23');
  });

  it('rundet auch negativ von Null weg (-2.225 → -2.23)', () => {
    expect(roundHalfUp('-2.225', 2)).toBe('-2.23');
  });

  it('rundet unter .5 ab (2.224 → 2.22)', () => {
    expect(roundHalfUp('2.224', 2)).toBe('2.22');
  });

  it('liefert feste Skala, Nachkommastellen aufgefüllt (2 → 2.00)', () => {
    expect(roundHalfUp('2', 2)).toBe('2.00');
  });
});
