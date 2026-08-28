<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Records\AuditRecord;
use Summae\Core\Substrate\Uuid;

/**
 * The audit trail is part of the format (datenformat.md v0.3, review G3):
 * the original content stays determinable over the retention period
 * — append-only, fully carried over on migration.
 */
interface AuditTrail
{
    public function append(AuditRecord $record): void;

    /** @return list<AuditRecord> in capture order */
    public function all(): array;

    /**
     * The part of the trail a question is about (SPEC-018).
     *
     * `all()` is honest and, past a certain size, the wrong tool: a trail is the fastest-growing
     * table in the system, and answering "what happened to this posting" by materialising ten years
     * of history to discard almost all of it makes the cost of a screen scale with the age of the
     * books. So the criteria travel to the store, which is the only place that can decline to read
     * a row.
     *
     * Criteria combine with AND and an absent one filters nothing:
     * `objectType`, `objectId`, `objectIds` (a set — what a page of postings needs), `actor`,
     * `action`, `from`/`to` (inclusive calendar dates on the recording moment), `offset`, `limit`
     * (absent or negative = everything from the offset on, exactly as on `journal`).
     *
     * `count` is the number of matches **before** paging, so a page header needs no second call.
     * Order stays capture order, which is the trail's own total order.
     *
     * @param array<string, mixed> $criteria
     *
     * @return array{records: list<AuditRecord>, count: int}
     */
    public function find(array $criteria): array;

    /**
     * Erase the trail's records about one object, and answer how many went (F-CORE-040).
     *
     * The one hole in "the trail is append-only because no code path deletes from it", and it is
     * opened deliberately and narrowly. Where a record has to be removable at all — the master data
     * a jurisdiction's privacy rules reach, never the books, which every retention rule protects —
     * removing the record alone removes nothing: createPartner's own audit entry still holds the
     * name and the address in `changes`, so the data would only move to where nobody looks.
     *
     * It is reachable from exactly one operation (`erasePartner`), which refuses while any voucher
     * or open item names the partner. Nothing in the bookkeeping path can call it: the journal, the
     * entries and the trail's records about them stay untouchable, which is what GoBD asks for.
     */
    public function eraseFor(string $objectType, Uuid $objectId): int;
}
