import type { JournalRepository, OpenItemRepository, VoucherRepository } from '../../port.js';
import { CalendarDate } from '../../substrate/calendar-date.js';
import type { OpenItem } from '../../records/open-item.js';
import { parseOpenItemKind } from '../../substrate/types.js';

/**
 * OP-Liste: deterministisch, asOf-fähig (Zeitreise über settledAt). Sortierung:
 * voucherDate, dann sequenceNumber (determinismus.md §3).
 */
export class OpenItemsProjection {
  constructor(
    private readonly openItems: OpenItemRepository,
    private readonly vouchers: VoucherRepository,
    private readonly journal: JournalRepository,
  ) {}

  compute(params: Record<string, unknown>): { items: Array<Record<string, unknown>> } {
    const asOf = typeof params.asOf === 'string' ? CalendarDate.of(params.asOf) : null;
    const kind = parseOpenItemKind(params.kind);
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

  private serializeItem(item: OpenItem, asOf: CalendarDate | null): Record<string, unknown> {
    return {
      id: item.id.value,
      kind: item.kind,
      voucherNumber: this.vouchers.byId(item.voucherId)?.voucherNumber ?? null,
      money: item.money.toJSON(),
      remaining: item.remainingAt(asOf).toJSON(),
      status: item.statusAt(asOf),
    };
  }
}
