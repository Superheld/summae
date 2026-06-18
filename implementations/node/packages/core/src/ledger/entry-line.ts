import type { AccountNumber } from '../shared/account-number.js';
import type { DimensionValue } from '../shared/dimension-value.js';
import type { Money } from '../shared/money.js';
import type { Uuid } from '../shared/uuid.js';
import type { Side } from './types.js';

/**
 * Buchungsposition — Value Object innerhalb der Buchung (keine eigene Identität;
 * Referenz = Buchungs-ID + Positionsindex).
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
