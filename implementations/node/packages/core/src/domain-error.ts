/**
 * Domain error with catalog code (fehlerkatalog.md). Contract part: same
 * violation → same code in all implementations. `message` is free-form,
 * `details` carries the IDs/values involved.
 */
export class DomainError extends Error {
  constructor(
    readonly errorCode: string,
    message = '',
    readonly details: Record<string, unknown> = {},
  ) {
    super(message !== '' ? message : errorCode);
    this.name = 'DomainError';
  }
}

/**
 * Echoes a rejected input value back in `details` so the caller can spot their typo.
 *
 * Only strings and safe integers are rendered; everything else becomes `null`. That is the
 * same line canonical JSON draws (integers are exactly representable, floats are rejected
 * rather than serialized) — and it has to be drawn here too, because a plain cast is not
 * the same operation in both languages: PHP renders `true` as `"1"` and Node as `"true"`,
 * PHP `1.0E+25` against Node `1e+25`. A detail that differs by language is a detail that
 * breaks equivalence, so it is better dropped than guessed at.
 */
export function rejectedValue(value: unknown): string | null {
  if (typeof value === 'string') {
    return value;
  }
  // Safe integers only — beyond 2^53 a JS number is no longer an exact integer, while the
  // PHP side is still an int, and the two would render differently.
  if (typeof value === 'number' && Number.isSafeInteger(value)) {
    return String(value);
  }
  return null;
}
