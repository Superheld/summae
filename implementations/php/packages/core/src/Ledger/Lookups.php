<?php

declare(strict_types=1);

namespace Summae\Core\Ledger;

use Summae\Core\DomainError;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Exception\InvalidValue;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Substrate\Uuid;

/**
 * The two lookups every ledger service needs (Node counterpart: `lookups.ts`). Static, not a
 * shared base class: both are stateless apart from the repository they read, so passing it in is
 * cheaper than inheritance — and it keeps `post`, `settle`, `correct` and `reverse` reporting the
 * exact same error for the exact same bad input, which is what the fixtures pin.
 */
final class Lookups
{
    /** A posting date; anything unparsable is a period problem, not a format problem (api.md). */
    public static function parseEntryDate(mixed $entryDate): CalendarDate
    {
        if (!is_string($entryDate)) {
            throw new DomainError('E_PERIOD_UNKNOWN', 'entryDate missing');
        }

        try {
            return CalendarDate::of($entryDate);
        } catch (InvalidValue) {
            throw new DomainError('E_PERIOD_UNKNOWN', sprintf('Invalid posting date "%s"', $entryDate));
        }
    }

    /** An existing journal entry by id — a malformed id is "unknown", never a crash. */
    public static function requireEntry(JournalRepository $journal, mixed $entryId): JournalEntry
    {
        $entry = null;

        if (is_string($entryId) && $entryId !== '') {
            try {
                $entry = $journal->byId(Uuid::fromString($entryId));
            } catch (InvalidValue) {
                $entry = null;
            }
        }

        return $entry ?? throw new DomainError('E_ENTRY_UNKNOWN', sprintf(
            'Posting %s does not exist',
            is_string($entryId) ? $entryId : '?',
        ));
    }
}
