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

/** The four types the parameter contract knows. */
export type ParameterType = 'integer' | 'string' | 'date' | 'boolean';

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
