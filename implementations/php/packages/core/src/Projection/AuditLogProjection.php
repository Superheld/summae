<?php

declare(strict_types=1);

namespace Summae\Core\Projection;

use Summae\Core\Port\AuditTrail;
use Summae\Core\Shared\CalendarDate;

/**
 * Änderungshistorie als Projektion (F-CORE-014, Review G3).
 * Reihenfolge = Erfassungsreihenfolge des Audit-Trails.
 */
final readonly class AuditLogProjection
{
    public function __construct(
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $params from?, to? (ISO-Daten)
     *
     * @return array{records: list<array<string, mixed>>}
     */
    public function compute(array $params): array
    {
        $from = is_string($params['from'] ?? null) ? CalendarDate::of($params['from']) : null;
        $to = is_string($params['to'] ?? null) ? CalendarDate::of($params['to']) : null;

        $records = [];

        foreach ($this->audit->all() as $record) {
            $date = CalendarDate::of($record->at->format('Y-m-d'));

            if ($from !== null && $date->isBefore($from)) {
                continue;
            }

            if ($to !== null && $date->isAfter($to)) {
                continue;
            }

            $records[] = $record->jsonSerialize();
        }

        return ['records' => $records];
    }
}
