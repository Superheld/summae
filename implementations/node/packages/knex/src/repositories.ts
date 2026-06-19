import {
  Account,
  AccountNumber,
  type AccountRepository,
  type AccountStatus,
  type AccountType,
  Asset,
  type AssetRepository,
  type AssetRoute,
  type AuditChanges,
  AuditRecord,
  type AuditTrail,
  CalendarDate,
  type EntryStatus,
  FiscalYear,
  type FiscalYearRepository,
  type FiscalYearStatus,
  JournalEntry,
  type JournalRepository,
  OpenItem,
  type OpenItemKind,
  type OpenItemRepository,
  Partner,
  type PartnerRepository,
  Period,
  PeriodRef,
  type PeriodStatus,
  Settlement,
  type SettlementDifferenceKind,
  Uuid,
  Voucher,
  type VoucherRepository,
} from '@superheld/summae-core';
import * as H from './hydrator.js';
import { TABLE_PREFIX } from './schema-installer.js';
import type { SyncDb } from './sync-db.js';

type Row = Record<string, unknown>;

function str(row: Row, key: string): string {
  const v = row[key];
  return typeof v === 'string' ? v : '';
}
function strOrNull(row: Row, key: string): string | null {
  const v = row[key];
  return typeof v === 'string' ? v : null;
}
function int(row: Row, key: string): number {
  const v = row[key];
  if (typeof v === 'number') return v;
  if (typeof v === 'bigint') return Number(v);
  if (typeof v === 'string') return Number.parseInt(v, 10);
  return 0;
}

/** Konten — flache Spalten (datenformat.md). Eindeutig je Mandant über (tenant, number). */
export class DatabaseAccountRepository implements AccountRepository {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  add(account: Account): void {
    this.db.run(
      this.table().insert({
        id: account.id.value,
        tenant_id: this.tenantId.value,
        number: account.number.value,
        name: account.name,
        type: account.type,
        subtype: account.subtype,
        status: account.status(),
      }),
    );
  }

  save(account: Account): void {
    this.db.run(this.table().where('id', account.id.value).update({ name: account.name, status: account.status() }));
  }

  byNumber(number: AccountNumber): Account | null {
    const row = this.db.first(this.table().where('tenant_id', this.tenantId.value).where('number', number.value));
    return row === null ? null : this.hydrate(row);
  }

  byId(id: Uuid): Account | null {
    const row = this.db.first(this.table().where('id', id.value));
    return row === null ? null : this.hydrate(row);
  }

  all(): Account[] {
    return this.rows()
      .map((row) => this.hydrate(row))
      .sort((a, b) => a.number.compareTo(b.number));
  }

  private hydrate(row: Row): Account {
    return new Account(
      Uuid.fromString(str(row, 'id')),
      AccountNumber.of(str(row, 'number')),
      str(row, 'name'),
      str(row, 'type') as AccountType,
      strOrNull(row, 'subtype'),
      str(row, 'status') as AccountStatus,
    );
  }

  private rows(): Row[] {
    return this.db.all(this.table().where('tenant_id', this.tenantId.value).orderBy('rowid'));
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}accounts`);
  }
}

/** Geschäftsjahre — flache Spalten + Perioden als JSON. */
export class DatabaseFiscalYearRepository implements FiscalYearRepository {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  add(fiscalYear: FiscalYear): void {
    this.db.run(
      this.table().insert({
        id: fiscalYear.id.value,
        tenant_id: this.tenantId.value,
        year: fiscalYear.year,
        start: fiscalYear.start.iso,
        end: fiscalYear.end.iso,
        status: fiscalYear.status(),
        periods: this.encodePeriods(fiscalYear),
      }),
    );
  }

  save(fiscalYear: FiscalYear): void {
    this.db.run(
      this.table()
        .where('id', fiscalYear.id.value)
        .update({ status: fiscalYear.status(), periods: this.encodePeriods(fiscalYear) }),
    );
  }

  byYear(year: number): FiscalYear | null {
    const row = this.db.first(this.table().where('tenant_id', this.tenantId.value).where('year', year));
    return row === null ? null : this.hydrate(row);
  }

  forDate(date: CalendarDate): FiscalYear | null {
    for (const fiscalYear of this.all()) {
      if (fiscalYear.contains(date)) return fiscalYear;
    }
    return null;
  }

  all(): FiscalYear[] {
    return this.db
      .all(this.table().where('tenant_id', this.tenantId.value).orderBy('rowid'))
      .map((row) => this.hydrate(row))
      .sort((a, b) => a.year - b.year);
  }

  private encodePeriods(fiscalYear: FiscalYear): string {
    return H.encode(
      fiscalYear.periods().map((period) => ({
        period: period.number,
        start: period.start.iso,
        end: period.end.iso,
        status: period.status(),
      })),
    );
  }

  private hydrate(row: Row): FiscalYear {
    const periods = H.decodeList(row.periods).map(
      (p) =>
        new Period(
          int(p, 'period'),
          H.requireDate(p.start, 'Periodenstart'),
          H.requireDate(p.end, 'Periodenende'),
          str(p, 'status') as PeriodStatus,
        ),
    );
    return FiscalYear.restore(
      Uuid.fromString(str(row, 'id')),
      int(row, 'year'),
      H.requireDate(row.start, 'start'),
      H.requireDate(row.end, 'end'),
      str(row, 'status') as FiscalYearStatus,
      periods,
    );
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}fiscal_years`);
  }
}

/** Journal — append-only; `save` ändert nur Status/Text/Zeilen/Storno-Verweis. */
export class DatabaseJournalRepository implements JournalRepository {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  append(entry: JournalEntry): void {
    this.db.run(
      this.table().insert({
        id: entry.id.value,
        tenant_id: this.tenantId.value,
        fiscal_year: entry.periodRef.fiscalYear,
        sequence_number: entry.sequenceNumber,
        period: entry.periodRef.period,
        status: entry.status(),
        entry_date: entry.entryDate.iso,
        voucher_date: entry.voucherDate?.iso ?? null,
        recorded_at: entry.recordedAt,
        voucher_id: entry.voucherId.value,
        text: entry.text(),
        lines: this.encodeLines(entry),
        reverses: entry.reverses?.value ?? null,
        reversed_by: entry.reversedBy()?.value ?? null,
      }),
    );
  }

  save(entry: JournalEntry): void {
    this.db.run(
      this.table()
        .where('id', entry.id.value)
        .update({
          status: entry.status(),
          text: entry.text(),
          lines: this.encodeLines(entry),
          reversed_by: entry.reversedBy()?.value ?? null,
        }),
    );
  }

  byId(id: Uuid): JournalEntry | null {
    const row = this.db.first(this.table().where('id', id.value));
    return row === null ? null : this.hydrate(row);
  }

  nextSequenceNumber(fiscalYear: number): number {
    const rows = this.db.all(
      this.table().where('tenant_id', this.tenantId.value).where('fiscal_year', fiscalYear).max('sequence_number as max'),
    );
    const max = rows[0]?.max;
    return (typeof max === 'number' ? max : typeof max === 'bigint' ? Number(max) : 0) + 1;
  }

  all(): JournalEntry[] {
    return this.db
      .all(this.table().where('tenant_id', this.tenantId.value).orderBy('rowid'))
      .map((row) => this.hydrate(row))
      .sort((a, b) =>
        a.periodRef.fiscalYear !== b.periodRef.fiscalYear
          ? a.periodRef.fiscalYear - b.periodRef.fiscalYear
          : a.sequenceNumber - b.sequenceNumber,
      );
  }

  forFiscalYear(fiscalYear: number): JournalEntry[] {
    return this.db
      .all(this.table().where('tenant_id', this.tenantId.value).where('fiscal_year', fiscalYear).orderBy('rowid'))
      .map((row) => this.hydrate(row))
      .sort((a, b) => a.sequenceNumber - b.sequenceNumber);
  }

  private encodeLines(entry: JournalEntry): string {
    return H.encode(entry.lines().map((line) => line.toJSON()));
  }

  private hydrate(row: Row): JournalEntry {
    return new JournalEntry(
      Uuid.fromString(str(row, 'id')),
      int(row, 'sequence_number'),
      H.requireDate(row.entry_date, 'entry_date'),
      H.date(row.voucher_date),
      str(row, 'recorded_at'),
      new PeriodRef(int(row, 'fiscal_year'), int(row, 'period')),
      Uuid.fromString(str(row, 'voucher_id')),
      str(row, 'text'),
      H.entryLines(H.decodeList(row.lines)),
      strOrNull(row, 'reverses') === null ? null : Uuid.fromString(str(row, 'reverses')),
      strOrNull(row, 'reversed_by') === null ? null : Uuid.fromString(str(row, 'reversed_by')),
      str(row, 'status') as EntryStatus,
    );
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}journal_entries`);
  }
}

/** Belege — Payload als JSON. */
export class DatabaseVoucherRepository implements VoucherRepository {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  add(voucher: Voucher): void {
    this.db.run(
      this.table().insert({ id: voucher.id.value, tenant_id: this.tenantId.value, payload: H.encode(voucher.toJSON()) }),
    );
  }

  byId(id: Uuid): Voucher | null {
    const row = this.db.first(this.table().where('id', id.value));
    return row === null ? null : this.hydrate(row);
  }

  all(): Voucher[] {
    return this.db
      .all(this.table().where('tenant_id', this.tenantId.value).orderBy('rowid'))
      .map((row) => this.hydrate(row))
      .sort((a, b) => (a.id.value < b.id.value ? -1 : a.id.value > b.id.value ? 1 : 0));
  }

  private hydrate(row: Row): Voucher {
    const data = H.decode(row.payload);
    const servicePeriod = H.isRecord(data.servicePeriod) ? data.servicePeriod : {};
    return new Voucher({
      id: Uuid.fromString(str(row, 'id')),
      voucherNumber: str(data, 'voucherNumber'),
      voucherDate: H.requireDate(data.voucherDate, 'voucherDate'),
      due: H.date(data.due),
      recurring: data.recurring === true,
      economicYear: typeof data.economicYear === 'number' ? data.economicYear : null,
      supplierTaxationMethod: strOrNull(data, 'supplierTaxationMethod'),
      serviceDate: H.date(data.serviceDate),
      servicePeriodFrom: H.date(servicePeriod.from),
      servicePeriodTo: H.date(servicePeriod.to),
      kind: strOrNull(data, 'kind'),
      partnerId: typeof data.partnerId === 'string' ? Uuid.fromString(data.partnerId) : null,
      issuer: strOrNull(data, 'issuer'),
    });
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}vouchers`);
  }
}

/** Offene Posten — flache Spalten + Ausgleiche als JSON. */
export class DatabaseOpenItemRepository implements OpenItemRepository {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  add(item: OpenItem): void {
    this.db.run(
      this.table().insert({
        id: item.id.value,
        tenant_id: this.tenantId.value,
        kind: item.kind,
        origin_entry_id: item.originEntryId.value,
        origin_line_index: item.originLineIndex,
        amount: item.money.amountAsString(),
        currency: item.money.currency.code,
        voucher_id: item.voucherId.value,
        opened_at: item.openedAt.iso,
        partner_id: item.partnerId?.value ?? null,
        settlements: this.encodeSettlements(item),
      }),
    );
  }

  save(item: OpenItem): void {
    this.db.run(this.table().where('id', item.id.value).update({ settlements: this.encodeSettlements(item) }));
  }

  byId(id: Uuid): OpenItem | null {
    const row = this.db.first(this.table().where('id', id.value));
    return row === null ? null : this.hydrate(row);
  }

  byOriginEntry(entryId: Uuid): OpenItem[] {
    return this.db
      .all(this.table().where('origin_entry_id', entryId.value).orderBy('rowid'))
      .map((row) => this.hydrate(row));
  }

  all(): OpenItem[] {
    return this.db.all(this.table().where('tenant_id', this.tenantId.value).orderBy('rowid')).map((row) => this.hydrate(row));
  }

  private encodeSettlements(item: OpenItem): string {
    return H.encode(item.settlements().map((settlement) => settlement.toJSON()));
  }

  private hydrate(row: Row): OpenItem {
    const settlements = H.decodeList(row.settlements).map((data) => {
      const difference = H.isRecord(data.difference) ? data.difference : null;
      const differenceMoney = difference !== null && H.isRecord(difference.money) ? H.money(difference.money) : null;
      const differenceKind =
        difference !== null && typeof difference.kind === 'string'
          ? (difference.kind as SettlementDifferenceKind)
          : null;
      return new Settlement(
        Uuid.fromString(str(data, 'entryId')),
        H.money(H.isRecord(data.money) ? data.money : {}),
        H.requireDate(data.settledAt, 'settledAt'),
        differenceMoney,
        differenceKind,
      );
    });
    return OpenItem.restore(
      Uuid.fromString(str(row, 'id')),
      str(row, 'kind') as OpenItemKind,
      Uuid.fromString(str(row, 'origin_entry_id')),
      int(row, 'origin_line_index'),
      H.money({ amount: str(row, 'amount'), currency: str(row, 'currency') }),
      Uuid.fromString(str(row, 'voucher_id')),
      H.requireDate(row.opened_at, 'opened_at'),
      strOrNull(row, 'partner_id') === null ? null : Uuid.fromString(str(row, 'partner_id')),
      settlements,
    );
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}open_items`);
  }
}

/** Geschäftspartner — Payload als JSON. */
export class DatabasePartnerRepository implements PartnerRepository {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  add(partner: Partner): void {
    this.db.run(
      this.table().insert({ id: partner.id.value, tenant_id: this.tenantId.value, payload: H.encode(partner.toJSON()) }),
    );
  }

  save(partner: Partner): void {
    this.db.run(this.table().where('id', partner.id.value).update({ payload: H.encode(partner.toJSON()) }));
  }

  byId(id: Uuid): Partner | null {
    const row = this.db.first(this.table().where('id', id.value));
    return row === null ? null : this.hydrate(row);
  }

  all(): Partner[] {
    return this.db
      .all(this.table().where('tenant_id', this.tenantId.value).orderBy('rowid'))
      .map((row) => this.hydrate(row))
      .sort((a, b) => {
        const byName = a.name() < b.name() ? -1 : a.name() > b.name() ? 1 : 0;
        return byName !== 0 ? byName : a.id.value < b.id.value ? -1 : a.id.value > b.id.value ? 1 : 0;
      });
  }

  private hydrate(row: Row): Partner {
    const data = H.decode(row.payload);
    const accountNumbers = (Array.isArray(data.accountNumbers) ? data.accountNumbers : []).filter(
      (n): n is string => typeof n === 'string',
    );
    const address = H.isRecord(data.address) ? data.address : {};
    return new Partner(
      Uuid.fromString(str(row, 'id')),
      str(data, 'name'),
      typeof data.kind === 'string' ? data.kind : 'both',
      strOrNull(data, 'vatId'),
      typeof data.paymentTermsDays === 'number' ? data.paymentTermsDays : null,
      accountNumbers,
      address,
    );
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}partners`);
  }
}

/** Anlagegüter — Stammdaten (payload) + AfA-Lebenslauf/Abgang (state) als JSON. */
export class DatabaseAssetRepository implements AssetRepository {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  add(asset: Asset): void {
    this.db.run(
      this.table().insert({
        id: asset.id.value,
        tenant_id: this.tenantId.value,
        payload: H.encode(this.payload(asset)),
        state: H.encode(this.state(asset)),
      }),
    );
  }

  save(asset: Asset): void {
    this.db.run(this.table().where('id', asset.id.value).update({ state: H.encode(this.state(asset)) }));
  }

  byId(id: Uuid): Asset | null {
    const row = this.db.first(this.table().where('id', id.value));
    return row === null ? null : this.hydrate(row);
  }

  all(): Asset[] {
    return this.db.all(this.table().where('tenant_id', this.tenantId.value).orderBy('rowid')).map((row) => this.hydrate(row));
  }

  private payload(asset: Asset): Record<string, unknown> {
    return { ...asset.toJSON(), monthlySchedule: asset.monthlySchedule.map((amount) => amount.toJSON()) };
  }

  private state(asset: Asset): Record<string, unknown> {
    return {
      disposed: asset.isDisposed(),
      disposedOn: asset.toJSON().disposedOn,
      accumulated: asset.accumulatedDepreciationAt(null).toJSON(),
      depreciations: asset.depreciationsForPersistence(),
    };
  }

  private hydrate(row: Row): Asset {
    const data = H.decode(row.payload);
    const state = H.decode(row.state);
    const schedule = (Array.isArray(data.monthlySchedule) ? data.monthlySchedule : [])
      .filter(H.isRecord)
      .map((amount) => H.money(amount));
    const depreciations = (Array.isArray(state.depreciations) ? state.depreciations : [])
      .filter(H.isRecord)
      .map((booking) => ({
        planMonth: int(booking, 'planMonth'),
        date: H.requireDate(booking.date, 'AfA-Datum'),
        amount: H.money(H.isRecord(booking.amount) ? booking.amount : {}),
        entryId: Uuid.fromString(str(booking, 'entryId')),
      }));
    return Asset.restore(
      Uuid.fromString(str(row, 'id')),
      str(data, 'name'),
      str(data, 'assetClass'),
      AccountNumber.of(str(data, 'assetAccount')),
      H.money(H.isRecord(data.acquisitionCost) ? data.acquisitionCost : {}),
      H.requireDate(data.acquiredOn, 'acquiredOn'),
      str(data, 'route') as AssetRoute,
      typeof data.usefulLifeMonths === 'number' ? data.usefulLifeMonths : null,
      schedule,
      Uuid.fromString(str(data, 'voucherId')),
      depreciations,
      state.disposed === true,
      H.date(state.disposedOn),
    );
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}assets`);
  }
}

/** Audit-Trail — append-only, Payload als JSON, Reihenfolge über `seq`. */
export class DatabaseAuditTrail implements AuditTrail {
  constructor(
    private readonly db: SyncDb,
    private readonly tenantId: Uuid,
  ) {}

  append(record: AuditRecord): void {
    this.db.run(
      this.table().insert({ id: record.id.value, tenant_id: this.tenantId.value, payload: H.encode(record.toJSON()) }),
    );
  }

  all(): AuditRecord[] {
    return this.db.all(this.table().where('tenant_id', this.tenantId.value).orderBy('seq')).map((row) => {
      const data = H.decode(row.payload);
      const changes = H.isRecord(data.changes) ? (data.changes as AuditChanges) : {};
      return new AuditRecord(
        Uuid.fromString(str(data, 'id')),
        str(data, 'at'),
        typeof data.actor === 'string' ? data.actor : 'system',
        str(data, 'objectType'),
        Uuid.fromString(str(data, 'objectId')),
        str(data, 'action'),
        changes,
      );
    });
  }

  private table() {
    return this.db.table(`${TABLE_PREFIX}audit_log`);
  }
}
