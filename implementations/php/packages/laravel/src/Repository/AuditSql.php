<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

/**
 * The dialect seam of the audit query, as pure strings (SPEC-018).
 *
 * `objectType`, `action`, `actor`, `objectId` and the recording moment live inside the JSON payload
 * rather than in columns, so a filter has to read them there — SQLite with `json_extract`, Postgres
 * with `->>`. Extracted into its own class for two reasons, and neither is tidiness:
 *
 * - **Both branches are checkable without a database.** The adapter suite runs on SQLite, so the
 *   Postgres strings would otherwise be reached only by the conformance run against a real server —
 *   proof that they work, but not visible to the unit suite that guards the coverage floor. A wrong
 *   Postgres string would then be found late and far from here.
 * - **Raw SQL stays literal.** Every predicate is a whole literal per field rather than assembled
 *   from a variable, which is the habit worth keeping exactly where caller-supplied values meet the
 *   query.
 *
 * Why not columns: adding one is easy, filling it for rows that already exist is a data migration,
 * and neither language has a migration runner. An unfilled column would make the filter miss exactly
 * the history an audit is about, which is worse than no filter.
 */
final class AuditSql
{
    private function __construct()
    {
    }

    /** @return literal-string */
    public static function equals(bool $postgres, string $field): string
    {
        return $postgres
            ? match ($field) {
                'objectType' => "payload->>'objectType' = ?",
                'action' => "payload->>'action' = ?",
                'actor' => "payload->>'actor' = ?",
                default => "payload->>'objectId' = ?",
            }
            : match ($field) {
                'objectType' => "json_extract(payload, '$.objectType') = ?",
                'action' => "json_extract(payload, '$.action') = ?",
                'actor' => "json_extract(payload, '$.actor') = ?",
                default => "json_extract(payload, '$.objectId') = ?",
            };
    }

    /**
     * `at` is a canonical UTC timestamp, so its first ten characters are the calendar date and
     * compare as one.
     *
     * @return literal-string
     */
    public static function dateAtLeast(bool $postgres): string
    {
        return $postgres
            ? "substr(payload->>'at', 1, 10) >= ?"
            : "substr(json_extract(payload, '$.at'), 1, 10) >= ?";
    }

    /** @return literal-string */
    public static function dateAtMost(bool $postgres): string
    {
        return $postgres
            ? "substr(payload->>'at', 1, 10) <= ?"
            : "substr(json_extract(payload, '$.at'), 1, 10) <= ?";
    }
}
