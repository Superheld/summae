import { describe, it, expect } from 'vitest';
import { canonicalJson, Money } from '../src/index.js';

describe('canonicalJson — RFC 8785 (JCS)', () => {
  it('sorts object keys', () => {
    expect(canonicalJson({ b: 1, a: 2 })).toBe('{"a":2,"b":1}');
  });

  it('sorts nested, keeps array order', () => {
    expect(canonicalJson({ obj: { y: true, x: null }, list: [3, 1, 2] })).toBe(
      '{"list":[3,1,2],"obj":{"x":null,"y":true}}',
    );
  });

  it('sorts by UTF-16 code units, not codepoints', () => {
    // U+1F600 (surrogate pair D83D DE00) sorts BEFORE U+FB33, even though codepoint is larger.
    expect(canonicalJson({ '\u{FB33}': 1, '\u{1F600}': 2 })).toBe(
      `{"${'\u{1F600}'}":2,"${'\u{FB33}'}":1}`,
    );
  });

  it('numeric string keys stay strings, codepoint-sorted', () => {
    expect(canonicalJson({ '81': 1, '66': 2 })).toBe('{"66":2,"81":1}');
  });

  it('escapes strings RFC-compliant (hex lowercase, short escapes)', () => {
    expect(canonicalJson('Zeile\n\tTab \x01 "quote" \\ ü€')).toBe(
      '"Zeile\\n\\tTab \\u0001 \\"quote\\" \\\\ ü€"',
    );
  });

  it('serializes integers plainly', () => {
    expect(canonicalJson([0, -42, 9007199254740991])).toBe('[0,-42,9007199254740991]');
  });

  it('rejects floats', () => {
    expect(() => canonicalJson({ amount: 12.34 })).toThrow();
  });

  it('rejects unsafe integers (> 2^53-1)', () => {
    expect(() => canonicalJson(9007199254740992)).toThrow();
  });

  it('empty array → [], empty object → {}', () => {
    expect(canonicalJson([])).toBe('[]');
    expect(canonicalJson({})).toBe('{}');
  });

  it('unwraps toJSON objects (JsonSerializable counterpart)', () => {
    expect(canonicalJson(Money.of('1234.56', 'EUR'))).toBe('{"amount":"1234.56","currency":"EUR"}');
  });

  it('is deterministic for equivalent inputs', () => {
    const a = { z: [1, 2], a: { k: 'v', b: 'w' } };
    const b = { a: { b: 'w', k: 'v' }, z: [1, 2] };
    expect(canonicalJson(a)).toBe(canonicalJson(b));
  });
});
