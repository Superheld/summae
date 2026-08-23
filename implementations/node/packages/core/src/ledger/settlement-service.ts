import { DomainError } from '../domain-error.js';
import type { AccountRepository, JournalRepository, OpenItemRepository } from '../port.js';
import type { Currency } from '../substrate/currency.js';
import { InvalidValue } from '../substrate/errors.js';
import type { JournalEntry } from '../substrate/journal-entry.js';
import { Money } from '../substrate/money.js';
import { Uuid } from '../substrate/uuid.js';
import { OpenItem } from '../records/open-item.js';
import { Settlement } from '../policies/expansion/settlement.js';
import { parseSettlementDifferenceKind, type SettlementDifferenceKind } from '../substrate/types.js';
import type { AuditWriter } from './audit-writer.js';
import { requireEntry } from './lookups.js';

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * Settlement: allocation payment → open item(s), also partial; always
 * explicit, no FIFO (determinismus.md §3). Differences (cash discount/write-off/
 * small difference) per api.md G2. Validate fully first, then apply.
 *
 * **This service does not post.** It takes an entry the caller has already written, checks the
 * allocation is covered by what that entry moves on the item's account, and records it. So when a
 * difference reduces the consideration of an item that carried tax, nothing here demands that the
 * caller's entry also corrects that tax — and no projection can notice the omission afterwards,
 * because each one computes correctly from whatever is on the journal.
 *
 * That is a deliberate boundary, not an oversight: whether a given reduction changes the taxable
 * base is a question a jurisdiction answers, and the policy kind that would express it has no
 * socket in this core yet. Until it does, the caller owes the correction line. See the ➖ row in
 * docs/gobd-conformance.md §4 and A-13 in the app's obligation list.
 */
export class SettlementService {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly journal: JournalRepository,
    private readonly openItems: OpenItemRepository,
    private readonly audit: AuditWriter,
  ) {}

  settle(input: Record<string, unknown>): OpenItem[] {
    const actor = this.audit.actorOf(input);
    const entry = requireEntry(this.journal, input.entryId);

    const allocations = Array.isArray(input.allocations) ? input.allocations : [];
    if (allocations.length === 0) {
      throw new DomainError('E_OPENITEM_UNKNOWN', 'settle without allocations');
    }

    const plan: Array<{ item: OpenItem; settlement: Settlement }> = [];
    const planned = new Map<string, Money>();

    for (const allocation of allocations) {
      if (!isRecord(allocation)) {
        throw new DomainError('E_OPENITEM_UNKNOWN', 'Allocation is not a structure');
      }
      const openItemId = allocation.openItemId;
      let item: OpenItem | null = null;
      if (typeof openItemId === 'string') {
        try {
          item = this.openItems.byId(Uuid.fromString(openItemId));
        } catch (error) {
          if (!(error instanceof InvalidValue)) throw error;
        }
      }
      if (item === null) {
        throw new DomainError(
          'E_OPENITEM_UNKNOWN',
          `Open item ${typeof openItemId === 'string' ? openItemId : '?'} does not exist`,
        );
      }

      const money = this.parseSettlementMoney(allocation.money, 'Allocation amount');
      const [differenceMoney, differenceKind] = this.parseDifference(allocation.difference ?? null, item);

      const alreadyPlanned = planned.get(item.id.value) ?? Money.zero(this.baseCurrency);
      if (money.add(alreadyPlanned).compareTo(item.remaining()) > 0) {
        throw new DomainError(
          'E_SETTLEMENT_EXCEEDS_ITEM',
          `Allocation ${money.amountAsString()} exceeds remaining amount ${item
            .remaining()
            .subtract(alreadyPlanned)
            .amountAsString()} of item ${item.id.value}`,
          { openItemId: item.id.value },
        );
      }

      planned.set(item.id.value, money.add(alreadyPlanned));
      plan.push({
        item,
        settlement: new Settlement(entry.id, money, entry.entryDate, differenceMoney, differenceKind),
      });
    }

    this.assertEntryCoversAllocations(entry, plan);

    const affected: OpenItem[] = [];
    for (const step of plan) {
      const before = step.item.remaining().amountAsString();
      step.item.settle(step.settlement);
      this.openItems.save(step.item);
      this.audit.record(actor, 'openItem', step.item.id, 'settled', {
        remaining: { from: before, to: step.item.remaining().amountAsString() },
      });
      affected.push(step.item);
    }

    return affected;
  }

  /**
   * R-1: an allocation may not claim more than the settling entry actually books against the open
   * item's account.
   *
   * The only bound used to be the item's remaining amount, so a 500.00 payment could close a
   * 1190.00 receivable in full. The general ledger then carries a 690.00 receivable the subledger
   * no longer knows about — permanently, and with nothing to point at it — and under cash-basis
   * taxation the VAT return declares tax as collected that never arrived.
   *
   * The bound is the entry's NET REDUCING movement on that account, not its total: a payment with
   * a discount books the full receivable against the receivables account and carries the
   * difference as its own line, so those settlements stay valid. Settlements already recorded
   * against this same entry count against the same budget — otherwise the check could be walked
   * around by settling twice.
   */
  private assertEntryCoversAllocations(
    entry: JournalEntry,
    plan: Array<{ item: OpenItem; settlement: Settlement }>,
  ): void {
    const zero = Money.zero(this.baseCurrency);

    // What this entry moves per account, signed so that a positive value reduces a receivable.
    const movement = new Map<string, Money>();
    for (const line of entry.lines()) {
      const account = this.accounts.byId(line.accountId);
      if (account === null) continue;
      const key = account.number.value;
      const signed = line.side === 'credit' ? line.money : line.money.negate();
      movement.set(key, (movement.get(key) ?? zero).add(signed));
    }

    // Already claimed against this entry by an earlier settle call.
    const claimed = new Map<string, Money>();
    const addClaim = (item: OpenItem, amount: Money): void => {
      const account = this.accountOfOpenItem(item);
      if (account === null) return;
      const asReduction = item.kind === 'payable' ? amount.negate() : amount;
      claimed.set(account, (claimed.get(account) ?? zero).add(asReduction));
    };

    for (const item of this.openItems.all()) {
      for (const settlement of item.settlements()) {
        if (settlement.entryId.value === entry.id.value) addClaim(item, settlement.money);
      }
    }
    for (const step of plan) {
      addClaim(step.item, step.settlement.money);
    }

    for (const [account, needed] of claimed) {
      const available = movement.get(account) ?? zero;
      // Compared in the reducing direction: `needed` is already signed that way, and so is
      // `available`, because a payable is reduced by a debit and a receivable by a credit.
      if (needed.abs().compareTo(available.abs()) > 0 || needed.isPositive() !== available.isPositive()) {
        throw new DomainError(
          'E_SETTLEMENT_EXCEEDS_ENTRY',
          `Allocations against account ${account} claim ${needed.abs().amountAsString()}, ` +
            `but the entry moves ${available.abs().amountAsString()} there`,
          { account, claimed: needed.abs().amountAsString(), available: available.abs().amountAsString() },
        );
      }
    }
  }

  /** The account an open item sits on — its origin posting's line. */
  private accountOfOpenItem(item: OpenItem): string | null {
    const origin = this.journal.byId(item.originEntryId);
    const line = origin?.lines()[item.originLineIndex];
    if (line === undefined) return null;
    return this.accounts.byId(line.accountId)?.number.value ?? null;
  }

  private parseSettlementMoney(raw: unknown, label: string): Money {
    const amount = isRecord(raw) ? asString(raw.amount) : null;
    const currency = isRecord(raw) ? asString(raw.currency) : null;
    if (amount === null || currency !== this.baseCurrency.code) {
      throw new InvalidValue(`${label} missing or wrong currency`);
    }
    const money = Money.of(amount, this.baseCurrency);
    if (!money.isPositive()) {
      throw new InvalidValue(`${label} must be > 0`);
    }
    return money;
  }

  private parseDifference(
    raw: unknown,
    item: OpenItem,
  ): [Money | null, SettlementDifferenceKind | null] {
    if (raw === null) return [null, null];
    if (!isRecord(raw)) {
      throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'difference is not a structure');
    }
    const kind = parseSettlementDifferenceKind(raw.kind);
    if (kind === null) {
      throw new DomainError(
        'E_SETTLEMENT_DIFFERENCE_INVALID',
        `Unknown difference kind "${typeof raw.kind === 'string' ? raw.kind : '?'}"`,
      );
    }
    let money: Money;
    try {
      money = this.parseSettlementMoney(raw.money, 'Difference amount');
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'Difference amount invalid (≤ 0 or format)');
      }
      throw error;
    }
    if (money.compareTo(item.remaining()) > 0) {
      throw new DomainError(
        'E_SETTLEMENT_DIFFERENCE_INVALID',
        `Difference ${money.amountAsString()} exceeds remaining amount ${item.remaining().amountAsString()}`,
      );
    }
    return [money, kind];
  }
}
