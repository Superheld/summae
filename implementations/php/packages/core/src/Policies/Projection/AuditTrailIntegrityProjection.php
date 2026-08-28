<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AuditTrail;
use Summae\Core\Records\AuditRecord;
use Summae\Core\Substrate\Timestamp;

/**
 * Walks the audit trail's hash chain and says whether it still holds (format 0.8).
 *
 * **Why this projection is the feature, and the two hash fields are only its data.** Until now the
 * trail was append-only *because no code path updates or deletes it* — a property of the procedure,
 * not of the data. An auditor could check the code, or trust the deployment, and nothing else;
 * docs/gobd-conformance.md §13 says plainly that a direct UPDATE against a summae_* table leaves no
 * trace. It now leaves one, and this is where it becomes visible. Storing hashes and never offering
 * a way to check them would be decoration.
 *
 * **Four states per record, and keeping them apart is the whole difficulty:**
 *
 * - **chained** — carries both hashes, its own recomputes, and its link matches its predecessor.
 * - **unchained** — written before format 0.8, so it has no hash. Reported as its own number and
 *   never as a break: a library that cried tampering over its own upgrade would be useless. They
 *   can only sit at the *front*; an unchained record appearing after a chained one is an insertion
 *   and is reported as a break.
 * - **redacted** — erased under a privacy right (F-CORE-040). The shell keeps both hashes, so the
 *   link still resolves; its content cannot be recomputed, because there is none. Counted, not
 *   verified, and the count is published so nobody reads the difference as a silent pass.
 * - **broken** — everything else, with the reason named.
 *
 * **What it cannot do**, stated because a guarantee's edge is part of it:
 *
 * - Records removed from the **end** leave nothing behind to notice. Every chain has this hole. The
 *   answer is the published `head`: keep it somewhere summae cannot reach and compare.
 * - Two concurrent appends can read the same head and both link to it. That is a *fork*, and it is
 *   reported as a break — truthfully, because from the data alone a fork and a removal look the
 *   same. Serialising appends is the embedding's to arrange, like every other write.
 * - It proves the trail's own integrity, not the books'. A chain over the postings would need
 *   `previousEntryHash`, which the data format reserves and forbids writers to populate in v0.x
 *   (SPEC-022) — a chain every conforming reader is told to ignore would be evidence for nobody.
 */
final readonly class AuditTrailIntegrityProjection
{
    public function __construct(private AuditTrail $audit)
    {
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $records = $this->audit->all();
        /** @var list<array<string, mixed>> $breaks */
        $breaks = [];
        $chained = 0;
        $unchained = 0;
        $redacted = 0;
        $previousHash = null;
        $seenChained = false;

        foreach ($records as $record) {
            if ($record->recordHash === null) {
                ++$unchained;
                if ($seenChained) {
                    $breaks[] = self::breakAt(
                        $record,
                        'unchainedAfterChained',
                        'a record without a hash follows chained ones, which is where an insertion shows',
                    );
                }
                continue;
            }

            $seenChained = true;
            if ($record->isRedacted()) {
                ++$redacted;
            } else {
                ++$chained;
                if (AuditRecord::hashOf($record) !== $record->recordHash) {
                    $breaks[] = self::breakAt(
                        $record,
                        'contentMismatch',
                        'the record no longer hashes to the value it carries',
                    );
                }
            }

            if ($record->previousRecordHash !== $previousHash) {
                $breaks[] = self::breakAt(
                    $record,
                    'linkMismatch',
                    'the link does not name the preceding record — one was changed, removed or inserted',
                );
            }
            $previousHash = $record->recordHash;
        }

        return [
            'records' => count($records),
            'chained' => $chained,
            'unchained' => $unchained,
            'redacted' => $redacted,
            // The tip of the chain. Keep it outside summae and compare later: it is the only thing
            // that notices records dropped from the end.
            'head' => $previousHash,
            'intact' => $breaks === [],
            'breaks' => $breaks,
        ];
    }

    /** @return array<string, mixed> */
    private static function breakAt(AuditRecord $record, string $reason, string $detail): array
    {
        return [
            'recordId' => $record->id->value,
            'at' => Timestamp::canonical($record->at),
            'reason' => $reason,
            'detail' => $detail,
        ];
    }
}
