import { DomainError } from '../domain-error.js';
import type {
  AccountRepository,
  AuditTrail,
  FiscalYearRepository,
  JournalRepository,
  OpenItemRepository,
  VoucherRepository,
} from '../port.js';
import { AccountNumber } from '../shared/account-number.js';
import { CalendarDate } from '../shared/calendar-date.js';
import type { Clock } from '../shared/clock.js';
import type { Currency } from '../shared/currency.js';
import { DimensionValue } from '../shared/dimension-value.js';
import { InvalidValue } from '../shared/errors.js';
import type { IdGenerator } from '../shared/id-generator.js';
import { Money } from '../shared/money.js';
import { PeriodRef } from '../shared/period-ref.js';
import { Uuid } from '../shared/uuid.js';
import { Account } from './account.js';
import { AuditRecord, type AuditChanges } from './audit-record.js';
import { DimensionRegistry } from './dimension-registry.js';
import { EntryLine } from './entry-line.js';
import { FiscalYear } from './fiscal-year.js';
import { JournalEntry } from './journal-entry.js';
import { OpenItem } from './open-item.js';
import { PostResult } from './post-result.js';
import { Settlement } from './settlement.js';
import {
  isAccountType,
  type OpenItemKind,
  parseSettlementDifferenceKind,
  type SettlementDifferenceKind,
  type Side,
} from './types.js';
import type { Voucher } from './voucher.js';

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
 * Domain Service `post` und Verwandte (ledger-modell.md). Prüfreihenfolge beim
 * Buchen ist Vertragsbestandteil (api.md):
 *   1. Struktur (E_ENTRY_TOO_FEW_LINES, E_ENTRY_INVALID_AMOUNT)
 *   2. Referenzen (E_ENTRY_NO_VOUCHER, E_VOUCHER_UNKNOWN, E_ACCOUNT_UNKNOWN,
 *      E_ACCOUNT_LOCKED, E_DIMENSION_INVALID)
 *   3. Bilanzgleichung (E_ENTRY_UNBALANCED)
 *   4. Zeitlicher Kontext (E_PERIOD_UNKNOWN, E_PERIOD_CLOSED)
 * Nur der erste Fehler wird gemeldet.
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

    // 1. Struktur
    const rawLines = input.lines;
    if (!Array.isArray(rawLines) || rawLines.length < 2) {
      throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'Eine Buchung braucht mindestens zwei Positionen');
    }
    const parsed = rawLines.map((rawLine, index) => {
      if (!isRecord(rawLine)) {
        throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Position ${index} ist keine Struktur`);
      }
      return this.parseLine(rawLine, index);
    });

    // 2. Referenzen
    const voucher = this.requireVoucher(input.voucherId);
    const lines = this.resolveLines(parsed);

    // 3. Bilanzgleichung
    this.assertBalanced(lines);

    // 4. Zeitlicher Kontext
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
   * AR/AP-Automatik: Soll auf Forderungskonto → receivable, Haben auf
   * Verbindlichkeitskonto → payable. Stornobuchungen erzeugen keine Posten.
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
   * Ausgleich: Zuordnung Zahlung → offene(r) Posten, auch teilweise; immer
   * explizit, kein FIFO (determinismus.md §3). Differenzen (Skonto/Ausfall/
   * Kleindifferenz) nach api.md G2. Erst vollständig validieren, dann anwenden.
   */
  settle(input: Record<string, unknown>): OpenItem[] {
    const actor = this.actor(input);
    const entry = this.requireEntry(input.entryId);

    const allocations = Array.isArray(input.allocations) ? input.allocations : [];
    if (allocations.length === 0) {
      throw new DomainError('E_OPENITEM_UNKNOWN', 'settle ohne Zuordnungen');
    }

    const plan: Array<{ item: OpenItem; settlement: Settlement }> = [];
    const planned = new Map<string, Money>();

    for (const allocation of allocations) {
      if (!isRecord(allocation)) {
        throw new DomainError('E_OPENITEM_UNKNOWN', 'Zuordnung ist keine Struktur');
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
          `Offener Posten ${typeof openItemId === 'string' ? openItemId : '?'} existiert nicht`,
        );
      }

      const money = this.parseSettlementMoney(allocation.money, 'Zuordnungsbetrag');
      const [differenceMoney, differenceKind] = this.parseDifference(allocation.difference ?? null, item);

      const alreadyPlanned = planned.get(item.id.value) ?? Money.zero(this.baseCurrency);
      if (money.add(alreadyPlanned).compareTo(item.remaining()) > 0) {
        throw new DomainError(
          'E_SETTLEMENT_EXCEEDS_ITEM',
          `Zuordnung ${money.amountAsString()} übersteigt Restbetrag ${item
            .remaining()
            .subtract(alreadyPlanned)
            .amountAsString()} des Postens ${item.id.value}`,
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
      throw new InvalidValue(`${label} fehlt oder falsche Währung`);
    }
    const money = Money.of(amount, this.baseCurrency);
    if (!money.isPositive()) {
      throw new InvalidValue(`${label} muss > 0 sein`);
    }
    return money;
  }

  private parseDifference(
    raw: unknown,
    item: OpenItem,
  ): [Money | null, SettlementDifferenceKind | null] {
    if (raw === null) return [null, null];
    if (!isRecord(raw)) {
      throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'difference ist keine Struktur');
    }
    const kind = parseSettlementDifferenceKind(raw.kind);
    if (kind === null) {
      throw new DomainError(
        'E_SETTLEMENT_DIFFERENCE_INVALID',
        `Unbekannte Differenzart "${typeof raw.kind === 'string' ? raw.kind : '?'}"`,
      );
    }
    let money: Money;
    try {
      money = this.parseSettlementMoney(raw.money, 'Differenzbetrag');
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_SETTLEMENT_DIFFERENCE_INVALID', 'Differenzbetrag ungültig (≤ 0 oder Format)');
      }
      throw error;
    }
    if (money.compareTo(item.remaining()) > 0) {
      throw new DomainError(
        'E_SETTLEMENT_DIFFERENCE_INVALID',
        `Differenz ${money.amountAsString()} übersteigt Restbetrag ${item.remaining().amountAsString()}`,
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
        throw new DomainError('E_ENTRY_TOO_FEW_LINES', 'Eine Buchung braucht mindestens zwei Positionen');
      }
      const parsed = input.lines.map((rawLine, index) => {
        if (!isRecord(rawLine)) {
          throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Position ${index} ist keine Struktur`);
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
      // Statusprüfung auch ohne effektive Änderung (E_ENTRY_FINALIZED).
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
      throw new DomainError('E_ENTRY_UNKNOWN', 'finalize braucht entryId oder finalizeUntil');
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
      throw new DomainError('E_ENTRY_ALREADY_REVERSED', `Buchung ${original.id.value} ist bereits storniert`, {
        entryId: original.id.value,
      });
    }

    const entryDate = this.parseEntryDate(input.entryDate);
    const [fiscalYear, period] = this.openPeriodFor(entryDate);
    const text = asString(input.text) ?? `Storno ${original.sequenceNumber}`;

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
          `Jahresabschluss ${fiscalYear.year}: Buchung ${entry.sequenceNumber} ist nicht festgeschrieben`,
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
          `Geschäftsjahr ${year} (${start.iso} bis ${end.iso}) überschneidet sich mit ${existing.year}`,
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
      throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', `Kontonummer ${account.number.value} ist bereits vergeben`, {
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
      throw new DomainError('E_ACCOUNT_UNKNOWN', `Konto ${number} existiert nicht`, { number });
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
      throw new DomainError('E_COA_FORMAT_INVALID', 'Import ohne Zeilen');
    }

    const accounts: Account[] = [];
    const numbers = new Set<string>();

    rows.forEach((row, index) => {
      if (!isRecord(row)) {
        throw new DomainError('E_COA_FORMAT_INVALID', `Zeile ${index} ist keine Struktur`);
      }
      let account: Account;
      try {
        account = this.buildAccount(row);
      } catch (error) {
        if (error instanceof DomainError) {
          throw new DomainError('E_COA_FORMAT_INVALID', `Zeile ${index} ist nicht parsebar`, { row: index });
        }
        throw error;
      }
      if (numbers.has(account.number.value) || this.accounts.byNumber(account.number) !== null) {
        throw new DomainError('E_ACCOUNT_NUMBER_TAKEN', `Kontonummer ${account.number.value} ist bereits vergeben`, {
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

  // ---- intern ----------------------------------------------------------

  private actor(input: Record<string, unknown>): string {
    const actor = asString(input.actor);
    return actor !== null && actor !== '' ? actor : 'system';
  }

  private parseLine(rawLine: Record<string, unknown>, index: number): ParsedLine {
    const money = rawLine.money;
    const amount = isRecord(money) ? asString(money.amount) : null;
    const currency = isRecord(money) ? asString(money.currency) : null;

    if (amount === null || currency === null) {
      throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Position ${index}: money fehlt oder unvollständig`);
    }
    if (currency !== this.baseCurrency.code) {
      throw new DomainError(
        'E_ENTRY_INVALID_AMOUNT',
        `Position ${index}: Fremdwährung ${currency} — v1 bucht nur Mandantenwährung ${this.baseCurrency.code}`,
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
          `Position ${index}: Betrag "${amount}" ist kein gültiger ${this.baseCurrency.code}-Betrag`,
          { amount },
        );
      }
      throw error;
    }
    if (!parsedMoney.isPositive()) {
      throw new DomainError(
        'E_ENTRY_INVALID_AMOUNT',
        `Position ${index}: Betrag muss > 0 sein (negative Beträge nur bei Storno)`,
        { amount },
      );
    }

    const side = rawLine.side;
    if (side !== 'debit' && side !== 'credit') {
      throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Position ${index}: side muss debit oder credit sein`);
    }

    const account = asString(rawLine.account);
    if (account === null || account === '') {
      throw new DomainError('E_ENTRY_INVALID_AMOUNT', `Position ${index}: account fehlt`);
    }

    const dimensions: DimensionValue[] = [];
    const rawDimensions = Array.isArray(rawLine.dimensions) ? rawLine.dimensions : [];
    for (const rawDimension of rawDimensions) {
      if (!isRecord(rawDimension) || typeof rawDimension.type !== 'string' || typeof rawDimension.code !== 'string') {
        throw new DomainError('E_DIMENSION_INVALID', `Position ${index}: Dimension unvollständig`);
      }
      dimensions.push(DimensionValue.of(rawDimension.type, rawDimension.code));
    }

    const taxTag = isRecord(rawLine.taxTag) ? rawLine.taxTag : null;

    return { account, side, money: parsedMoney, dimensions, taxTag };
  }

  private requireVoucher(voucherId: unknown): Voucher {
    if (typeof voucherId !== 'string' || voucherId === '') {
      throw new DomainError('E_ENTRY_NO_VOUCHER', 'Keine Buchung ohne Beleg (F-CORE-003)');
    }
    let voucher: Voucher | null = null;
    try {
      voucher = this.vouchers.byId(Uuid.fromString(voucherId));
    } catch (error) {
      if (!(error instanceof InvalidValue)) throw error;
    }
    if (voucher === null) {
      throw new DomainError('E_VOUCHER_UNKNOWN', `Beleg ${voucherId} existiert nicht`, { voucherId });
    }
    return voucher;
  }

  private resolveLines(parsed: ParsedLine[]): EntryLine[] {
    const lines = parsed.map((line) => {
      const number = AccountNumber.of(line.account);
      const account = this.accounts.byNumber(number);
      if (account === null) {
        throw new DomainError('E_ACCOUNT_UNKNOWN', `Konto ${number.value} existiert nicht`, { number: number.value });
      }
      if (account.isLocked()) {
        throw new DomainError('E_ACCOUNT_LOCKED', `Konto ${number.value} ist gesperrt`, { number: number.value });
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
        `Σ Soll (${debit.amountAsString()}) ≠ Σ Haben (${credit.amountAsString()})`,
        { debit: debit.amountAsString(), credit: credit.amountAsString() },
      );
    }
  }

  private parseEntryDate(entryDate: unknown): CalendarDate {
    if (typeof entryDate !== 'string') {
      throw new DomainError('E_PERIOD_UNKNOWN', 'entryDate fehlt');
    }
    try {
      return CalendarDate.of(entryDate);
    } catch (error) {
      if (error instanceof InvalidValue) {
        throw new DomainError('E_PERIOD_UNKNOWN', `Ungültiges Buchungsdatum "${entryDate}"`);
      }
      throw error;
    }
  }

  private openPeriodFor(entryDate: CalendarDate): [FiscalYear, ReturnType<FiscalYear['periodForDate']>] {
    const fiscalYear = this.fiscalYears.forDate(entryDate);
    if (fiscalYear === null) {
      throw new DomainError(
        'E_PERIOD_UNKNOWN',
        `Buchungsdatum ${entryDate.iso} liegt außerhalb angelegter Geschäftsjahre`,
        { date: entryDate.iso },
      );
    }
    const period = fiscalYear.periodForDate(entryDate);
    if (fiscalYear.isClosed() || !period.isOpen()) {
      throw new DomainError('E_PERIOD_CLOSED', `Periode ${fiscalYear.year}/${period.number} ist geschlossen`, {
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
      throw new DomainError('E_ENTRY_UNKNOWN', `Buchung ${typeof entryId === 'string' ? entryId : '?'} existiert nicht`);
    }
    return entry;
  }

  private requireFiscalYear(year: unknown): FiscalYear {
    const fiscalYear = typeof year === 'number' ? this.fiscalYears.byYear(year) : null;
    if (fiscalYear === null) {
      throw new DomainError('E_PERIOD_UNKNOWN', `Geschäftsjahr ${typeof year === 'number' ? year : '?'} ist nicht angelegt`);
    }
    return fiscalYear;
  }

  private periodNumber(input: Record<string, unknown>): number {
    const period = input.period;
    if (typeof period !== 'number' || !Number.isInteger(period)) {
      throw new DomainError('E_PERIOD_UNKNOWN', 'Periodennummer fehlt');
    }
    return period;
  }

  private buildAccount(input: Record<string, unknown>): Account {
    const number = asString(input.number);
    const name = asString(input.name);
    const type = input.type;

    if (number === null || number === '' || name === null || name === '' || !isAccountType(type)) {
      throw new DomainError('E_COA_FORMAT_INVALID', 'Konto braucht number, name und gültigen type');
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
