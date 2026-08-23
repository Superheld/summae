<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

/**
 * Reading projection parameters — one place that says what each declared type means.
 *
 * The types come from the parameter contract (testing/testsuite/schema/api-parameters.json),
 * which the dispatcher enforces before a projection ever sees the params. So the readers below
 * are NOT validation: by the time a projection runs, a declared parameter is either absent or
 * of the right type. They exist because the previous idiom — `is_int($params['x'] ?? null) ?
 * $params['x'] : <default>` at every single call site — used the type check as a silent policy
 * decision, and a wrong value became a plausible default 18 times over.
 *
 * They also carry the int/float normalisation, which is the whole cross-language point:
 * `json_decode('2026.0')` yields a float here while `JSON.parse('2026.0')` yields an integer in
 * Node. `is_int` therefore answered "no" to a number Node had long since accepted, and the same
 * JSON call produced a VAT return in one language and an empty one in the other. A whole-valued
 * float IS the integer it spells; a fractional one is a caller mistake and never reaches here.
 */
final class Parameters
{
    /** Beyond 2^53-1 a JS number is no longer an exact integer while a PHP int still is. */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /** ISO calendar date, zoneless. Whether the date exists is CalendarDate's business. */
    private const ISO_DATE = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * A whole number, as int. Returns null for anything else — including a fractional float,
     * which is not a year to be rounded into shape.
     */
    public static function asInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return abs($value) <= self::MAX_SAFE_INTEGER ? $value : null;
        }

        if (is_float($value) && $value === floor($value) && abs($value) <= (float) self::MAX_SAFE_INTEGER) {
            return (int) $value;
        }

        return null;
    }

    public static function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'integer' => self::asInteger($value) !== null,
            'string' => is_string($value) && $value !== '',
            'date' => is_string($value) && preg_match(self::ISO_DATE, $value) === 1,
            'boolean' => is_bool($value),
            // Structure, for the operation contract (F-9). The inner shape stays the operation's
            // business: this answers what a key MAY be, not what a voucher looks like. `money` is
            // its own type rather than an `object` because the mistake it catches is a real one —
            // an amount passed as a JSON number was silently ignored by every operation reading it
            // with an is-array check, and the operation carried on with its default.
            //
            // The empty case is where the two languages could drift and therefore says so out
            // loud: `json_decode('{}', true)` and `json_decode('[]', true)` both yield `[]` here,
            // while Node keeps `{}` and `[]` apart. So an empty list is accepted where an object
            // is declared — in BOTH languages, see matchesParameterType — rather than one of them
            // rejecting an input the other takes.
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'money' => is_array($value) && !array_is_list($value) && is_string($value['amount'] ?? null),
            default => false,
        };
    }

    /** An integer parameter, or the projection's documented default when it is absent. */
    public static function integerOr(mixed $value, int $fallback): int
    {
        return self::asInteger($value) ?? $fallback;
    }

    /** An integer parameter, or null when it is absent (= "not scoped to a year"). */
    public static function integerOrNull(mixed $value): ?int
    {
        return self::asInteger($value);
    }
}
