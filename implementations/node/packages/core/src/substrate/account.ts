import { AccountNumber } from './account-number.js';
import type { Uuid } from './uuid.js';
import type { AccountStatus, AccountType } from './types.js';

/**
 * Account (ledger-modell.md aggregate 2). No balance in the aggregate — balances are
 * projections of the journal, always.
 */
export class Account {
  private accountStatus: AccountStatus;

  constructor(
    readonly id: Uuid,
    readonly number: AccountNumber,
    readonly name: string,
    readonly type: AccountType,
    readonly subtype: string | null,
    status: AccountStatus = 'active',
  ) {
    this.accountStatus = status;
  }

  status(): AccountStatus {
    return this.accountStatus;
  }

  isLocked(): boolean {
    return this.accountStatus === 'locked';
  }

  lock(): void {
    this.accountStatus = 'locked';
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      number: this.number.value,
      name: this.name,
      type: this.type,
      subtype: this.subtype,
      status: this.accountStatus,
    };
  }
}
