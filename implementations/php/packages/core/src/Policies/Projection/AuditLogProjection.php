<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AuditTrail;
use Summae\Core\Records\AuditRecord;

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
        // The criteria travel to the store, which is the only place that can decline to read a row
        // (SPEC-018). An in-memory trail filters the same criteria with the same rule; a database
        // one pushes them into SQL. Same answer, different amount of reading.
        $result = $this->audit->find([
            'from' => is_string($params['from'] ?? null) ? $params['from'] : null,
            'to' => is_string($params['to'] ?? null) ? $params['to'] : null,
            'objectType' => is_string($params['objectType'] ?? null) ? $params['objectType'] : null,
            'objectId' => is_string($params['objectId'] ?? null) ? $params['objectId'] : null,
            'actor' => is_string($params['actor'] ?? null) ? $params['actor'] : null,
            'action' => is_string($params['action'] ?? null) ? $params['action'] : null,
            'offset' => max(0, Parameters::integerOr($params['offset'] ?? null, 0)),
            'limit' => Parameters::integerOrNull($params['limit'] ?? null),
        ]);

        return [
            'count' => $result['count'],
            'offset' => max(0, Parameters::integerOr($params['offset'] ?? null, 0)),
            'limit' => Parameters::integerOrNull($params['limit'] ?? null),
            'records' => array_map(
                static fn (AuditRecord $record): array => $record->jsonSerialize(),
                $result['records'],
            ),
        ];
    }
}
