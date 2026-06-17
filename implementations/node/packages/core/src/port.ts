import type { AccountNumber } from './shared/account-number.js';
import type { CalendarDate } from './shared/calendar-date.js';
import type { Uuid } from './shared/uuid.js';
import type { Account } from './ledger/account.js';
import type { AuditRecord } from './ledger/audit-record.js';
import type { FiscalYear } from './ledger/fiscal-year.js';
import type { JournalEntry } from './ledger/journal-entry.js';
import type { Voucher } from './ledger/voucher.js';

/** Kontonummern je Mandant eindeutig — der Adapter MUSS das zusichern. */
export interface AccountRepository {
  add(account: Account): void;
  save(account: Account): void;
  byNumber(number: AccountNumber): Account | null;
  byId(id: Uuid): Account | null;
  /** sortiert nach Kontonummer (Codepoints) */
  all(): Account[];
}

export interface FiscalYearRepository {
  add(fiscalYear: FiscalYear): void;
  save(fiscalYear: FiscalYear): void;
  byYear(year: number): FiscalYear | null;
  forDate(date: CalendarDate): FiscalYear | null;
  /** sortiert nach Jahr */
  all(): FiscalYear[];
}

/**
 * Journal: append-only, lückenlose sequenceNumber je Geschäftsjahr. `save`
 * persistiert Statuswechsel; der Eintrag selbst wird nie gelöscht.
 */
export interface JournalRepository {
  append(entry: JournalEntry): void;
  save(entry: JournalEntry): void;
  byId(id: Uuid): JournalEntry | null;
  nextSequenceNumber(fiscalYear: number): number;
  /** sortiert nach (fiscalYear, sequenceNumber) */
  all(): JournalEntry[];
  /** sortiert nach sequenceNumber */
  forFiscalYear(fiscalYear: number): JournalEntry[];
}

export interface VoucherRepository {
  add(voucher: Voucher): void;
  byId(id: Uuid): Voucher | null;
  /** sortiert nach ID */
  all(): Voucher[];
}

/** Audit-Trail ist Formatbestandteil (datenformat.md v0.3): append-only. */
export interface AuditTrail {
  append(record: AuditRecord): void;
  /** in Erfassungsreihenfolge */
  all(): AuditRecord[];
}
