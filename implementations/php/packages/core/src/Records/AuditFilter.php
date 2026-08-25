<?php

declare(strict_types=1);

namespace Summae\Core\Records;

use Summae\Core\Substrate\CalendarDate;

/**
 * The criteria of `AuditTrail::find`, applied in memory (SPEC-018).
 *
 * One place, because the rule has to be the same wherever it runs: the in-memory adapter filters
 * with this, a database adapter filters with SQL, and the two must not answer differently. An
 * adapter suite in each language drives the same criteria through both and compares — the shared
 * data mechanism the quality policy calls for, applied to a port rather than to a format.
 *
 * The one asymmetry worth naming: a store can decline to *read* a row, and this cannot. That is
 * the whole point of the port method and the reason this class is a fallback rather than the
 * implementation.
 */
final class AuditFilter
{
    private function __construct()
    {
    }

    /**
     * @param list<AuditRecord> $records in capture order
     * @param array<string, mixed> $criteria
     *
     * @return array{records: list<AuditRecord>, count: int}
     */
    public static function apply(array $records, array $criteria): array
    {
        $matching = array_values(array_filter(
            $records,
            static fn (AuditRecord $record): bool => self::matches($record, $criteria),
        ));

        $offset = max(0, is_int($criteria['offset'] ?? null) ? $criteria['offset'] : 0);
        $limit = is_int($criteria['limit'] ?? null) ? $criteria['limit'] : null;

        // An absent limit means "everything from the offset on" — the same rule `journal` publishes.
        $page = $limit === null || $limit < 0
            ? array_slice($matching, $offset)
            : array_slice($matching, $offset, $limit);

        return ['records' => $page, 'count' => count($matching)];
    }

    /** @param array<string, mixed> $criteria */
    private static function matches(AuditRecord $record, array $criteria): bool
    {
        foreach ([['objectType', $record->objectType], ['actor', $record->actor], ['action', $record->action]] as [$key, $actual]) {
            $wanted = $criteria[$key] ?? null;
            if (is_string($wanted) && $wanted !== $actual) {
                return false;
            }
        }

        $objectId = $criteria['objectId'] ?? null;
        if (is_string($objectId) && $record->objectId->value !== $objectId) {
            return false;
        }

        $objectIds = $criteria['objectIds'] ?? null;
        if (is_array($objectIds) && !in_array($record->objectId->value, $objectIds, true)) {
            return false;
        }

        $date = CalendarDate::of($record->at->format('Y-m-d'));
        $from = $criteria['from'] ?? null;
        $to = $criteria['to'] ?? null;

        if (is_string($from) && $date->isBefore(CalendarDate::of($from))) {
            return false;
        }

        return !(is_string($to) && $date->isAfter(CalendarDate::of($to)));
    }
}
