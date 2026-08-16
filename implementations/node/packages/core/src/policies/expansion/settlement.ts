import type { CalendarDate } from '../../substrate/calendar-date.js';
import type { Money } from '../../substrate/money.js';
import type { Uuid } from '../../substrate/uuid.js';
import type { SettlementCause, SettlementDifferenceKind } from '../../substrate/types.js';

/**
 * Single settlement of an open item. `money` is the settled
 * open-item amount INCLUDING the difference (api.md G2).
 *
 * `cause` says what closed the item, `difference` says by how much the amounts differed — the two
 * are independent: a payment can carry a discount, a cancellation never does.
 */
export class Settlement {
  constructor(
    readonly entryId: Uuid,
    readonly money: Money,
    readonly settledAt: CalendarDate,
    readonly differenceMoney: Money | null = null,
    readonly differenceKind: SettlementDifferenceKind | null = null,
    readonly cause: SettlementCause = 'payment',
  ) {}

  toJSON(): Record<string, unknown> {
    return {
      entryId: this.entryId.value,
      money: this.money.toJSON(),
      settledAt: this.settledAt.iso,
      cause: this.cause,
      difference:
        this.differenceMoney === null
          ? null
          : { money: this.differenceMoney.toJSON(), kind: this.differenceKind },
    };
  }
}
