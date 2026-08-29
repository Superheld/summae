import { DomainError } from '../../../domain-error.js';
import type { AccountNumber } from '../../../substrate/account-number.js';
import type { CalendarDate } from '../../../substrate/calendar-date.js';
import type { Money } from '../../../substrate/money.js';
import type { Uuid } from '../../../substrate/uuid.js';

export interface ProvisionMovement {
  kind: string;
  date: CalendarDate;
  amount: Money;
  entryId: Uuid | null;
  note: string | null;
}

/**
 * A provision, with its history (F-CORE-051).
 *
 * **Why this is an aggregate and not just a balance.** The balance of a provision account answers
 * almost nothing an auditor asks. What was set aside, for what, when, at what estimate — and then
 * what happened to it: was it *used* because the obligation materialised, *released* because the
 * reason ceased, or *re-measured* because the estimate moved? Those are three different events with
 * three different postings and three different meanings, and a netted balance shows none of them.
 * Same reason the asset register exists next to the asset accounts.
 *
 * **The movement list is the point, and it is append-only.** Every step names the entry it produced,
 * so the register and the journal can be walked against each other in both directions. Nothing here
 * is ever edited: a re-measurement is a new movement, not a corrected old one.
 *
 * **`settled` is not `released`.** A provision reaches `settled` when its carrying amount is zero,
 * however it got there. *How* it got there is in the movements, and the difference matters: a
 * release is income the business never had to pay, a use is an obligation that came true. A status
 * field that collapsed the two would be the same kind of lie as a netted balance.
 */
export class Provision {
  private movementList: ProvisionMovement[] = [];

  private carrying: Money;

  constructor(
    readonly id: Uuid,
    readonly reason: string,
    readonly account: AccountNumber,
    readonly expenseAccount: AccountNumber,
    readonly releaseAccount: AccountNumber,
    readonly recognizedOn: CalendarDate,
    readonly dueDate: CalendarDate | null,
    /** The undiscounted best estimate of the amount needed to settle (the *Erfüllungsbetrag*). */
    readonly settlementAmount: Money,
    /** What was actually recognised — the present value where discounting applied. */
    recognizedAmount: Money,
    /** The rate the discount used, as a percentage string, or null where nothing was discounted. */
    readonly discountRate: string | null,
  ) {
    this.carrying = recognizedAmount;
  }

  carryingAmount(): Money {
    return this.carrying;
  }

  isSettled(): boolean {
    return this.carrying.isZero();
  }

  status(): string {
    return this.isSettled() ? 'settled' : 'open';
  }

  movements(): ProvisionMovement[] {
    return this.movementList;
  }

  record(kind: string, date: CalendarDate, amount: Money, entryId: Uuid | null, note: string | null = null): void {
    this.movementList.push({ kind, date, amount, entryId, note });
  }

  /**
   * Move the carrying amount. Never below zero — a provision that has given back more than it held
   * would be income invented out of a sign error, and the operations that call this each cap their
   * own amount, so reaching here is a bug rather than a user mistake.
   */
  moveCarryingAmount(delta: Money): void {
    const next = this.carrying.add(delta);

    if (next.isNegative()) {
      throw new DomainError(
        'E_PROVISION_EXCEEDS_CARRYING',
        `provision ${this.id.value} carries ${this.carrying.amountAsString()} — ` +
          `${delta.abs().amountAsString()} cannot be taken from it`,
        { provisionId: this.id.value, carryingAmount: this.carrying.amountAsString() },
      );
    }

    this.carrying = next;
  }

  toJSON(): Record<string, unknown> {
    return {
      id: this.id.value,
      reason: this.reason,
      account: this.account.toString(),
      expenseAccount: this.expenseAccount.toString(),
      releaseAccount: this.releaseAccount.toString(),
      recognizedOn: this.recognizedOn.iso,
      dueDate: this.dueDate?.iso ?? null,
      settlementAmount: this.settlementAmount.toJSON(),
      carryingAmount: this.carrying.toJSON(),
      discountRate: this.discountRate,
      movements: this.movementList.map((movement) => ({
        kind: movement.kind,
        date: movement.date.iso,
        amount: movement.amount.toJSON(),
        entryId: movement.entryId?.value ?? null,
        note: movement.note,
      })),
    };
  }

  /**
   * Restore from persistence — the carrying amount and the movements are taken over directly, no
   * replay. Replaying them would recompute a history that is already a fact.
   */
  static restore(
    id: Uuid,
    reason: string,
    account: AccountNumber,
    expenseAccount: AccountNumber,
    releaseAccount: AccountNumber,
    recognizedOn: CalendarDate,
    dueDate: CalendarDate | null,
    settlementAmount: Money,
    carryingAmount: Money,
    discountRate: string | null,
    movements: ProvisionMovement[],
  ): Provision {
    const provision = new Provision(
      id,
      reason,
      account,
      expenseAccount,
      releaseAccount,
      recognizedOn,
      dueDate,
      settlementAmount,
      carryingAmount,
      discountRate,
    );
    provision.movementList = movements;
    return provision;
  }
}
