<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AccountRepository;
use Summae\Core\Port\AuditTrail;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\DimensionValue;
use Summae\Core\Substrate\EntryLine;
use Summae\Core\Substrate\JournalEntry;

/**
 * The journal as a screen reads it (F-CORE-031).
 *
 * The plainest view a bookkeeping application has, and until now it had two bad ways to fill it.
 * `journalExport` is lossless and builds five streams with a SHA-256 each, has no window and no
 * paging — an archive format answering a list view's question, paid for on every page load.
 * `datevExport` has the window and the weight but is DATEV-shaped, and therefore **lossy for split
 * entries**: `6000 75.00 + 1500 14.25 against 1200 89.25` collapses into one row and the input-tax
 * line disappears. Filling a journal view from it would quietly hide every tax line in the books.
 *
 * So: datevExport's cost with journalExport's completeness. Every line of every entry, account
 * numbers resolved, no hashes, no streams.
 *
 * **Paging counts entries, not lines**, which is the whole point — a page boundary that fell inside
 * a split entry would reproduce exactly the defect this projection exists to avoid. `count` is the
 * number of entries in the window *before* paging, so a page header can say "51–100 of 3,204"
 * without a second call that costs an export.
 *
 * Ordered by sequenceNumber, which is already the journal's total order: paging needs a stable one,
 * and inventing a tie-break where the ledger already has none would be a second answer to a
 * question that has one.
 *
 * Each entry carries `actor` (F-CORE-037): who recorded it. The entry itself has no author — the
 * fact lives in the audit trail and nowhere else, so a screen showing the journal could show
 * everything about a posting except who made it. See `EntryAuthors`.
 */
final readonly class JournalProjection
{
    public function __construct(
        private AccountRepository $accounts,
        private JournalRepository $journal,
        private VoucherRepository $vouchers,
        /** Where the author of a posting lives — required for the same reason as everywhere else. */
        private AuditTrail $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $params fiscalYear, fromDate?, toDate?, offset?, limit?
     *
     * @return array<string, mixed>
     */
    public function compute(array $params): array
    {
        $fiscalYear = Parameters::integerOr($params['fiscalYear'] ?? null, 0);
        $fromDate = is_string($params['fromDate'] ?? null) ? CalendarDate::of($params['fromDate']) : null;
        $toDate = is_string($params['toDate'] ?? null) ? CalendarDate::of($params['toDate']) : null;
        $offset = max(0, Parameters::integerOr($params['offset'] ?? null, 0));
        $limit = Parameters::integerOrNull($params['limit'] ?? null);

        $matching = array_values(array_filter(
            $this->journal->forFiscalYear($fiscalYear),
            static function (JournalEntry $entry) use ($fromDate, $toDate): bool {
                if ($fromDate !== null && $entry->entryDate->isBefore($fromDate)) {
                    return false;
                }

                return !($toDate !== null && $entry->entryDate->isAfter($toDate));
            },
        ));
        usort($matching, static fn (JournalEntry $a, JournalEntry $b): int => $a->sequenceNumber <=> $b->sequenceNumber);

        // A limit that is absent means "everything from the offset on" — a projection that invented
        // a default page size would silently truncate a caller that never asked for pages.
        $page = $limit === null || $limit < 0
            ? array_slice($matching, $offset)
            : array_slice($matching, $offset, $limit);

        $authors = EntryAuthors::forEntries(
            $this->audit,
            array_map(static fn (JournalEntry $entry): string => $entry->id->value, $page),
        );

        return [
            'fiscalYear' => $fiscalYear,
            'count' => count($matching),
            'offset' => $offset,
            'limit' => $limit,
            'entries' => array_map(
                fn (JournalEntry $entry): array => $this->serialize($entry, $authors),
                $page,
            ),
        ];
    }

    /**
     * @param array<string, string> $authors
     *
     * @return array<string, mixed>
     */
    private function serialize(JournalEntry $entry, array $authors): array
    {
        $voucher = $this->vouchers->byId($entry->voucherId);

        return [
            'sequenceNumber' => $entry->sequenceNumber,
            'entryId' => $entry->id->value,
            'actor' => $authors[$entry->id->value] ?? null,
            'status' => $entry->isFinalized() ? 'finalized' : 'entered',
            'entryDate' => $entry->entryDate->iso,
            'voucherNumber' => $voucher?->voucherNumber,
            'voucherDate' => $voucher?->voucherDate->iso,
            'text' => $entry->text(),
            'reverses' => $entry->reverses?->value,
            'reversedBy' => $entry->reversedBy()?->value,
            'lines' => array_map(fn (EntryLine $line): array => [
                'account' => $line->account->value,
                // The name is why this is not just a cheaper export: a journal view showing "6000"
                // and nothing else makes the reader look every number up somewhere for every row.
                'accountName' => $this->accounts->byId($line->accountId)?->name,
                'side' => $line->side->value,
                'money' => $line->money->jsonSerialize(),
                'dimensions' => array_map(
                    static fn (DimensionValue $dimension): array => $dimension->jsonSerialize(),
                    $line->dimensions,
                ),
                'taxTag' => $line->taxTag,
            ], $entry->lines()),
        ];
    }
}
