import { DomainError, rejectedValue } from '../../domain-error.js';
import type { JournalRepository, OpenItemRepository, VoucherRepository } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { OpenItem } from '../../records/open-item.js';
import { parseOpenItemKind } from '../../substrate/types.js';

/**
 * Open items list: deterministic, asOf-capable (time travel via settledAt). Sorting:
 * voucherDate, then sequenceNumber (determinismus.md §3).
 */
export class OpenItemsProjection {
  constructor(
    private readonly openItems: OpenItemRepository,
    private readonly vouchers: VoucherRepository,
    private readonly journal: JournalRepository,
  ) {}

  compute(params: Record<string, unknown>): { items: Array<Record<string, unknown>> } {
    const asOf = typeof params.asOf === 'string' ? CalendarDate.of(params.asOf) : null;
    // An unparseable kind used to fall back to "no filter", so a mistyped filter widened the
    // result instead of narrowing it: a payment run asking for payables got receivables mixed
    // in and would have paid them out. Absent still means "no filter" — a wrong value must not.
    let kind = null;
    if (params.kind !== undefined && params.kind !== null) {
      kind = parseOpenItemKind(params.kind);
      if (kind === null) {
        throw new DomainError('E_INPUT_INVALID', 'openItems: "kind" must be "receivable" or "payable"', {
          kind: rejectedValue(params.kind),
        });
      }
    }
    const partnerId = typeof params.partnerId === 'string' ? params.partnerId : null;

    const open = this.openItems.all().filter((item) => {
      if (kind !== null && item.kind !== kind) return false;
      if (partnerId !== null && (item.partnerId?.value ?? null) !== partnerId) return false;
      if (asOf !== null && item.openedAt.isAfter(asOf)) return false;
      if (item.remainingAt(asOf).isZero()) return false;
      return true;
    });

    open.sort((a, b) => {
      const byDate = this.voucherDate(a).compareTo(this.voucherDate(b));
      return byDate !== 0 ? byDate : this.sequenceNumber(a) - this.sequenceNumber(b);
    });

    return { items: open.map((item) => this.serializeItem(item, asOf)) };
  }

  private voucherDate(item: OpenItem): CalendarDate {
    return this.vouchers.byId(item.voucherId)?.voucherDate ?? item.openedAt;
  }

  private sequenceNumber(item: OpenItem): number {
    return this.journal.byId(item.originEntryId)?.sequenceNumber ?? 0;
  }

  /**
   * Both `partnerId` and `due` were known here and neither was published.
   *
   * The partner was accepted as a FILTER and dropped from the result, so a list could be narrowed to
   * one debtor and then could not say which. The due date sits on the voucher — which this method
   * already loads, for the voucher number — and without it the list cannot be aged at all, which is
   * what a maturity disclosure is built from.
   *
   * Null where the voucher names no date: present and null, so "no date agreed" stays
   * distinguishable from "this view does not say".
   */
  private serializeItem(item: OpenItem, asOf: CalendarDate | null): Record<string, unknown> {
    const voucher = this.vouchers.byId(item.voucherId);
    return {
      id: item.id.value,
      kind: item.kind,
      voucherNumber: voucher?.voucherNumber ?? null,
      partnerId: item.partnerId?.value ?? null,
      due: voucher?.due?.iso ?? null,
      money: item.money.toJSON(),
      remaining: item.remainingAt(asOf).toJSON(),
      status: item.statusAt(asOf),
    };
  }
}
