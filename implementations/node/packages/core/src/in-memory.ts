import type {
  AccountRepository,
  AssetRepository,
  AuditTrail,
  FiscalYearRepository,
  JournalRepository,
  OpenItemRepository,
  PartnerRepository,
  VoucherRepository,
} from './port.js';
import type { Asset } from './assets/asset.js';
import type { Partner } from './partner/partner.js';
import type { AccountNumber } from './shared/account-number.js';
import type { CalendarDate } from './shared/calendar-date.js';
import type { Uuid } from './shared/uuid.js';
import type { Account } from './ledger/account.js';
import type { AuditRecord } from './ledger/audit-record.js';
import type { FiscalYear } from './ledger/fiscal-year.js';
import type { JournalEntry } from './ledger/journal-entry.js';
import type { OpenItem } from './ledger/open-item.js';
import type { Voucher } from './ledger/voucher.js';

export class InMemoryAccountRepository implements AccountRepository {
  private readonly byNumberMap = new Map<string, Account>();
  private readonly byIdMap = new Map<string, Account>();

  add(account: Account): void {
    if (this.byNumberMap.has(account.number.value)) {
      throw new Error(`Repository-Kontrakt verletzt: Kontonummer ${account.number.value} doppelt`);
    }
    this.byNumberMap.set(account.number.value, account);
    this.byIdMap.set(account.id.value, account);
  }

  save(_account: Account): void {
    // In-Memory: Objektidentität genügt.
  }

  byNumber(number: AccountNumber): Account | null {
    return this.byNumberMap.get(number.value) ?? null;
  }

  byId(id: Uuid): Account | null {
    return this.byIdMap.get(id.value) ?? null;
  }

  all(): Account[] {
    return [...this.byNumberMap.values()].sort((a, b) => a.number.compareTo(b.number));
  }
}

export class InMemoryFiscalYearRepository implements FiscalYearRepository {
  private readonly byYearMap = new Map<number, FiscalYear>();

  add(fiscalYear: FiscalYear): void {
    this.byYearMap.set(fiscalYear.year, fiscalYear);
  }

  save(_fiscalYear: FiscalYear): void {}

  byYear(year: number): FiscalYear | null {
    return this.byYearMap.get(year) ?? null;
  }

  forDate(date: CalendarDate): FiscalYear | null {
    for (const fiscalYear of this.byYearMap.values()) {
      if (fiscalYear.contains(date)) return fiscalYear;
    }
    return null;
  }

  all(): FiscalYear[] {
    return [...this.byYearMap.values()].sort((a, b) => a.year - b.year);
  }
}

export class InMemoryJournalRepository implements JournalRepository {
  private readonly entries: JournalEntry[] = [];
  private readonly byIdMap = new Map<string, JournalEntry>();
  private readonly sequences = new Map<number, number>();

  append(entry: JournalEntry): void {
    this.entries.push(entry);
    this.byIdMap.set(entry.id.value, entry);
    this.sequences.set(entry.periodRef.fiscalYear, entry.sequenceNumber);
  }

  save(_entry: JournalEntry): void {}

  byId(id: Uuid): JournalEntry | null {
    return this.byIdMap.get(id.value) ?? null;
  }

  nextSequenceNumber(fiscalYear: number): number {
    return (this.sequences.get(fiscalYear) ?? 0) + 1;
  }

  all(): JournalEntry[] {
    return [...this.entries].sort((a, b) =>
      a.periodRef.fiscalYear !== b.periodRef.fiscalYear
        ? a.periodRef.fiscalYear - b.periodRef.fiscalYear
        : a.sequenceNumber - b.sequenceNumber,
    );
  }

  forFiscalYear(fiscalYear: number): JournalEntry[] {
    return this.entries
      .filter((entry) => entry.periodRef.fiscalYear === fiscalYear)
      .sort((a, b) => a.sequenceNumber - b.sequenceNumber);
  }
}

export class InMemoryVoucherRepository implements VoucherRepository {
  private readonly byIdMap = new Map<string, Voucher>();

  add(voucher: Voucher): void {
    this.byIdMap.set(voucher.id.value, voucher);
  }

  byId(id: Uuid): Voucher | null {
    return this.byIdMap.get(id.value) ?? null;
  }

  all(): Voucher[] {
    return [...this.byIdMap.values()].sort((a, b) =>
      a.id.value < b.id.value ? -1 : a.id.value > b.id.value ? 1 : 0,
    );
  }
}

export class InMemoryOpenItemRepository implements OpenItemRepository {
  private readonly items: OpenItem[] = [];
  private readonly byIdMap = new Map<string, OpenItem>();

  add(item: OpenItem): void {
    this.items.push(item);
    this.byIdMap.set(item.id.value, item);
  }

  save(_item: OpenItem): void {}

  byId(id: Uuid): OpenItem | null {
    return this.byIdMap.get(id.value) ?? null;
  }

  byOriginEntry(entryId: Uuid): OpenItem[] {
    return this.items.filter((item) => item.originEntryId.equals(entryId));
  }

  all(): OpenItem[] {
    return [...this.items];
  }
}

export class InMemoryAuditTrail implements AuditTrail {
  private readonly records: AuditRecord[] = [];

  append(record: AuditRecord): void {
    this.records.push(record);
  }

  all(): AuditRecord[] {
    return [...this.records];
  }
}

export class InMemoryPartnerRepository implements PartnerRepository {
  private readonly byIdMap = new Map<string, Partner>();

  add(partner: Partner): void {
    this.byIdMap.set(partner.id.value, partner);
  }

  save(_partner: Partner): void {}

  byId(id: Uuid): Partner | null {
    return this.byIdMap.get(id.value) ?? null;
  }

  all(): Partner[] {
    return [...this.byIdMap.values()].sort((a, b) => {
      const byName = a.name() < b.name() ? -1 : a.name() > b.name() ? 1 : 0;
      return byName !== 0 ? byName : a.id.value < b.id.value ? -1 : a.id.value > b.id.value ? 1 : 0;
    });
  }
}

export class InMemoryAssetRepository implements AssetRepository {
  private readonly items: Asset[] = [];
  private readonly byIdMap = new Map<string, Asset>();

  add(asset: Asset): void {
    this.items.push(asset);
    this.byIdMap.set(asset.id.value, asset);
  }

  save(_asset: Asset): void {}

  byId(id: Uuid): Asset | null {
    return this.byIdMap.get(id.value) ?? null;
  }

  all(): Asset[] {
    return [...this.items];
  }
}
