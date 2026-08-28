import type { JournalRepository, PartnerRepository, VoucherRepository } from '../../port.js';
import type { Currency } from '../../substrate/currency.js';
import type { JournalEntry } from '../../substrate/journal-entry.js';
import { Money } from '../../substrate/money.js';
import type { Voucher } from '../../records/voucher.js';

/**
 * Documents that look like they were entered twice (F-CORE-044).
 *
 * **The defect this exists for.** `voucherNumber` is a free string with no uniqueness of any kind,
 * and `postVoucher` even substitutes `''` when none is supplied. The same incoming invoice booked
 * twice therefore produces two vouchers, two balanced entries, two open items and two input-tax
 * deductions, and every invariant the library has is satisfied: the entries balance, they carry a
 * voucher, they sit in an open period, the trial balance adds up. Nothing in summae notices, and
 * nothing in the reports looks wrong — the second deduction is simply money claimed twice.
 *
 * **Why a projection and not a refusal.** Duplicate voucher numbers are legitimate across sources:
 * two suppliers may both send their invoice number 1, and a tenant that uses the supplier's number
 * as its own voucher number will meet that in its first year. So the grouping is by *document
 * identity* — the issuer plus the number — and even then the answer is a report, not an error. This
 * follows the line `vatReturn.gapWarnings` draws: name it at the figures, let the application
 * decide. A hard uniqueness rule would be wrong in a way that cannot be worked around, and the one
 * thing worse than a missing check is one that blocks correct bookkeeping.
 *
 * **Deliberately no parameters, and that is a decision rather than an omission.** A date window is
 * the obvious one to offer and the wrong one to have: an invoice entered in December and again in
 * January is exactly the case this projection exists for, and any window on the voucher date hides
 * it at the boundary. `accounts` takes no parameters for the same kind of reason.
 *
 * **Three exclusions, each because including it would produce noise rather than findings:**
 * a voucher with an empty `voucherNumber` (there is nothing to compare — and it is not this
 * projection's business to complain about that); a voucher flagged `recurring` (a Dauerbeleg
 * repeating its number is what the flag means); and, per voucher, entries that are a reversal or
 * have been reversed — `postedTotal` counts only what still moves the books, so a duplicate that
 * has already been corrected reads `0.00` instead of dropping out silently. `stillPosted` counts
 * the vouchers in a group that have a non-zero total, which is the number an application acts on.
 *
 * Substrate, not pack: entering one document twice is wrong in every jurisdiction, and the
 * projection cites no statute.
 */
export class DuplicateVouchersProjection {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly vouchers: VoucherRepository,
    private readonly journal: JournalRepository,
    /** Only for the name — a duplicate list that says `partnerId` and not "Mueller GmbH" is read by nobody. */
    private readonly partners: PartnerRepository,
  ) {}

  compute(): Record<string, unknown> {
    const entriesByVoucher = this.entriesByVoucher();

    const groups = new Map<string, Voucher[]>();
    for (const voucher of this.vouchers.all()) {
      if (voucher.voucherNumber === '' || voucher.recurring) continue;
      const key = DuplicateVouchersProjection.groupKey(voucher);
      const bucket = groups.get(key);
      if (bucket === undefined) groups.set(key, [voucher]);
      else bucket.push(voucher);
    }

    const keys = [...groups.keys()].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));

    const duplicates: Array<Record<string, unknown>> = [];
    let voucherCount = 0;
    for (const key of keys) {
      const members = groups.get(key) ?? [];
      if (members.length < 2) continue;

      members.sort((a, b) => {
        const byDate = a.voucherDate.iso < b.voucherDate.iso ? -1 : a.voucherDate.iso > b.voucherDate.iso ? 1 : 0;
        if (byDate !== 0) return byDate;
        return a.id.value < b.id.value ? -1 : a.id.value > b.id.value ? 1 : 0;
      });

      const rows: Array<Record<string, unknown>> = [];
      let stillPosted = 0;
      for (const voucher of members) {
        const entries = entriesByVoucher.get(voucher.id.value) ?? [];
        const total = this.postedTotal(entries);
        if (!total.isZero()) stillPosted += 1;
        rows.push(this.serialize(voucher, entries, total));
      }

      const first = members[0]!;
      duplicates.push({
        voucherNumber: first.voucherNumber,
        partnerId: first.partnerId?.value ?? null,
        partnerName: first.partnerId === null ? null : (this.partners.byId(first.partnerId)?.name() ?? null),
        issuer: first.issuer,
        count: members.length,
        stillPosted,
        vouchers: rows,
      });
      voucherCount += members.length;
    }

    return { count: duplicates.length, voucherCount, duplicates };
  }

  /**
   * Issuer identity first, so two documents from the same source sort together, then the number,
   * separated by a byte that cannot occur in either — without it, issuer "AB" + number "C" and
   * issuer "A" + number "BC" would be one group. `partnerId` wins over `issuer` when both are
   * present: the master record is the identity, the string is what somebody typed. Vouchers with
   * neither group among themselves — two vouchers "RE-4711" from nowhere in particular are still
   * worth a second look.
   */
  private static groupKey(voucher: Voucher): string {
    const issuer = voucher.partnerId?.value ?? voucher.issuer ?? '';
    return `${issuer}\u001f${voucher.voucherNumber}`;
  }

  private entriesByVoucher(): Map<string, JournalEntry[]> {
    const byVoucher = new Map<string, JournalEntry[]>();
    for (const entry of this.journal.all()) {
      const bucket = byVoucher.get(entry.voucherId.value);
      if (bucket === undefined) byVoucher.set(entry.voucherId.value, [entry]);
      else bucket.push(entry);
    }
    return byVoucher;
  }

  /**
   * What the voucher still moves: the debit side of its entries, skipping any entry that is a
   * reversal or has been reversed. A duplicate that was already corrected therefore reads `0.00`
   * and stays visible with its history, instead of disappearing from a list somebody is using to
   * decide whether it was corrected.
   */
  private postedTotal(entries: JournalEntry[]): Money {
    let total = Money.zero(this.baseCurrency);
    for (const entry of entries) {
      if (entry.reverses !== null || entry.reversedBy() !== null) continue;
      for (const line of entry.lines()) {
        if (line.side === 'debit') total = total.add(line.money);
      }
    }
    return total;
  }

  private serialize(voucher: Voucher, entries: JournalEntry[], total: Money): Record<string, unknown> {
    const ordered = [...entries].sort((a, b) => {
      const byYear = a.periodRef.fiscalYear - b.periodRef.fiscalYear;
      return byYear !== 0 ? byYear : a.sequenceNumber - b.sequenceNumber;
    });

    return {
      voucherId: voucher.id.value,
      voucherDate: voucher.voucherDate.iso,
      postedTotal: total.toJSON(),
      entries: ordered.map((entry) => ({
        entryId: entry.id.value,
        sequenceNumber: entry.sequenceNumber,
        fiscalYear: entry.periodRef.fiscalYear,
        entryDate: entry.entryDate.iso,
        status: entry.isFinalized() ? 'finalized' : 'entered',
        reverses: entry.reverses?.value ?? null,
        reversedBy: entry.reversedBy()?.value ?? null,
      })),
    };
  }
}
