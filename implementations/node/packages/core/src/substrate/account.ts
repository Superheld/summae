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

  /**
   * The way back (F-CORE-033).
   *
   * The lock had no counterpart for a long time, and the question that decided this was whether the
   * irreversibility was *law*. It is not. What the German rules protect against unrecognisable
   * change are **postings**; for master data they ask that the change be *logged* — which the audit
   * trail does, in both directions. And that is the German answer to a question no other
   * jurisdiction answers differently either: no chart of accounts anywhere is a one-way door. So
   * nothing here is a jurisdiction's answer and nothing belongs in a pack. The sources are in the
   * knowledge base (`knowledge/10-fachwissen/17-gobd-compliance.md`), where a statute may be named.
   *
   * What a lock protects is the books, and it keeps doing that: an account cannot be posted to
   * while it is locked, and every posting ever made on it stays exactly where it is. Unlocking
   * changes nothing about the past — it only allows a future posting again, which is why a
   * mis-clicked lock does not have to be repaired by abandoning the account and opening a second
   * one under a new number.
   */
  unlock(): void {
    this.accountStatus = 'active';
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
