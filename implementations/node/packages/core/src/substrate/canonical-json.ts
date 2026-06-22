import { InvalidValue } from './errors.js';

/**
 * Kanonisches JSON nach RFC 8785 (JCS) — Grundlage aller Hashes und
 * Determinismus-Vergleiche (datenformat.md Grundsatz 1, determinismus.md §5).
 *
 * Bewusste Abweichung vom vollen RFC: Floats werden abgelehnt statt
 * ECMAScript-serialisiert — das Datenformat verbietet JSON-Number für Beträge
 * (String-Dezimal), und Ganzzahlen (sequenceNumber, year) sind exakt darstellbar.
 *
 * Schlüsselsortierung nach UTF-16-Code-Units: JS-Strings sind UTF-16, der
 * native `Array#sort` vergleicht genau danach — RFC-konform ohne Handarbeit
 * (Surrogatpaare sortieren vor U+E000..U+FFFF).
 *
 * Objekte mit `toJSON()` werden entpackt (JS-Pendant zu PHPs JsonSerializable).
 */
const MAX_SAFE_INTEGER = 9007199254740991; // 2^53 - 1

export function canonicalJson(value: unknown): string {
  return encodeValue(value);
}

function encodeValue(value: unknown): string {
  if (value === null) {
    return 'null';
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  if (typeof value === 'number') {
    if (!Number.isInteger(value)) {
      throw new InvalidValue(
        'Floats sind im Datenformat verboten (Beträge als String-Dezimal, datenformat.md)',
      );
    }
    if (Math.abs(value) > MAX_SAFE_INTEGER) {
      throw new InvalidValue(`Ganzzahl außerhalb des sicheren Bereichs (|x| > 2^53-1): ${value}`);
    }
    return String(value);
  }
  if (typeof value === 'string') {
    return encodeString(value);
  }
  if (Array.isArray(value)) {
    return `[${value.map(encodeValue).join(',')}]`;
  }
  if (typeof value === 'object') {
    const candidate = value as { toJSON?: () => unknown };
    if (typeof candidate.toJSON === 'function') {
      return encodeValue(candidate.toJSON());
    }
    return encodeObject(value as Record<string, unknown>);
  }
  throw new InvalidValue(`Nicht serialisierbarer Typ: ${typeof value}`);
}

function encodeObject(object: Record<string, unknown>): string {
  const keys = Object.keys(object);
  if (keys.length === 0) {
    return '{}';
  }
  // Native Sortierung = UTF-16-Code-Unit-Reihenfolge = RFC 8785.
  keys.sort();
  return `{${keys.map((key) => `${encodeString(key)}:${encodeValue(object[key])}`).join(',')}}`;
}

/**
 * JCS-Stringserialisierung (RFC 8785 §3.2.2.2): kurze Escapes für die üblichen
 * Steuerzeichen, \u00xx (lowercase) für den Rest unter U+0020, alles andere roh.
 */
function encodeString(value: string): string {
  let out = '"';
  for (let i = 0; i < value.length; i++) {
    const char = value[i]!;
    const code = value.charCodeAt(i);
    if (char === '"') {
      out += '\\"';
    } else if (char === '\\') {
      out += '\\\\';
    } else if (code === 0x08) {
      out += '\\b';
    } else if (code === 0x09) {
      out += '\\t';
    } else if (code === 0x0a) {
      out += '\\n';
    } else if (code === 0x0c) {
      out += '\\f';
    } else if (code === 0x0d) {
      out += '\\r';
    } else if (code < 0x20) {
      out += `\\u${code.toString(16).padStart(4, '0')}`;
    } else {
      out += char;
    }
  }
  return `${out}"`;
}
