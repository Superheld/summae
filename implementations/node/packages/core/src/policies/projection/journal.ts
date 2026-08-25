import type { AccountRepository, AuditTrail, JournalRepository, VoucherRepository } from '../../port.js';
import { entryAuthors } from './entry-authors.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { JournalEntry } from '../../substrate/journal-entry.js';
import { integerOr, integerOrNull } from './parameters.js';

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
 * So: `datevExport`'s cost with `journalExport`'s completeness. Every line of every entry, account
 * numbers resolved, no hashes, no streams.
 *
 * **Paging counts entries, not lines**, which is the whole point — a page boundary that fell inside
 * a split entry would reproduce exactly the defect this projection exists to avoid. `count` is the
 * number of entries in the window *before* paging, so a page header can say "51–100 of 3,204"
 * without a second call that costs an export.
 *
 * Ordered by `sequenceNumber`, which is already the journal's total order: paging needs a stable
 * one, and inventing a tie-break where the ledger already has none would be a second answer to a
 * question that has one.
 *
 * Each entry carries `actor` (F-CORE-037): who recorded it. The entry itself has no author — the
 * fact lives in the audit trail and nowhere else, so a screen showing the journal could show
 * everything about a posting except who made it. See `entryAuthors`.
 */
export class JournalProjection {
  constructor(
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly vouchers: VoucherRepository,
    /** Where the author of a posting lives — required for the same reason as everywhere else. */
    private readonly audit: AuditTrail,
  ) {}

  compute(params: Record<string, unknown>): Record<string, unknown> {
    const fiscalYear = integerOr(params.fiscalYear, 0);
    const fromDate = typeof params.fromDate === 'string' ? CalendarDate.of(params.fromDate) : null;
    const toDate = typeof params.toDate === 'string' ? CalendarDate.of(params.toDate) : null;
    const offset = Math.max(0, integerOr(params.offset, 0));
    const limit = integerOrNull(params.limit);

    const matching = this.journal
      .forFiscalYear(fiscalYear)
      .filter((entry) => {
        if (fromDate !== null && entry.entryDate.isBefore(fromDate)) return false;
        if (toDate !== null && entry.entryDate.isAfter(toDate)) return false;
        return true;
      })
      .sort((a, b) => a.sequenceNumber - b.sequenceNumber);

    // A limit that is absent means "everything from the offset on" — a projection that invented a
    // default page size would silently truncate a caller that never asked for pages.
    const page = limit === null || limit < 0 ? matching.slice(offset) : matching.slice(offset, offset + limit);
    const authors = entryAuthors(
      this.audit,
      page.map((entry) => entry.id.value),
    );

    return {
      fiscalYear,
      count: matching.length,
      offset,
      limit,
      entries: page.map((entry) => this.serialize(entry, authors)),
    };
  }

  private serialize(entry: JournalEntry, authors: Map<string, string>): Record<string, unknown> {
    const voucher = this.vouchers.byId(entry.voucherId);

    return {
      sequenceNumber: entry.sequenceNumber,
      entryId: entry.id.value,
      actor: authors.get(entry.id.value) ?? null,
      status: entry.isFinalized() ? 'finalized' : 'entered',
      entryDate: entry.entryDate.iso,
      voucherNumber: voucher?.voucherNumber ?? null,
      voucherDate: voucher?.voucherDate.iso ?? null,
      text: entry.text(),
      reverses: entry.reverses?.value ?? null,
      reversedBy: entry.reversedBy()?.value ?? null,
      lines: entry.lines().map((line) => ({
        account: line.account.value,
        // The name is why this is not just a cheaper export: a journal view showing "6000" and
        // nothing else makes the reader look every number up somewhere for every row.
        accountName: this.accounts.byId(line.accountId)?.name ?? null,
        side: line.side,
        money: line.money.toJSON(),
        dimensions: line.dimensions.map((dimension) => dimension.toJSON()),
        taxTag: line.taxTag,
      })),
    };
  }
}
