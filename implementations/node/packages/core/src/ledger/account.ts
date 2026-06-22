import { AccountNumber } from '../substrate/account-number.js';
import type { Uuid } from '../substrate/uuid.js';
import type { AccountStatus, AccountType } from './types.js';

/**
 * Konto (ledger-modell.md Aggregat 2). Kein Saldo im Aggregat — Salden sind
 * Projektionen des Journals, immer.
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
