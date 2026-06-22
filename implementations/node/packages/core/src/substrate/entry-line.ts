import type { AccountNumber } from './account-number.js';
import type { DimensionValue } from './dimension-value.js';
import type { Money } from './money.js';
import type { Uuid } from './uuid.js';
import type { Side } from './types.js';

/**
 * Posting line — value object within the posting (no own identity;
 * reference = posting ID + line index).
 */
export class EntryLine {
  constructor(
    readonly accountId: Uuid,
    readonly account: AccountNumber,
    readonly side: Side,
    readonly money: Money,
    readonly dimensions: DimensionValue[] = [],
    readonly taxTag: Record<string, unknown> | null = null,
  ) {}

  negated(): EntryLine {
    return new EntryLine(
      this.accountId,
      this.account,
      this.side,
      this.money.negate(),
      this.dimensions,
      this.taxTag,
    );
  }

  toJSON(): Record<string, unknown> {
    return {
      accountId: this.accountId.value,
      account: this.account.value,
      side: this.side,
      money: this.money.toJSON(),
      dimensions: this.dimensions.map((dimension) => dimension.toJSON()),
      taxTag: this.taxTag,
    };
  }
}
