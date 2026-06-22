import { describe, it, expect } from 'vitest';
import {
  Currency,
  Uuid,
  FixedClock,
  DeterministicIdGenerator,
  InvalidValue,
} from '../src/index.js';

describe('Currency', () => {
  it('has a fixed scale per currency', () => {
    expect(Currency.of('EUR').scale).toBe(2);
    expect(Currency.of('JPY').scale).toBe(0);
    expect(Currency.of('KWD').scale).toBe(3);
  });

  it('rejects invalid codes', () => {
    expect(() => Currency.of('eur')).toThrow(InvalidValue);
    expect(() => Currency.of('EURO')).toThrow(InvalidValue);
  });
});

describe('Uuid', () => {
  it('accepts valid UUIDs and normalizes to lowercase', () => {
    expect(Uuid.fromString('0192F0C1-0000-7000-8000-000000000001').value).toBe(
      '0192f0c1-0000-7000-8000-000000000001',
    );
  });

  it('rejects invalid UUIDs', () => {
    expect(() => Uuid.fromString('nicht-uuid')).toThrow(InvalidValue);
  });

  it('orders byte-wise (= chronologically for v7)', () => {
    const a = Uuid.fromString('00000000-0000-7000-8000-000000000001');
    const b = Uuid.fromString('00000000-0000-7000-8000-000000000002');
    expect(a.compareTo(b)).toBe(-1);
    expect(b.compareTo(a)).toBe(1);
    expect(a.compareTo(a)).toBe(0);
  });
});

describe('DeterministicIdGenerator', () => {
  it('is deterministic (same clock + counter → same sequence)', () => {
    const seq = () => {
      const gen = new DeterministicIdGenerator(FixedClock.at('2026-01-01T00:00:00.000Z'));
      return [gen.next().value, gen.next().value, gen.next().value];
    };
    expect(seq()).toEqual(seq());
  });

  it('carries UUID version 7 and increments the sequence part', () => {
    const gen = new DeterministicIdGenerator(FixedClock.at('2026-01-01T00:00:00.000Z'));
    const first = gen.next().value;
    const second = gen.next().value;

    // Version nibble (1st char of the 3rd group) is 7.
    expect(first.split('-')[2]!.startsWith('7')).toBe(true);
    expect(first).not.toBe(second);
    // Counter sits in the tail: …001 before …002.
    expect(first < second).toBe(true);
  });
});
