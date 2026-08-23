<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\JournalEntry;

/**
 * Resolves the reversal back-references of an entry into journal sequence numbers.
 *
 * The journal stores them as ids (`reverses` / `reversedBy`), which is right for the data format
 * and useless on a printed sheet: a reader looking at a cash book finds the counterpart by its
 * number, not by a UUID. So the views that a person reads publish numbers, and the export keeps
 * publishing ids.
 *
 * Why the views must publish it at all: a reversal that is not shown as one leaves a reader unable
 * to tell a corrected mistake from a removed transaction, and that is a formal defect in its own
 * right — no evidence of manipulation needed. This core reverses by general reversal (same side,
 * negated amount), which makes it worse without the marker: the movement shows a negative amount
 * on the original side and nothing explaining it.
 *
 * Mechanism, not jurisdiction: every set of books needs its corrections to be traceable.
 */
final readonly class ReversalIndex
{
    /** @param array<string, int> $sequenceById */
    private function __construct(private array $sequenceById)
    {
    }

    public static function of(JournalRepository $journal): self
    {
        $sequenceById = [];
        foreach ($journal->all() as $entry) {
            $sequenceById[$entry->id->value] = $entry->sequenceNumber;
        }

        return new self($sequenceById);
    }

    /**
     * The two fields a movement carries. Both null for an ordinary posting — present and null,
     * never absent, so a reader can tell "not a reversal" from "this view does not say".
     *
     * @return array{reversesEntry: int|null, reversedByEntry: int|null}
     */
    public function forEntry(JournalEntry $entry): array
    {
        $reversedBy = $entry->reversedBy();

        return [
            'reversesEntry' => $entry->reverses === null
                ? null
                : ($this->sequenceById[$entry->reverses->value] ?? null),
            'reversedByEntry' => $reversedBy === null
                ? null
                : ($this->sequenceById[$reversedBy->value] ?? null),
        ];
    }
}
