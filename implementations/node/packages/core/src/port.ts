import type { AccountNumber } from './substrate/account-number.js';
import type { CalendarDate } from './substrate/calendar-date.js';
import type { Uuid } from './substrate/uuid.js';
import type { Asset } from './policies/expansion/assets/asset.js';
import type { Partner } from './partner/partner.js';
import type { Account } from './substrate/account.js';
import type { AuditRecord } from './records/audit-record.js';
import type { FiscalYear } from './substrate/fiscal-year.js';
import type { JournalEntry } from './substrate/journal-entry.js';
import type { OpenItem } from './records/open-item.js';
import type { Voucher } from './records/voucher.js';

/** Account numbers unique per tenant — the adapter MUST guarantee that. */
export interface AccountRepository {
  add(account: Account): void;
  save(account: Account): void;
  byNumber(number: AccountNumber): Account | null;
  byId(id: Uuid): Account | null;
  /** sorted by account number (codepoints) */
  all(): Account[];
}

export interface FiscalYearRepository {
  add(fiscalYear: FiscalYear): void;
  save(fiscalYear: FiscalYear): void;
  byYear(year: number): FiscalYear | null;
  forDate(date: CalendarDate): FiscalYear | null;
  /** sorted by year */
  all(): FiscalYear[];
}

/**
 * Journal: append-only, gapless sequenceNumber per fiscal year. `save`
 * persists status changes; the entry itself is never deleted.
 */
export interface JournalRepository {
  append(entry: JournalEntry): void;
  save(entry: JournalEntry): void;
  byId(id: Uuid): JournalEntry | null;
  nextSequenceNumber(fiscalYear: number): number;
  /** sorted by (fiscalYear, sequenceNumber) */
  all(): JournalEntry[];
  /** sorted by sequenceNumber */
  forFiscalYear(fiscalYear: number): JournalEntry[];
}

export interface VoucherRepository {
  add(voucher: Voucher): void;
  byId(id: Uuid): Voucher | null;
  /** sorted by ID */
  all(): Voucher[];
}

export interface OpenItemRepository {
  add(item: OpenItem): void;
  save(item: OpenItem): void;
  byId(id: Uuid): OpenItem | null;
  /** items that arose from this posting */
  byOriginEntry(entryId: Uuid): OpenItem[];
  /** in creation order */
  all(): OpenItem[];
}

/** The audit trail is part of the format (datenformat.md v0.3): append-only. */
export interface AuditTrail {
  append(record: AuditRecord): void;
  /** in capture order */
  all(): AuditRecord[];
}

export interface AssetRepository {
  add(asset: Asset): void;
  save(asset: Asset): void;
  byId(id: Uuid): Asset | null;
  /** in acquisition order */
  all(): Asset[];
}

export interface PartnerRepository {
  add(partner: Partner): void;
  save(partner: Partner): void;
  byId(id: Uuid): Partner | null;
  /** sorted by name, then ID */
  all(): Partner[];
}
