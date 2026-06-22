import { DomainError } from '../domain-error.js';
import type { CalendarDate } from '../substrate/calendar-date.js';
import type { Money } from '../substrate/money.js';
import type { Uuid } from '../substrate/uuid.js';
import type { Settlement } from '../policies/expansion/settlement.js';
import type { OpenItemKind, OpenItemStatus } from '../substrate/types.js';

/**
 * Open item (ledger-modell.md aggregate 5): arises from a posting to
 * an AR/AP account, references the origin posting + line. Invariant:
 * Σ settlements ≤ amount; partial settlements allowed.
 */
export class OpenItem {
  private readonly settlementList: Settlement[] = [];

  constructor(
    readonly id: Uuid,
    readonly kind: OpenItemKind,
    readonly originEntryId: Uuid,
    readonly originLineIndex: number,
    readonly money: Money,
    readonly voucherId: Uuid,
    readonly openedAt: CalendarDate,
    readonly partnerId: Uuid | null = null,
  ) {}

  /**
   * Restore from persistence: set already-validated settlements directly
   * (no re-check) — counterpart to PHP's `OpenItem::restore`.
   */
  static restore(
    id: Uuid,
    kind: OpenItemKind,
    originEntryId: Uuid,
    originLineIndex: number,
    money: Money,
    voucherId: Uuid,
    openedAt: CalendarDate,
    partnerId: Uuid | null,
    settlements: Settlement[],
  ): OpenItem {
    const item = new OpenItem(id, kind, originEntryId, originLineIndex, money, voucherId, openedAt, partnerId);
    item.settlementList.push(...settlements);
    return item;
  }

  settlements(): Settlement[] {
    return this.settlementList;
  }

  remaining(): Money {
    return this.remainingAt(null);
  }

  /** Remaining amount as of a cutoff date (null = today/all). */
  remainingAt(asOf: CalendarDate | null): Money {
    let remaining = this.money;
    for (const settlement of this.settlementList) {
      if (asOf !== null && settlement.settledAt.isAfter(asOf)) continue;
      remaining = remaining.subtract(settlement.money);
    }
    return remaining;
  }

  status(): OpenItemStatus {
    return this.statusAt(null);
  }

  statusAt(asOf: CalendarDate | null): OpenItemStatus {
    const remaining = this.remainingAt(asOf);
    if (remaining.isZero()) return 'settled';
    return remaining.equals(this.money) ? 'open' : 'partially_settled';
  }

  settle(settlement: Settlement): void {
    if (settlement.money.compareTo(this.remaining()) > 0) {
      throw new DomainError(
        'E_SETTLEMENT_EXCEEDS_ITEM',
        `Allocation ${settlement.money.amountAsString()} exceeds remaining amount ${this.remaining().amountAsString()} of item ${this.id.value}`,
        {
          openItemId: this.id.value,
          remaining: this.remaining().amountAsString(),
          allocated: settlement.money.amountAsString(),
        },
      );
    }
    this.settlementList.push(settlement);
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      kind: this.kind,
      originEntryId: this.originEntryId.value,
      originLineIndex: this.originLineIndex,
      money: this.money.toJSON(),
      partnerId: this.partnerId?.value ?? null,
      remaining: this.remaining().toJSON(),
      status: this.status(),
      settlements: this.settlementList.map((settlement) => settlement.toJSON()),
    };
  }
}
