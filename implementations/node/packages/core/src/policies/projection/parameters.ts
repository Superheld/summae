/**
 * Reading projection parameters — one place that says what each declared type means.
 *
 * The types come from the parameter contract (`testing/testsuite/schema/api-parameters.json`),
 * which the dispatcher enforces before a projection ever sees the params. So the readers below
 * are NOT validation: by the time a projection runs, a declared parameter is either absent or
 * of the right type. They exist because the previous idiom — `typeof params.x === 'number' ? x
 * : <default>` at every single call site — used the type check as a silent policy decision, and
 * a wrong value became a plausible default 25 times over.
 *
 * They still do the check, for the two cases the dispatcher does not cover: a projection
 * constructed directly (tests, embedding code) and TypeScript's own narrowing, which cannot
 * know what the dispatcher already proved.
 */

/**
 * The types the parameter contract knows.
 *
 * The first four describe a projection's parameters, which are scalars by nature. Operations
 * take structure as well — a voucher, a list of lines, an amount — so `object`, `array` and
 * `money` came with the operation contract (F-9). Their *inner* shape stays the operation's
 * business: this contract answers what a key may be, not what a voucher looks like.
 *
 * `money` is worth its own type rather than being an `object`, because the mistake it catches is
 * a real one: an amount passed as a JSON number was silently ignored by every operation that read
 * it with an is-object check, and the operation carried on with its default.
 */
export type ParameterType = 'integer' | 'string' | 'date' | 'boolean' | 'object' | 'array' | 'money';

/** ISO calendar date, zoneless. Whether the date exists is `CalendarDate`'s business. */
const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * A whole number. JSON has no int/float split, so 2026 and 2026.0 are the same value and both
 * pass — a rule that told them apart would be unimplementable here, because `JSON.parse` has
 * already merged them. 2026.4 does not pass. Bounded to 2^53-1, beyond which a JS number is no
 * longer an exact integer while PHP's int still is.
 */
export function isIntegerParam(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && Math.abs(value) <= Number.MAX_SAFE_INTEGER;
}

export function matchesParameterType(value: unknown, type: ParameterType): boolean {
  switch (type) {
    case 'integer':
      return isIntegerParam(value);
    case 'string':
      return typeof value === 'string' && value !== '';
    case 'date':
      return typeof value === 'string' && ISO_DATE.test(value);
    case 'boolean':
      return typeof value === 'boolean';
    case 'object':
      // An empty list counts as an empty object, and the reason is the other language:
      // `json_decode('{}', true)` and `json_decode('[]', true)` both yield `[]` in PHP, which
      // cannot tell them apart. Accepting it here rather than rejecting it keeps the two
      // implementations answering the same question the same way, which outranks being strict.
      if (Array.isArray(value)) return value.length === 0;
      return typeof value === 'object' && value !== null;
    case 'array':
      return Array.isArray(value);
    case 'money':
      return (
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        typeof (value as Record<string, unknown>).amount === 'string'
      );
  }
}

/** An integer parameter, or the projection's documented default when it is absent. */
export function integerOr(value: unknown, fallback: number): number {
  return isIntegerParam(value) ? value : fallback;
}

/** An integer parameter, or `null` when it is absent (= "not scoped to a year"). */
export function integerOrNull(value: unknown): number | null {
  return isIntegerParam(value) ? value : null;
}
