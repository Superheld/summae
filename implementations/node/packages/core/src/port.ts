import type { AccountNumber } from './substrate/account-number.js';
import type { CalendarDate } from './substrate/calendar-date.js';
import type { Uuid } from './substrate/uuid.js';
import type { Asset } from './policies/expansion/assets/asset.js';
import type { CostingRun } from './policies/expansion/costing/costing-run.js';
import type { InventoryValuation } from './policies/expansion/inventory/inventory-valuation.js';
import type { Deferral } from './policies/expansion/deferrals/deferral.js';
import type { Provision } from './policies/expansion/provisions/provision.js';
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
/**
 * Criteria for `AuditTrail.find` — AND-combined, an absent one filters nothing.
 *
 * `objectIds` is the set a page of postings needs; `from`/`to` are inclusive calendar dates on the
 * recording moment; an absent or negative `limit` means everything from the offset on, exactly as
 * on `journal`.
 */
export interface AuditCriteria {
  readonly objectType?: string | null;
  readonly objectId?: string | null;
  readonly objectIds?: readonly string[] | null;
  readonly actor?: string | null;
  readonly action?: string | null;
  readonly from?: string | null;
  readonly to?: string | null;
  readonly offset?: number | null;
  readonly limit?: number | null;
}

export interface AuditTrail {
  append(record: AuditRecord): void;
  /** in capture order */
  all(): AuditRecord[];
  /**
   * The part of the trail a question is about (SPEC-018).
   *
   * `all()` is honest and, past a certain size, the wrong tool: a trail is the fastest-growing table
   * in the system, and answering "what happened to this posting" by materialising ten years of
   * history to discard almost all of it makes the cost of a screen scale with the age of the books.
   * So the criteria travel to the store, which is the only place that can decline to read a row.
   *
   * `count` is the number of matches **before** paging, so a page header needs no second call.
   * Order stays capture order, which is the trail's own total order.
   */
  find(criteria: AuditCriteria): { records: AuditRecord[]; count: number };
  /**
   * Erase the trail's records about one object, and answer how many went (F-CORE-040).
   *
   * The one hole in "the trail is append-only because no code path deletes from it", and it is
   * opened deliberately and narrowly. Where a record has to be removable at all — the master data
   * a jurisdiction's privacy rules reach, never the books, which every retention rule protects —
   * removing the record alone removes nothing: `createPartner`'s own audit entry still holds the
   * name and the address in `changes`, so the data would only move to where nobody looks.
   *
   * It is reachable from exactly one operation (`erasePartner`), which refuses while any voucher
   * or open item names the partner. Nothing in the bookkeeping path can call it: the journal, the
   * entries and the trail's records about them stay untouchable, which is what GoBD asks for.
   */
  eraseFor(objectType: string, objectId: Uuid): number;
}

export interface AssetRepository {
  add(asset: Asset): void;
  save(asset: Asset): void;
  byId(id: Uuid): Asset | null;
  /** in acquisition order */
  all(): Asset[];
}

/**
 * Costing runs (F-KLR-001/004).
 *
 * The one repository that was missing for years, and its absence was not neutral: the service kept
 * its runs in a private `Map`, so a released run — the thing the requirements say evaluations read —
 * was gone with the process that produced it. `all()` is what gives a period its next version
 * number, which is also why the version no longer restarts at 1 after a restart.
 */
export interface CostingRunRepository {
  add(run: CostingRun): void;
  save(run: CostingRun): void;
  byId(id: Uuid): CostingRun | null;
  /** sorted by period, then version */
  all(): CostingRun[];
}

/**
 * Inventory valuations (F-CORE-050).
 *
 * The same shape as `CostingRunRepository`, and for the same reason it exists at all: a valuation
 * that lives in the process that made it cannot be read back, and the record of *how* a stock
 * figure was reached is precisely what an inventory has to be able to show. `all()` is sorted
 * because the next version of a period comes out of the store, not out of a counter.
 */
export interface InventoryValuationRepository {
  /**
   * Deliberately no `byId` and no `save`. A valuation is one act that never changes, and nothing
   * asks for a single one: the projection reports them all and the version counter reads them all.
   * An interface method nobody calls is a burden on every adapter author for a convenience the core
   * does not have — and it is the kind of thing that reads as "supported" long after it stopped
   * being exercised.
   */
  add(valuation: InventoryValuation): void;
  /** sorted by fiscal year, then period, then version */
  all(): InventoryValuation[];
}

/**
 * Provisions (F-CORE-051).
 *
 * Shaped like `AssetRepository`, because a provision is the same kind of thing an asset is: a record
 * with a life, whose movements matter as much as its balance. `save` exists here and not on the
 * inventory port for exactly that reason — a valuation is one act and never changes, a provision is
 * used, released and re-measured over years.
 */
/**
 * Prepaid and deferred items (F-CORE-053).
 *
 * Deliberately no `byId`: the release run reads them all and the register reports them all; nothing
 * in the core asks for a single deferral. An interface method nobody calls is a burden on every
 * adapter author for a convenience the core does not have.
 */
export interface DeferralRepository {
  add(deferral: Deferral): void;
  save(deferral: Deferral): void;
  /** in the order they were recognised */
  all(): Deferral[];
}

export interface ProvisionRepository {
  add(provision: Provision): void;
  save(provision: Provision): void;
  byId(id: Uuid): Provision | null;
  /** in the order they were recognised */
  all(): Provision[];
}

/**
 * What a tenant *is*, apart from its books (SPEC-015).
 *
 * Everything else in this file persists a record the books are made of. This one persists the
 * tenant itself — its identity and the configuration it was set up with — and it exists because
 * that configuration had no owner at all: the tax profile, the dimension registry, the allocation
 * scheme and the imported mappings were constructor arguments rebuilt from whatever the caller
 * passed on every open. Five operations changed them, wrote a durable audit record about it, and
 * lost the change with the process.
 *
 * The chart of accounts settles the question of whether this is the library's business: it is
 * seeded from the pack, stored per tenant, changed by operations and read back by a projection,
 * and nobody ever argued it belonged to the embedding. A cost centre is master data the same way
 * an account is.
 *
 * Scoped to one tenant like every other repository here, so `load` needs no argument. Listing the
 * tenants in a store is deliberately NOT part of this port: a repository here answers for the
 * tenant it was built for, and "which tenants exist" is a question about the store, which the
 * adapter answers (`listTenants`).
 */
export interface TenantRecordRepository {
  load(): TenantRecordData | null;
  save(record: TenantRecordData): void;
}

/**
 * The stored form. Deliberately raw data rather than the built objects: what goes in is exactly
 * what `TaxProfile.fromData`, `DimensionRegistry.fromData`, `setAllocationScheme` and
 * `importMapping` accept, so the round trip is symmetric by construction and no second serializer
 * can drift from the first one.
 */
export interface TenantRecordData {
  id: string;
  name: string;
  baseCurrency: string;
  packIdentity: { id: string; version: string } | null;
  config: {
    taxProfile: Record<string, unknown> | null;
    dimensionTypes: Array<{ code: string }>;
    dimensionValues: Array<{ typeCode: string; code: string }>;
    allocationScheme: Record<string, unknown> | null;
    mappings: Record<string, unknown>[];
    /** What the entity IS — legal form and, where a jurisdiction knows one, size class (F-CORE-039). */
    entityProfile: { legalForm: string; sizeClass: string | null } | null;
  };
}

export interface PartnerRepository {
  add(partner: Partner): void;
  save(partner: Partner): void;
  byId(id: Uuid): Partner | null;
  /** sorted by name, then ID */
  all(): Partner[];
  /**
   * Remove a partner outright (F-CORE-040) — the only repository in the core that can forget.
   *
   * Guarded by `PartnerService.erase`, never called from the bookkeeping path. See
   * `AuditTrail.eraseFor` for why the capability exists at all.
   */
  remove(id: Uuid): void;
}
