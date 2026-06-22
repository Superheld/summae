import { InvalidValue } from './errors.js';

/**
 * Canonical JSON per RFC 8785 (JCS) — basis of all hashes and
 * determinism comparisons (datenformat.md principle 1, determinismus.md §5).
 *
 * Deliberate deviation from the full RFC: floats are rejected instead of
 * ECMAScript-serialized — the data format forbids JSON Number for amounts
 * (string decimal), and integers (sequenceNumber, year) are exactly representable.
 *
 * Key sorting by UTF-16 code units: JS strings are UTF-16, the
 * native `Array#sort` compares exactly by that — RFC-compliant without manual work
 * (surrogate pairs sort before U+E000..U+FFFF).
 *
 * Objects with `toJSON()` are unpacked (JS counterpart to PHP's JsonSerializable).
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
        'Floats are forbidden in the data format (amounts as string decimal, datenformat.md)',
      );
    }
    if (Math.abs(value) > MAX_SAFE_INTEGER) {
      throw new InvalidValue(`Integer outside the safe range (|x| > 2^53-1): ${value}`);
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
  throw new InvalidValue(`Non-serializable type: ${typeof value}`);
}

function encodeObject(object: Record<string, unknown>): string {
  const keys = Object.keys(object);
  if (keys.length === 0) {
    return '{}';
  }
  // Native sort = UTF-16 code unit order = RFC 8785.
  keys.sort();
  return `{${keys.map((key) => `${encodeString(key)}:${encodeValue(object[key])}`).join(',')}}`;
}

/**
 * JCS string serialization (RFC 8785 §3.2.2.2): short escapes for the usual
 * control characters, \u00xx (lowercase) for the rest below U+0020, everything else raw.
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
