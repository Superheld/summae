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
import type { CalendarDate } from '../substrate/calendar-date.js';
import type { Clock } from '../substrate/clock.js';
import type { Currency } from '../substrate/currency.js';
import { DimensionValue } from '../substrate/dimension-value.js';
import { InvalidValue } from '../substrate/errors.js';
import type { IdGenerator } from '../substrate/id-generator.js';
import { Money } from '../substrate/money.js';
import { PeriodRef } from '../substrate/period-ref.js';
import { Uuid } from '../substrate/uuid.js';
import type { Account } from '../substrate/account.js';
import type { AuditChanges } from '../records/audit-record.js';
import { TaxCodeRegistry } from '../policies/expansion/tax/tax-code-registry.js';
import { AccountCombinationRegistry, type ConstraintContext } from '../policies/constraint/account-combination-registry.js';
import type { LegalFormRegistry } from '../policies/projection/legal-forms.js';
import type { TaxProfile } from '../policies/expansion/tax/tax-profile.js';
import { DimensionRegistry } from '../policies/constraint/dimension-registry.js';
import { EntryLine } from '../substrate/entry-line.js';
import type { FiscalYear } from '../substrate/fiscal-year.js';
import { JournalEntry } from '../substrate/journal-entry.js';
import { OpenItem } from '../records/open-item.js';
import { PostResult } from '../substrate/post-result.js';
import { Settlement } from '../policies/expansion/settlement.js';
import { type OpenItemKind, type Side } from '../substrate/types.js';
import type { Voucher } from '../records/voucher.js';
import { AuditWriter } from './audit-writer.js';
import { ChartAdminService } from './chart-admin-service.js';
import type { TenantConfigStore } from '../composition/tenant-config-store.js';
import { FiscalPeriodService } from './fiscal-period-service.js';
import { SettlementService } from './settlement-service.js';
import { parseEntryDate, requireEntry } from './lookups.js';

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
 *
 * `Ledger` keeps the operations that write postings — `post`, `correct`, `finalize`, `reverse` —
 * together with the line parsing they share, and is a thin facade for the three areas that were
 * only ever neighbours of the journal, not part of it: settlement (`SettlementService`), chart of
 * accounts (`ChartAdminService`) and fiscal years/periods (`FiscalPeriodService`). The facade is
 * deliberate: `TenantOperations` and every adapter keep talking to one object, so the split is a
 * change of shape inside the core and of nothing else.
 */
export class Ledger {
  private readonly auditWriter: AuditWriter;
  private readonly settlements: SettlementService;
  private readonly chart: ChartAdminService;
  private readonly periods: FiscalPeriodService;

  constructor(
    private readonly baseCurrency: Currency,
    private readonly accounts: AccountRepository,
    private readonly fiscalYears: FiscalYearRepository,
    private readonly vouchers: VoucherRepository,
    private readonly journal: JournalRepository,
    private readonly openItems: OpenItemRepository,
    audit: AuditTrail,
    private readonly dimensions: DimensionRegistry,
    clock: Clock,
    private readonly ids: IdGenerator,
    private readonly taxCodes: TaxCodeRegistry = TaxCodeRegistry.empty(),
    // Only needed so that dimension declarations — which have no id of their own — can name their
    // tenant in the audit trail, like the other per-tenant configuration does.
    tenantId: Uuid | null = null,
    /** Passed straight through to the chart service, which is where dimensions are declared. */
    configStore: TenantConfigStore | null = null,
    /** The constraint socket's second predicate (F-CORE-042), checked over the whole entry. */
    private readonly combinations: AccountCombinationRegistry = AccountCombinationRegistry.empty(),
    /**
     * The two tenant facts a constraint rule may be conditioned on (`appliesWhen`, F-CORE-047).
     * Held as the live registries rather than as copied values, because the legal form is set by
     * `setEntityProfile` **after** the ledger exists — a snapshot taken here would leave every
     * conditional rule dormant for the life of the tenant.
     */
    private readonly legalForms: LegalFormRegistry | null = null,
    private readonly taxProfile: TaxProfile | null = null,
  ) {
    this.auditWriter = new AuditWriter(audit, clock, ids);
    this.settlements = new SettlementService(baseCurrency, accounts, journal, openItems, this.auditWriter);
    this.chart = new ChartAdminService(accounts, ids, this.auditWriter, dimensions, tenantId, configStore);
    this.periods = new FiscalPeriodService(fiscalYears, journal, ids, this.auditWriter);
  }

  /**
   * The dimension registry this ledger validates against — so `tenantConfiguration` can report what
   * a posting will be measured by. Read-only access: declaring a type or a value goes through
   * `defineDimensionType`/`defineDimensionValue`, which audit and store the change.
   */
  /** What `tenantConfiguration` reports, same reason as the dimension rules. */
  /**
   * The tenant facts a conditional constraint rule is measured against (`appliesWhen`).
   *
   * Read fresh on every posting, never cached: `setEntityProfile` may run at any point in a
   * tenant's life, and a rule that only applies to tenants configured before the first posting
   * would be a rule nobody could rely on. Absent stays absent — an unset fact makes a rule keyed on
   * it dormant rather than failing the posting, which is argued where the matching is done.
   */
  private constraintContext(): ConstraintContext {
    return {
      legalForm: this.legalForms?.declared()?.legalForm ?? null,
      taxationMethod: this.taxProfile?.taxationMethod() ?? null,
    };
  }

  combinationRegistry(): AccountCombinationRegistry {
    return this.combinations;
  }

  dimensionRegistry(): DimensionRegistry {
    return this.dimensions;
  }

  /**
   * What a posting's creation records (F-CORE-014).
   *
   * A creation is a change from nothing, written as `from: null` rather than as an empty diff — the
   * idiom vouchers, fiscal years and dimensions already used, and which postings did not, so the
   * published invariant held for some records and not for others.
   *
   * **The lines are deliberately not copied here.** A finalized entry cannot change and an entered
   * one records its own change under `corrected`, so the journal already holds what the entry is;
   * duplicating the lines would double the largest table in the system and create a second answer
   * to what the posting says. What the trail adds is the frame a reader cannot reconstruct: when it
   * was booked, against which voucher, under which text.
   */
  private static entryCreationDiff(entry: JournalEntry): Record<string, { from: unknown; to: unknown }> {
    return {
      entryDate: { from: null, to: entry.entryDate.iso },
      voucherId: { from: null, to: entry.voucherId.value },
      text: { from: null, to: entry.text() },
    };
  }

  post(input: Record<string, unknown>): PostResult {
    const actor = this.auditWriter.actorOf(input);

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
    const entryDate = parseEntryDate(input.entryDate);
    const [fiscalYear, period] = this.openPeriodFor(entryDate);

    // 4b. The accounts' validity window, which can only be judged once the date is known —
    // deliberately AFTER the period check, so no input that is refused today changes its code.
    this.assertAccountsValidOn(lines, entryDate);

    const text = asString(input.text) ?? '';

    const entry = new JournalEntry(
      this.ids.next(),
      this.journal.nextSequenceNumber(fiscalYear.year),
      entryDate,
      voucher.voucherDate,
      this.auditWriter.now(),
      new PeriodRef(fiscalYear.year, period.number),
      voucher.id,
      text,
      lines,
    );

    this.journal.append(entry);
    this.auditWriter.record(actor, 'journalEntry', entry.id, 'created', Ledger.entryCreationDiff(entry));

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

  correct(input: Record<string, unknown>): JournalEntry {
    const actor = this.auditWriter.actorOf(input);
    const entry = requireEntry(this.journal, input.entryId);
    const changes: AuditChanges = {};

    // Reading both fields leniently made every unrecognized field a silent no-op that still
    // returned the entry as a SUCCESS payload: `txt` instead of `text` looked like a correction
    // that had happened. A correction that changes nothing was never asked for — say so instead
    // of confirming a change nobody made.
    const hasText = input.text !== undefined && input.text !== null;
    const hasLines = input.lines !== undefined && input.lines !== null;
    if (!hasText && !hasLines) {
      throw new DomainError('E_INPUT_INVALID', 'correct requires "text" or "lines" — nothing to change', {
        fields: Object.keys(input).sort().join(','),
      });
    }
    if (hasText && typeof input.text !== 'string') {
      throw new DomainError('E_INPUT_INVALID', 'correct: "text" must be a string');
    }
    if (hasLines && !Array.isArray(input.lines)) {
      throw new DomainError('E_INPUT_INVALID', 'correct: "lines" must be an array');
    }

    const text = asString(input.text);
    if (text !== null && text !== entry.text()) {
      changes.text = { from: entry.text(), to: text };
      entry.changeText(text);
    }

    if (Array.isArray(input.lines)) {
      // Rewriting the lines used to leave the open items derived from them untouched, so the
      // subledger went on naming an amount, an account and a due date from a posting that no
      // longer existed — the same silent split between ledger and subledger as R-1, from the
      // other side. The text stays correctable; for amounts the GoBD-conform path is reversal
      // and a fresh posting, which keeps both books together.
      if (this.openItems.byOriginEntry(entry.id).length > 0) {
        throw new DomainError(
          'E_ENTRY_HAS_OPEN_ITEMS',
          'correct: this entry produced open items — correct the text, or reverse and post anew',
          { entryId: entry.id.value },
        );
      }

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
      // The corrected entry keeps its own date, so that is the date the window is judged against —
      // a correction must not be able to move a posting onto an account that was not open for
      // business when the posting happened.
      this.assertAccountsValidOn(lines, entry.entryDate);

      changes.lines = {
        from: entry.lines().map((line) => line.toJSON()),
        to: lines.map((line) => line.toJSON()),
      };
      entry.changeLines(lines);
    }

    if (Object.keys(changes).length > 0) {
      this.journal.save(entry);
      this.auditWriter.record(actor, 'journalEntry', entry.id, 'corrected', changes);
    } else {
      // Status check even without an effective change (E_ENTRY_FINALIZED).
      entry.changeText(entry.text());
    }

    return entry;
  }

  finalize(input: Record<string, unknown>): number {
    const actor = this.auditWriter.actorOf(input);

    if (input.entryId !== undefined) {
      const entry = requireEntry(this.journal, input.entryId);
      if (entry.isFinalized()) return 0;
      entry.finalize();
      this.journal.save(entry);
      this.auditWriter.record(actor, 'journalEntry', entry.id, 'finalized', {
        status: { from: 'entered', to: 'finalized' },
      });
      return 1;
    }

    const until = input.finalizeUntil;
    if (typeof until !== 'string') {
      throw new DomainError('E_ENTRY_UNKNOWN', 'finalize needs entryId or finalizeUntil');
    }
    const untilDate = parseEntryDate(until);
    let count = 0;

    for (const entry of this.journal.all()) {
      if (entry.isFinalized() || entry.entryDate.isAfter(untilDate)) continue;
      entry.finalize();
      this.journal.save(entry);
      this.auditWriter.record(actor, 'journalEntry', entry.id, 'finalized', {
        status: { from: 'entered', to: 'finalized' },
      });
      count++;
    }
    return count;
  }

  reverse(input: Record<string, unknown>): JournalEntry {
    const actor = this.auditWriter.actorOf(input);
    const original = requireEntry(this.journal, input.entryId);

    if (original.reversedBy() !== null) {
      throw new DomainError('E_ENTRY_ALREADY_REVERSED', `Posting ${original.id.value} is already reversed`, {
        entryId: original.id.value,
      });
    }

    // IMPL-008: a reversal clears the open items the reversed entry produced — but only while they
    // are untouched. Once one carries a settlement, money has actually moved, and cancelling the
    // item would drop that movement out of the open-item history while the ledger keeps it. The
    // line SAP draws with F5308: undo the settlement first, or post a credit note.
    const items = this.openItems.byOriginEntry(original.id);
    const settled = items.filter((item) => item.settlements().length > 0);
    if (settled.length > 0) {
      throw new DomainError(
        'E_ENTRY_HAS_SETTLED_ITEMS',
        'reverse: an open item of this entry is already settled — undo the settlement or post a credit note instead',
        { entryId: original.id.value, openItemId: settled[0]!.id.value },
      );
    }

    const entryDate = parseEntryDate(input.entryDate);
    const [fiscalYear, period] = this.openPeriodFor(entryDate);
    const text = asString(input.text) ?? `Reversal ${original.sequenceNumber}`;

    // A reversal may carry its own voucher. It used to inherit the reversed entry's one
    // unconditionally and drop any `voucherId` in the input without a word — so a caller who
    // supplied a cancellation document got no error, no hint, and a posting pointing at the wrong
    // paper. Inheriting stays the default, because a reversal without its own document is a normal
    // case and no posting may be voucher-less; supplying one is now honoured, and an unknown id
    // fails like everywhere else (E_VOUCHER_UNKNOWN).
    const reversalVoucherId =
      input.voucherId === null || input.voucherId === undefined
        ? original.voucherId
        : this.requireVoucher(input.voucherId).id;

    const reversal = new JournalEntry(
      this.ids.next(),
      this.journal.nextSequenceNumber(fiscalYear.year),
      entryDate,
      original.voucherDate,
      this.auditWriter.now(),
      new PeriodRef(fiscalYear.year, period.number),
      reversalVoucherId,
      text,
      original.lines().map((line) => line.negated()),
      original.id,
    );

    original.markReversed(reversal.id);
    this.journal.append(reversal);
    this.journal.save(original);

    this.auditWriter.record(actor, 'journalEntry', reversal.id, 'created', Ledger.entryCreationDiff(reversal));
    this.auditWriter.record(actor, 'journalEntry', original.id, 'reversed', {
      reversedBy: { from: null, to: reversal.id.value },
    });

    // Clear each untouched open item against the reversal. Nothing is deleted — the item keeps its
    // record and gains a settlement marked `cancellation`, which is what tells a reader (and the
    // cash-basis VAT return) that this was a reversal and not an incoming payment.
    for (const item of items) {
      item.settle(new Settlement(reversal.id, item.remaining(), entryDate, null, null, 'cancellation'));
      this.openItems.save(item);
      this.auditWriter.record(actor, 'openItem', item.id, 'cancelled', {
        cancelledBy: { from: null, to: reversal.id.value },
      });
    }

    return reversal;
  }

  // ---- facade: settlement, chart of accounts, fiscal years -------------

  settle(input: Record<string, unknown>): OpenItem[] {
    return this.settlements.settle(input);
  }

  createAccount(input: Record<string, unknown>): Account {
    return this.chart.createAccount(input);
  }

  defineDimensionType(input: Record<string, unknown>): Record<string, unknown> {
    return this.chart.defineDimensionType(input);
  }

  defineDimensionValue(input: Record<string, unknown>): Record<string, unknown> {
    return this.chart.defineDimensionValue(input);
  }

  lockAccount(input: Record<string, unknown>): Account {
    return this.chart.lockAccount(input);
  }

  unlockAccount(input: Record<string, unknown>): Account {
    return this.chart.unlockAccount(input);
  }

  importChartOfAccounts(input: Record<string, unknown>): number {
    return this.chart.importChartOfAccounts(input);
  }

  createFiscalYear(input: Record<string, unknown>): FiscalYear {
    return this.periods.createFiscalYear(input);
  }

  closePeriod(input: Record<string, unknown>): { fiscalYear: number; period: number; status: string } {
    return this.periods.closePeriod(input);
  }

  reopenPeriod(input: Record<string, unknown>): { fiscalYear: number; period: number; status: string } {
    return this.periods.reopenPeriod(input);
  }

  closeFiscalYear(input: Record<string, unknown>): FiscalYear {
    return this.periods.closeFiscalYear(input);
  }

  // ---- internal --------------------------------------------------------

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

    // A caller-supplied taxTag must name a REGISTERED tax code. The VAT return is built
    // from these tags, never from account numbers, so an unvalidated tag writes straight
    // into statutory output: `post` used to accept `{"code":"MADEUP","reportingKey":"4711"}`
    // and the invented key showed up as a line of the return. `postVoucher` always went
    // through the registry; the direct `post` path did not.
    const taxTag = isRecord(rawLine.taxTag) ? rawLine.taxTag : null;
    if (taxTag !== null && typeof taxTag.code === 'string' && taxTag.code !== '') {
      this.taxCodes.get(taxTag.code);
    }

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
    // Over the whole entry, after the per-line checks: a combination is not a property of any one
    // line, which is why it could not have been a second rule inside the dimension registry.
    this.combinations.validateEntry(lines.map((line) => line.account), this.constraintContext());
    return lines;
  }

  /**
   * An account may be posted to only inside its validity window (F-CORE-045).
   *
   * Judged against the ENTRY DATE, not against today: that is the whole difference from a lock. An
   * account retired at a year end keeps taking a late correction for December and refuses January,
   * which is what retiring an account actually means; a lock would refuse both.
   */
  private assertAccountsValidOn(lines: EntryLine[], entryDate: CalendarDate): void {
    for (const line of lines) {
      const account = this.accounts.byId(line.accountId);
      if (account === null || account.isValidOn(entryDate)) continue;

      throw new DomainError(
        'E_ACCOUNT_NOT_VALID_AT_DATE',
        `Account ${account.number.value} may not be posted to on ${entryDate.iso}`,
        {
          number: account.number.value,
          entryDate: entryDate.iso,
          validFrom: account.validFrom?.iso ?? null,
          validTo: account.validTo?.iso ?? null,
        },
      );
    }
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
}
