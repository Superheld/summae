import { describe, it, expect } from 'vitest';
import { canonicalJson, Money } from '../src/index.js';

describe('canonicalJson — RFC 8785 (JCS)', () => {
  it('sortiert Objektschlüssel', () => {
    expect(canonicalJson({ b: 1, a: 2 })).toBe('{"a":2,"b":1}');
  });

  it('sortiert verschachtelt, behält Array-Reihenfolge', () => {
    expect(canonicalJson({ obj: { y: true, x: null }, list: [3, 1, 2] })).toBe(
      '{"list":[3,1,2],"obj":{"x":null,"y":true}}',
    );
  });

  it('sortiert nach UTF-16-Code-Units, nicht Codepoints', () => {
    // U+1F600 (Surrogatpaar D83D DE00) sortiert VOR U+FB33, obwohl Codepoint größer.
    expect(canonicalJson({ '\u{FB33}': 1, '\u{1F600}': 2 })).toBe(
      `{"${'\u{1F600}'}":2,"${'\u{FB33}'}":1}`,
    );
  });

  it('numerische String-Schlüssel bleiben Strings, codepoint-sortiert', () => {
    expect(canonicalJson({ '81': 1, '66': 2 })).toBe('{"66":2,"81":1}');
  });

  it('escapt Strings RFC-konform (Hex lowercase, Kurz-Escapes)', () => {
    expect(canonicalJson('Zeile\n\tTab \x01 "quote" \\ ü€')).toBe(
      '"Zeile\\n\\tTab \\u0001 \\"quote\\" \\\\ ü€"',
    );
  });

  it('serialisiert Ganzzahlen schlicht', () => {
    expect(canonicalJson([0, -42, 9007199254740991])).toBe('[0,-42,9007199254740991]');
  });

  it('lehnt Floats ab', () => {
    expect(() => canonicalJson({ amount: 12.34 })).toThrow();
  });

  it('lehnt unsichere Ganzzahlen ab (> 2^53-1)', () => {
    expect(() => canonicalJson(9007199254740992)).toThrow();
  });

  it('leeres Array → [], leeres Objekt → {}', () => {
    expect(canonicalJson([])).toBe('[]');
    expect(canonicalJson({})).toBe('{}');
  });

  it('entpackt toJSON-Objekte (JsonSerializable-Pendant)', () => {
    expect(canonicalJson(Money.of('1234.56', 'EUR'))).toBe('{"amount":"1234.56","currency":"EUR"}');
  });

  it('ist deterministisch für äquivalente Eingaben', () => {
    const a = { z: [1, 2], a: { k: 'v', b: 'w' } };
    const b = { a: { b: 'w', k: 'v' }, z: [1, 2] };
    expect(canonicalJson(a)).toBe(canonicalJson(b));
  });
});
