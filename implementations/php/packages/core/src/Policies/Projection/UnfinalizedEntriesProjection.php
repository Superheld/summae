<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Clock;
use Summae\Core\Substrate\Timestamp;

/**
 * Postings still in status `entered` at a reference date (F-CORE-027).
 *
 * GoBD asks for finalization "at the latest with the VAT return" (Rz. 47 ff.) — a deadline
 * nothing in the data enforces, because it is a *German* rule and the substrate is
 * jurisdiction-free. What the substrate can do is make the deadline observable: the journal
 * already carries `entryDate` and `recordedAt`, so how long a posting has been sitting open
 * is a fold over the journal, not new state.
 *
 * The age is measured from `entryDate` (the bookkeeping date, zoneless) against `asOf`, not
 * from `recordedAt` — a posting recorded late for an old date is exactly the case the
 * deadline is about, and measuring from the recording moment would hide it.
 *
 * The projection reports; it never blocks. Which age is too old, and what happens then, is
 * the embedding application's workflow — the library supplies the number.
 *
 * Each row carries `actor` (F-CORE-037): who recorded the posting. This is the projection a
 * separation-of-duties check reads — "nobody may finalize a batch containing their own postings" —
 * and without the author it was the one question it could not ask here, so an application read the
 * whole audit trail per finalization and rebuilt the mapping itself. See `EntryAuthors`.
 */
final readonly class UnfinalizedEntriesProjection
{
    public function __construct(
        private JournalRepository $journal,
        private Clock $clock,
        /**
         * Where the author of a posting lives — the entry itself does not carry one.
         *
         * Required, not optional-with-a-default. An optional dependency is how three services in
         * the database factory lost their audit writer and nobody noticed for a release: nothing
         * fails to compile, nothing warns, the output is merely poorer in one setup. A caller that
         * cannot supply the trail should fail to construct.
         */
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $asOf = is_string($params['asOf'] ?? null)
            ? CalendarDate::of($params['asOf'])
            : CalendarDate::of($this->clock->now()->format('Y-m-d'));
        $olderThanDays = is_int($params['olderThanDays'] ?? null) ? $params['olderThanDays'] : 0;
        $fiscalYear = is_int($params['fiscalYear'] ?? null) ? $params['fiscalYear'] : null;

        $source = $fiscalYear === null
            ? $this->journal->all()
            : $this->journal->forFiscalYear($fiscalYear);

        $authors = EntryAuthors::from($this->audit);

        $entries = [];
        $oldestAgeInDays = 0;

        foreach ($source as $entry) {
            if ($entry->isFinalized()) {
                continue;
            }

            $ageInDays = $asOf->daysSince($entry->entryDate);
            if ($ageInDays < $olderThanDays) {
                continue;
            }

            $entries[] = [
                'entryId' => $entry->id->value,
                'sequenceNumber' => $entry->sequenceNumber,
                'entryDate' => $entry->entryDate->iso,
                'recordedAt' => Timestamp::canonical($entry->recordedAt),
                'fiscalYear' => $entry->periodRef->fiscalYear,
                'period' => $entry->periodRef->period,
                'ageInDays' => $ageInDays,
                'text' => $entry->text(),
                'actor' => $authors[$entry->id->value] ?? null,
            ];

            if ($ageInDays > $oldestAgeInDays) {
                $oldestAgeInDays = $ageInDays;
            }
        }

        // Journal order (sequenceNumber) is the order the entries arrive in; keeping it makes
        // the result deterministic without a second sort key.
        return [
            'asOf' => $asOf->iso,
            'olderThanDays' => $olderThanDays,
            'count' => count($entries),
            'oldestAgeInDays' => $oldestAgeInDays,
            'entries' => $entries,
        ];
    }
}
