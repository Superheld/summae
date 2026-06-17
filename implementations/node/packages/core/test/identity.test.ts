import { describe, it, expect } from 'vitest';
import {
  Currency,
  Uuid,
  FixedClock,
  DeterministicIdGenerator,
  InvalidValue,
} from '../src/index.js';

describe('Currency', () => {
  it('hat feste Skala je Währung', () => {
    expect(Currency.of('EUR').scale).toBe(2);
    expect(Currency.of('JPY').scale).toBe(0);
    expect(Currency.of('KWD').scale).toBe(3);
  });

  it('lehnt ungültige Codes ab', () => {
    expect(() => Currency.of('eur')).toThrow(InvalidValue);
    expect(() => Currency.of('EURO')).toThrow(InvalidValue);
  });
});

describe('Uuid', () => {
  it('akzeptiert gültige UUIDs und normalisiert auf lowercase', () => {
    expect(Uuid.fromString('0192F0C1-0000-7000-8000-000000000001').value).toBe(
      '0192f0c1-0000-7000-8000-000000000001',
    );
  });

  it('lehnt ungültige UUIDs ab', () => {
    expect(() => Uuid.fromString('nicht-uuid')).toThrow(InvalidValue);
  });

  it('ordnet byteweise (= zeitlich bei v7)', () => {
    const a = Uuid.fromString('00000000-0000-7000-8000-000000000001');
    const b = Uuid.fromString('00000000-0000-7000-8000-000000000002');
    expect(a.compareTo(b)).toBe(-1);
    expect(b.compareTo(a)).toBe(1);
    expect(a.compareTo(a)).toBe(0);
  });
});

describe('DeterministicIdGenerator', () => {
  it('ist deterministisch (gleiche Uhr + Zähler → gleiche Sequenz)', () => {
    const seq = () => {
      const gen = new DeterministicIdGenerator(FixedClock.at('2026-01-01T00:00:00.000Z'));
      return [gen.next().value, gen.next().value, gen.next().value];
    };
    expect(seq()).toEqual(seq());
  });

  it('trägt UUID-Version 7 und zählt den Sequenzteil hoch', () => {
    const gen = new DeterministicIdGenerator(FixedClock.at('2026-01-01T00:00:00.000Z'));
    const first = gen.next().value;
    const second = gen.next().value;

    // Version-Nibble (1. Zeichen der 3. Gruppe) ist 7.
    expect(first.split('-')[2]!.startsWith('7')).toBe(true);
    expect(first).not.toBe(second);
    // Zähler steckt im Schwanz: …001 vor …002.
    expect(first < second).toBe(true);
  });
});
