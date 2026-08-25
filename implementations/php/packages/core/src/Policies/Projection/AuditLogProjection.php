<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AuditTrail;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Substrate\CalendarDate;

/**
 * Change history as a projection (F-CORE-014, F-CORE-036; GoBD Rz. 107 ff.).
 *
 * Order = recording order of the audit trail, which is already its total order (the sequence in the
 * store, insertion order in memory). Paging needs a stable one, and inventing a tie-break where the
 * trail already has none would be a second answer to a question that has one.
 *
 * **Filters, because the auditor's question is about one thing.** Until 0.13.0 the only parameters
 * were `from`/`to`, so "who touched this account", "what happened to this posting" and "what did
 * this user do" were not askable: the caller had to fetch the whole trail and filter outside. That
 * is the wrong place twice over — it moves the fastest-growing table in the system across a
 * boundary to discard most of it, and it makes progressive and retrograde traceability a property
 * of the embedding rather than of the books.
 *
 * All filters combine with AND, and an absent one filters nothing. `count` is the number of records
 * matching the filters *before* paging, so a page header can say "51–100 of 3,204" without a second
 * call — the same contract `journal` publishes, and deliberately the same words.
 */
final readonly class AuditLogProjection
{
    public function __construct(
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $params from?, to? (ISO dates), objectType?, objectId?, actor?,
     *                                    action?, offset?, limit?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $from = is_string($params['from'] ?? null) ? CalendarDate::of($params['from']) : null;
        $to = is_string($params['to'] ?? null) ? CalendarDate::of($params['to']) : null;
        $objectType = is_string($params['objectType'] ?? null) ? $params['objectType'] : null;
        $objectId = is_string($params['objectId'] ?? null) ? $params['objectId'] : null;
        $actor = is_string($params['actor'] ?? null) ? $params['actor'] : null;
        $action = is_string($params['action'] ?? null) ? $params['action'] : null;
        $offset = max(0, Parameters::integerOr($params['offset'] ?? null, 0));
        $limit = Parameters::integerOrNull($params['limit'] ?? null);

        $matching = [];

        foreach ($this->audit->all() as $record) {
            if (!$this->matches($record, $from, $to, $objectType, $objectId, $actor, $action)) {
                continue;
            }

            $matching[] = $record->jsonSerialize();
        }

        // A limit that is absent means "everything from the offset on" — a projection that invented
        // a default page size would silently truncate a caller that never asked for pages.
        $page = $limit === null || $limit < 0
            ? array_slice($matching, $offset)
            : array_slice($matching, $offset, $limit);

        return [
            'count' => count($matching),
            'offset' => $offset,
            'limit' => $limit,
            'records' => $page,
        ];
    }

    private function matches(
        AuditRecord $record,
        ?CalendarDate $from,
        ?CalendarDate $to,
        ?string $objectType,
        ?string $objectId,
        ?string $actor,
        ?string $action,
    ): bool {
        $date = CalendarDate::of($record->at->format('Y-m-d'));

        if ($from !== null && $date->isBefore($from)) {
            return false;
        }

        if ($to !== null && $date->isAfter($to)) {
            return false;
        }

        if ($objectType !== null && $record->objectType !== $objectType) {
            return false;
        }

        if ($objectId !== null && $record->objectId->value !== $objectId) {
            return false;
        }

        if ($actor !== null && $record->actor !== $actor) {
            return false;
        }

        return !($action !== null && $record->action !== $action);
    }
}
