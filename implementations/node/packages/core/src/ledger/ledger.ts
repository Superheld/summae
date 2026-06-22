import { DomainError } from '../domain-error.js';
import type {
  AccountRepository,
  AuditTrail,
  FiscalYearRepository,
  JournalRepository,
  OpenItemRepository,
  VoucherRepository,
} from '../port.js';
import { AccountNumber } from '../substrate/account-number.js';
import { CalendarDate } from '../substrate/calendar-date.js';
import type { Clock } from '../substrate/clock.js';
import type { Currency } from '../substrate/currency.js';
import { DimensionValue } from '../substrate/dimension-value.js';
import { InvalidValue } from '../substrate/errors.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { Money } from '../substrate/money.js';
import { PeriodRef } from '../substrate/period-ref.js';
import { Uuid } from '../substrate/uuid.js';
import { Account } from '../substrate/account.js';
import { AuditRecord, type AuditChanges } from '../records/audit-record.js';
import { DimensionRegistry } from '../policies/constraint/dimension-registry.js';
import { EntryLine } from '../substrate/entry-line.js';
import { FiscalYear } from '../substrate/fiscal-year.js';
import { JournalEntry } from '../substrate/journal-entry.js';
import { OpenItem } from '../records/open-item.js';
import { PostResult } from '../substrate/post-result.js';
import { Settlement } from '../policies/expansion/settlement.js';
import {
  isAccountType,
  type OpenItemKind,
  parseSettlementDifferenceKind,
  type SettlementDifferenceKind,
  type Side,
} from '../substrate/types.js';
import type { Voucher } from '../records/voucher.js';

interface ParsedLine {
  readonly account: string;
  readonly side: Side;
  readonly money: Money;
  readonly dimensions: DimensionValue[];
  readonly taxTag: Record<string, unknown> | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === 'string' ? value : null;
}

/**
 * Domain Service `post` and relatives (ledger-modell.md). Check order when
 * posting is part of the contract (api.md):
 *   1. Structure (E_ENTRY_TOO_FEW_LINES, E_ENTRY_INVALID_AMOUNT)
 *   2. References (E_ENTRY_NO_VOUCHER, E_VOUCHER_UNKNOWN, E_ACCOUNT_UNKNOWN,
 *      E_ACCOUNT_LOCKED, E_DIMENSION_INVALID)
 *   3. Balance equation (E_ENTRY_UNBALANCED)
 *   4. Temporal context (E_PERIOD_UNKNOWN, E_PERIOD_CLOSED)
 * Only the first error is reported.
 */
export class Ledger {
  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly fiscalYears: FiscalYearRepository,
    private readonly vouchers: VoucherRepository,
    private readonly journal: JournalRepository,
    private readonly openItems: OpenItemRepository,
    private readonly audit: AuditTrail,
    private readonly dimensions: DimensionRegistry,
    private readonly clock: Clock,
    private readonly ids: IdGenerator,
  ) {}

  post(input: Record<string, unknown>): PostResult {
    const actor = this.actor(input);

    // 1. Structure
    const rawLines = input.lines;
    if (!Array.isArray(rawLines) || rawLines.length < 2) {
      throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'A posting needs at least two lines');
    }
    const parsed = rawLines.map((rawLine, index) => {
      if (!isRecord(rawLine)) {
        throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Line ${index} is not a structure`);
      }
      return this.parseLine(rawLine, index);
    });

    // 2. References
    const voucher = this.requireVoucher(input.voucherId);
    const lines = this.resolveLines(parsed);

    // 3. Balance equation
    this.assertBalanced(lines);

    // 4. Temporal context
    const entryDate = this.parseEntryDate(input.entryDate);
    const [fiscalYear, period] = this.openPeriodFor(entryDate);

    const text = asString(input.text) ?? '';

    const entry = new JournalEntry(
      this.ids.next(),
      this.journal.nextSequenceNumber(fiscalYear.year),
      entryDate,
      voucher.voucherDate,
      this.now(),
      new PeriodRef(fiscalYear.year, period.number),
      voucher.id,
      text,
      lines,
    );

    this.journal.append(entry);
    this.recordAudit(actor, 'journalEntry', entry.id, 'created');

    return new PostResult(entry, this.createOpenItems(entry));
  }

  /**
   * AR/AP automation: debit on a receivable account → receivable, credit on a
   * payable account → payable. Reversal postings create no items.
   */
  private createOpenItems(entry: JournalEntry): OpenItem[] {
    if (entry.reverses !== null) return [];

    const created: OpenItem[] = [];
    const voucher = this.vouchers.byId(entry.voucherId);

    entry.lines().forEach((line, index) => {
      const account = this.accounts.byId(line.accountId);
      let kind: OpenItemKind | null = null;
      if (account?.subtype === 'ar' && line.side === 'debit') kind = 'receivable';
      else if (account?.subtype === 'ap' && line.side === 'credit') kind = 'payable';
      if (kind === null) return;

      const item = new OpenItem(
        this.ids.next(),
        kind,
        entry.id,
        index,
        line.money,
        entry.voucherId,
        entry.entryDate,
        voucher?.partnerId ?? null,
      );
      this.openItems.add(item);
      created.push(item);
    });

    return created;
  }

  /**
   * Settlement: allocation payment → open item(s), also partial; always
   * explicit, no FIFO (determinismus.md §3). Differences (cash discount/write-off/
   * small difference) per api.md G2. Validate fully first, then apply.
   */
  settle(input: Record<string, unknown>): OpenItem[] {
    const actor = this.actor(input);
    const entry = this.requireEntry(input.entryId);

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

    const affected: OpenItem[] = [];
    for (const step of plan) {
      const before = step.item.remaining().amountAsString();
      step.item.settle(step.settlement);
      this.openItems.save(step.item);
      this.recordAudit(actor, 'openItem', step.item.id, 'settled', {
        remaining: { from: before, to: step.item.remaining().amountAsString() },
      });
      affected.push(step.item);
    }

    return affected;
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

  correct(input: Record<string, unknown>): JournalEntry {
    const actor = this.actor(input);
    const entry = this.requireEntry(input.entryId);
    const changes: AuditChanges = {};

    const text = asString(input.text);
    if (text !== null && text !== entry.text()) {
      changes.text = { from: entry.text(), to: text };
      entry.changeText(text);
    }

    if (Array.isArray(input.lines)) {
      if (input.lines.length < 2) {
        throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'A posting needs at least two lines');
      }
      const parsed = input.lines.map((rawLine, index) => {
        if (!isRecord(rawLine)) {
          throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Line ${index} is not a structure`);
        }
        return this.parseLine(rawLine, index);
      });
      const lines = this.resolveLines(parsed);
      this.assertBalanced(lines);

      changes.lines = {
        from: entry.lines().map((line) => line.toJSON()),
        to: lines.map((line) => line.toJSON()),
      };
      entry.changeLines(lines);
    }

    if (Object.keys(changes).length > 0) {
      this.journal.save(entry);
      this.recordAudit(actor, 'journalEntry', entry.id, 'corrected', changes);
    } else {
      // Status check even without an effective change (E_ENTRY_FINALIZED).
      entry.changeText(entry.text());
    }

    return entry;
  }

  finalize(input: Record<string, unknown>): number {
    const actor = this.actor(input);

    if (input.entryId !== undefined) {
      const entry = this.requireEntry(input.entryId);
      if (entry.isFinalized()) return 0;
      entry.finalize();
      this.journal.save(entry);
      this.recordAudit(actor, 'journalEntry', entry.id, 'finalized', {
        status: { from: 'entered', to: 'finalized' },
      });
      return 1;
    }

    const until = input.finalizeUntil;
    if (typeof until !== 'string') {
      throw new DomainError('E_ENTRY_UNKNOWN', 'finalize needs entryId or finalizeUntil');
    }
    const untilDate = this.parseEntryDate(until);
    let count = 0;

    for (const entry of this.journal.all()) {
      if (entry.isFinalized() || entry.entryDate.isAfter(untilDate)) continue;
      entry.finalize();
      this.journal.save(entry);
      this.recordAudit(actor, 'journalEntry', entry.id, 'finalized', {
        status: { from: 'entered', to: 'finalized' },
      });
      count++;
    }
    return count;
  }

  reverse(input: Record<string, unknown>): JournalEntry {
    const actor = this.actor(input);
    const original = this.requireEntry(input.entryId);

    if (original.reversedBy() !== null) {
      throw new DomainError('E_ENTRY_ALREADY_REVERSED', `Posting ${original.id.value} is already reversed`, {
        entryId: original.id.value,
      });
    }

    const entryDate = this.parseEntryDate(input.entryDate);
    const [fiscalYear, period] = this.openPeriodFor(entryDate);
    const text = asString(input.text) ?? `Reversal ${original.sequenceNumber}`;

    const reversal = new JournalEntry(
      this.ids.next(),
      this.journal.nextSequenceNumber(fiscalYear.year),
      entryDate,
      original.voucherDate,
      this.now(),
      new PeriodRef(fiscalYear.year, period.number),
      original.voucherId,
      text,
      original.lines().map((line) => line.negated()),
      original.id,
    );

    original.markReversed(reversal.id);
    this.journal.append(reversal);
    this.journal.save(original);

    this.recordAudit(actor, 'journalEntry', reversal.id, 'created');
    this.recordAudit(actor, 'journalEntry', original.id, 'reversed', {
      reversedBy: { from: null, to: reversal.id.value },
    });

    return reversal;
  }

  closePeriod(input: Record<string, unknown>): { fiscalYear: number; period: number; status: string } {
    const fiscalYear = this.requireFiscalYear(input.fiscalYear);
    const period = fiscalYear.closePeriod(this.periodNumber(input));
    this.fiscalYears.save(fiscalYear);
    return { fiscalYear: fiscalYear.year, period: period.number, status: period.status() };
  }

  reopenPeriod(input: Record<string, unknown>): { fiscalYear: number; period: number; status: string } {
    const fiscalYear = this.requireFiscalYear(input.fiscalYear);
    const period = fiscalYear.reopenPeriod(this.periodNumber(input));
    this.fiscalYears.save(fiscalYear);
    return { fiscalYear: fiscalYear.year, period: period.number, status: period.status() };
  }

  closeFiscalYear(input: Record<string, unknown>): FiscalYear {
    const fiscalYear = this.requireFiscalYear(input.fiscalYear);
    for (const entry of this.journal.forFiscalYear(fiscalYear.year)) {
      if (!entry.isFinalized()) {
        throw new DomainError(
          'E_FISCALYEAR_UNFINALIZED_ENTRIES',
          `Year-end close ${fiscalYear.year}: posting ${entry.sequenceNumber} is not finalized`,
          { fiscalYear: fiscalYear.year, sequenceNumber: entry.sequenceNumber },
        );
      }
    }
    fiscalYear.close();
    this.fiscalYears.save(fiscalYear);
    return fiscalYear;
  }

  createFiscalYear(input: Record<string, unknown>): FiscalYear {
    const year = typeof input.year === 'number' ? input.year : 0;
    const start = this.parseEntryDate(input.start);
    const end = this.parseEntryDate(input.end);

    for (const existing of this.fiscalYears.all()) {
      const overlaps = !existing.end.isBefore(start) && !existing.start.isAfter(end);
      if (overlaps || existing.year === year) {
        throw new DomainError(
          'E_FISCALYEAR_OVERLAP',
          `Fiscal year ${year} (${start.iso} to ${end.iso}) overlaps with ${existing.year}`,
          { year, existing: existing.year },
        );
      }
    }

    const fiscalYear = FiscalYear.create(this.ids.next(), year, start, end);
    this.fiscalYears.add(fiscalYear);
    return fiscalYear;
  }

  createAccount(input: Record<string, unknown>): Account {
    const actor = this.actor(input);
    const account = this.buildAccount(input);

    if (this.accounts.byNumber(account.number) !== null) {
      throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', `Account number ${account.number.value} is already taken`, {
        number: account.number.value,
      });
    }

    this.accounts.add(account);
    this.recordAudit(actor, 'account', account.id, 'created');
    return account;
  }

  lockAccount(input: Record<string, unknown>): Account {
    const actor = this.actor(input);
    const number = asString(input.number) ?? '';
    const account = this.accounts.byNumber(AccountNumber.of(number));

    if (account === null) {
      throw new DomainError('E_ACCOUNT_UNKNOWN', `Account ${number} does not exist`, { number });
    }

    const before = account.status();
    account.lock();
    this.accounts.save(account);
    this.recordAudit(actor, 'account', account.id, 'locked', {
      status: { from: before, to: account.status() },
    });
    return account;
  }

  importChartOfAccounts(input: Record<string, unknown>): number {
    const actor = this.actor(input);
    const rows = input.rows;
    if (!Array.isArray(rows) || rows.length === 0) {
      throw new DomainError('E_COA_FORMAT_INVALID', 'Import without rows');
    }

    const accounts: Account[] = [];
    const numbers = new Set<string>();

    rows.forEach((row, index) => {
      if (!isRecord(row)) {
        throw new DomainError('E_COA_FORMAT_INVALID', `Row ${index} is not a structure`);
      }
      let account: Account;
      try {
        account = this.buildAccount(row);
      } catch (error) {
        if (error instanceof DomainError) {
          throw new DomainError('E_COA_FORMAT_INVALID', `Row ${index} is not parsable`, { row: index });
        }
        throw error;
      }
      if (numbers.has(account.number.value) || this.accounts.byNumber(account.number) !== null) {
        throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', `Account number ${account.number.value} is already taken`, {
          number: account.number.value,
        });
      }
      numbers.add(account.number.value);
      accounts.push(account);
    });

    for (const account of accounts) {
      this.accounts.add(account);
      this.recordAudit(actor, 'account', account.id, 'created');
    }
    return accounts.length;
  }

  // ---- internal --------------------------------------------------------

  private actor(input: Record<string, unknown>): string {
    const actor = asString(input.actor);
    return actor !== null && actor !== '' ? actor : 'system';
  }

  private parseLine(rawLine: Record<string, unknown>, index: number): ParsedLine {
    const money = rawLine.money;
    const amount = isRecord(money) ? asString(money.amount) : null;
    const currency = isRecord(money) ? asString(money.currency) : null;

    if (amount === null || currency === null) {
      throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Line ${index}: money missing or incomplete`);
    }
    if (currency !== this.baseCurrency.code) {
      throw new DomainError(
        'E_ENTRY_INVALID_AMOUNT',
        `Line ${index}: foreign currency ${currency} — v1 posts only the tenant currency ${this.baseCurrency.code}`,
        { currency },
      );
    }

    let parsedMoney: Money;
    try {
      parsedMoney = Money.of(amount, this.baseCurrency);
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError(
          'E_ENTRY_INVALID_AMOUNT',
          `Line ${index}: amount "${amount}" is not a valid ${this.baseCurrency.code} amount`,
          { amount },
        );
      }
      throw error;
    }
    if (!parsedMoney.isPositive()) {
      throw new DomainError(
        'E_ENTRY_INVALID_AMOUNT',
        `Line ${index}: amount must be > 0 (negative amounts only on reversal)`,
        { amount },
      );
    }

    const side = rawLine.side;
    if (side !== 'debit' && side !== 'credit') {
      throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Line ${index}: side must be debit or credit`);
    }

    const account = asString(rawLine.account);
    if (account === null || account === '') {
      throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Line ${index}: account missing`);
    }

    const dimensions: DimensionValue[] = [];
    const rawDimensions = Array.isArray(rawLine.dimensions) ? rawLine.dimensions : [];
    for (const rawDimension of rawDimensions) {
      if (!isRecord(rawDimension) || typeof rawDimension.type !== 'string' || typeof rawDimension.code !== 'string') {
        throw new DomainError('E_DIMENSION_INVALID', `Line ${index}: dimension incomplete`);
      }
      dimensions.push(DimensionValue.of(rawDimension.type, rawDimension.code));
    }

    const taxTag = isRecord(rawLine.taxTag) ? rawLine.taxTag : null;

    return { account, side, money: parsedMoney, dimensions, taxTag };
  }

  private requireVoucher(voucherId: unknown): Voucher {
    if (typeof voucherId !== 'string' || voucherId === '') {
      throw new DomainError('E_ENTRY_NO_VOUCHER', 'No posting without a voucher (F-CORE-003)');
    }
    let voucher: Voucher | null = null;
    try {
      voucher = this.vouchers.byId(Uuid.fromString(voucherId));
    } catch (error) {
      if (!(error instanceof InvalidValue)) throw error;
    }
    if (voucher === null) {
      throw new DomainError('E_VOUCHER_UNKNOWN', `Voucher ${voucherId} does not exist`, { voucherId });
    }
    return voucher;
  }

  private resolveLines(parsed: ParsedLine[]): EntryLine[] {
    const lines = parsed.map((line) => {
      const number = AccountNumber.of(line.account);
      const account = this.accounts.byNumber(number);
      if (account === null) {
        throw new DomainError('E_ACCOUNT_UNKNOWN', `Account ${number.value} does not exist`, { number: number.value });
      }
      if (account.isLocked()) {
        throw new DomainError('E_ACCOUNT_LOCKED', `Account ${number.value} is locked`, { number: number.value });
      }
      return new EntryLine(account.id, account.number, line.side, line.money, line.dimensions, line.taxTag);
    });

    for (const line of lines) {
      this.dimensions.validateLine(line.account, line.dimensions);
    }
    return lines;
  }

  private assertBalanced(lines: EntryLine[]): void {
    let debit = Money.zero(this.baseCurrency);
    let credit = Money.zero(this.baseCurrency);
    for (const line of lines) {
      if (line.side === 'debit') debit = debit.add(line.money);
      else credit = credit.add(line.money);
    }
    if (!debit.equals(credit)) {
      throw new DomainError(
        'E_ENTRY_UNBALANCED',
        `Σ debit (${debit.amountAsString()}) ≠ Σ credit (${credit.amountAsString()})`,
        { debit: debit.amountAsString(), credit: credit.amountAsString() },
      );
    }
  }

  private parseEntryDate(entryDate: unknown): CalendarDate {
    if (typeof entryDate !== 'string') {
      throw new DomainError('E_PERIOD_UNKNOWN', 'entryDate missing');
    }
    try {
      return CalendarDate.of(entryDate);
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_PERIOD_UNKNOWN', `Invalid posting date "${entryDate}"`);
      }
      throw error;
    }
  }

  private openPeriodFor(entryDate: CalendarDate): [FiscalYear, ReturnType<FiscalYear['periodForDate']>] {
    const fiscalYear = this.fiscalYears.forDate(entryDate);
    if (fiscalYear === null) {
      throw new DomainError(
        'E_PERIOD_UNKNOWN',
        `Posting date ${entryDate.iso} lies outside any created fiscal year`,
        { date: entryDate.iso },
      );
    }
    const period = fiscalYear.periodForDate(entryDate);
    if (fiscalYear.isClosed() || !period.isOpen()) {
      throw new DomainError('E_PERIOD_CLOSED', `Period ${fiscalYear.year}/${period.number} is closed`, {
        fiscalYear: fiscalYear.year,
        period: period.number,
      });
    }
    return [fiscalYear, period];
  }

  private requireEntry(entryId: unknown): JournalEntry {
    let entry: JournalEntry | null = null;
    if (typeof entryId === 'string' && entryId !== '') {
      try {
        entry = this.journal.byId(Uuid.fromString(entryId));
      } catch (error) {
        if (!(error instanceof InvalidValue)) throw error;
      }
    }
    if (entry === null) {
      throw new DomainError('E_ENTRY_UNKNOWN', `Posting ${typeof entryId === 'string' ? entryId : '?'} does not exist`);
    }
    return entry;
  }

  private requireFiscalYear(year: unknown): FiscalYear {
    const fiscalYear = typeof year === 'number' ? this.fiscalYears.byYear(year) : null;
    if (fiscalYear === null) {
      throw new DomainError('E_PERIOD_UNKNOWN', `Fiscal year ${typeof year === 'number' ? year : '?'} is not created`);
    }
    return fiscalYear;
  }

  private periodNumber(input: Record<string, unknown>): number {
    const period = input.period;
    if (typeof period !== 'number' || !Number.isInteger(period)) {
      throw new DomainError('E_PERIOD_UNKNOWN', 'Period number missing');
    }
    return period;
  }

  private buildAccount(input: Record<string, unknown>): Account {
    const number = asString(input.number);
    const name = asString(input.name);
    const type = input.type;

    if (number === null || number === '' || name === null || name === '' || !isAccountType(type)) {
      throw new DomainError('E_COA_FORMAT_INVALID', 'Account needs number, name and a valid type');
    }

    const subtype = asString(input.subtype);
    const status = input.status === 'locked' ? 'locked' : 'active';

    return new Account(this.ids.next(), AccountNumber.of(number), name, type, subtype, status);
  }

  private recordAudit(
    actor: string,
    objectType: string,
    objectId: Uuid,
    action: string,
    changes: AuditChanges = {},
  ): void {
    this.audit.append(new AuditRecord(this.ids.next(), this.now(), actor, objectType, objectId, action, changes));
  }

  private now(): string {
    return this.clock.now().toISOString();
  }
}
