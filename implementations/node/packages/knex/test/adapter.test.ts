import {
  Account,
  AccountNumber,
  Asset,
  CalendarDate,
  Currency,
  Money,
  FixedClock,
  type Tenant,
  TenantOperations,
  Uuid,
  UuidV7IdGenerator,
} from '@superheld/summae-core';
import { beforeEach, describe, expect, it } from 'vitest';
import { DatabaseTenantFactory } from '../src/database-tenant-factory.js';
import { installSchema } from '../src/schema-installer.js';
import { SyncDb } from '../src/sync-db.js';

/**
 * IMPL-015 (Node twin of `packages/laravel/tests`): the persistence adapter gets its own suite.
 *
 * `packages/knex` had no test of its own either — its coverage came entirely from the CLI suite and
 * the cross test, both of which drive exactly one tenant per database and both of which write and
 * read inside one process. Neither can see a repository that ignores `tenant_id`, or a field that
 * never actually leaves the object graph. Both are checked here.
 *
 * PHP twin: `packages/laravel/tests/RepositoryRoundTripTest.php` + `TenantScopingTest.php`.
 */
const TENANT_A = '0195f000-0000-7000-8000-00000000aaaa';
const TENANT_B = '0195f000-0000-7000-8000-00000000bbbb';

let db: SyncDb;

beforeEach(() => {
  db = new SyncDb(':memory:');
  installSchema(db);
});

function tenantOn(tenantId: string, name = 'Adapter GmbH'): Tenant {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  // Real UUIDv7 rather than the deterministic generator: two tenants share one database here, and
  // the deterministic generator would hand both of them the same primary keys.
  return DatabaseTenantFactory.build(db, name, Currency.of('EUR'), clock, new UuidV7IdGenerator(clock), {
    tenantId: Uuid.fromString(tenantId),
  });
}

interface Ids extends Record<string, string> {
  accountId: string;
  voucherId: string;
  entryId: string;
  openItemId: string;
  partnerId: string;
}

function writeBooks(tenant: Tenant, label: string, revenueAccount: string): Ids {
  const ops = new TenantOperations(tenant);

  ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
  const account = ops.execute('createAccount', {
    number: '1400',
    name: 'Forderungen',
    type: 'asset',
    subtype: 'ar',
  }) as { id: string };
  ops.execute('createAccount', { number: revenueAccount, name: `Erlöse ${label}`, type: 'revenue' });
  const partner = ops.execute('createPartner', { name: `Kunde ${label}`, kind: 'customer' }) as { id: string };

  const voucher = ops.execute('createVoucher', {
    voucher: { voucherNumber: `AR-${label}`, voucherDate: '2026-03-01' },
  }) as { id: string };

  const entry = ops.execute('post', {
    entryDate: '2026-03-01',
    voucherId: voucher.id,
    text: `Rechnung ${label} mit Ümläuten`,
    lines: [
      { account: '1400', side: 'debit', money: { amount: '1190.00', currency: 'EUR' } },
      { account: revenueAccount, side: 'credit', money: { amount: '1190.00', currency: 'EUR' } },
    ],
  }) as { id: string; openItemsCreated: Array<{ id: string }> };

  const payVoucher = ops.execute('createVoucher', {
    voucher: { voucherNumber: `ZE-${label}`, voucherDate: '2026-03-15' },
  }) as { id: string };
  const payment = ops.execute('post', {
    entryDate: '2026-03-15',
    voucherId: payVoucher.id,
    text: 'Teilzahlung',
    lines: [
      { account: '1400', side: 'credit', money: { amount: '500.00', currency: 'EUR' } },
      { account: revenueAccount, side: 'debit', money: { amount: '500.00', currency: 'EUR' } },
    ],
  }) as { id: string };
  ops.execute('settle', {
    entryId: payment.id,
    allocations: [{ openItemId: entry.openItemsCreated[0]!.id, money: { amount: '500.00', currency: 'EUR' } }],
  });
  ops.execute('finalize', { entryId: entry.id });

  return {
    accountId: account.id,
    voucherId: voucher.id,
    entryId: entry.id,
    openItemId: entry.openItemsCreated[0]!.id,
    partnerId: partner.id,
  };
}

describe('adapter round-trip', () => {
  it('gives every aggregate back unchanged to a freshly built tenant', () => {
    const writer = tenantOn(TENANT_A);
    const ids = writeBooks(writer, 'A', '8400');

    // Second instance on the same database: nothing below can come from in-memory state.
    const reader = tenantOn(TENANT_A);
    const encode = (value: unknown): string => JSON.stringify(value);

    expect(encode(reader.accounts.all())).toBe(encode(writer.accounts.all()));
    expect(encode(reader.fiscalYears.byYear(2026))).toBe(encode(writer.fiscalYears.byYear(2026)));
    expect(encode(reader.journal.all())).toBe(encode(writer.journal.all()));
    expect(encode(reader.openItems.all())).toBe(encode(writer.openItems.all()));
    expect(encode(reader.partners.all())).toBe(encode(writer.partners.all()));
    expect(encode(reader.vouchers.all())).toBe(encode(writer.vouchers.all()));
    expect(encode(reader.audit.all())).toBe(encode(writer.audit.all()));

    const entry = reader.journal.byId(Uuid.fromString(ids.entryId));
    expect(entry?.text()).toBe('Rechnung A mit Ümläuten');
    expect(entry?.status()).toBe('finalized');

    const item = reader.openItems.byId(Uuid.fromString(ids.openItemId));
    expect(item?.remaining().amountAsString()).toBe('690.00');
    expect(item?.status()).toBe('partially_settled');
    expect(item?.settlements()[0]?.cause).toBe('payment');
  });
});

describe('costing runs survive the process', () => {
  /**
   * The finding this port exists for (F-KLR-001/004): a run created in one process used to be gone
   * in the next, because the service kept it in a private Map. An application that builds a tenant
   * per request could therefore release a run and never read it again — and `costAllocationSheet`
   * needs the runId, so there was no way to have a valid one.
   *
   * The test is the shape of the defect: write with one tenant instance, read with a second one on
   * the same database, and nothing in between may come from the object graph.
   */
  function seedCosting(tenant: Tenant): string {
    const ops = new TenantOperations(tenant);
    ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
    ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
    ops.execute('createAccount', { number: '6000', name: 'Aufwand', type: 'expense' });
    ops.execute('defineDimensionType', { code: 'costCenter' });
    ops.execute('defineDimensionValue', { type: 'costCenter', code: 'K100' });
    ops.execute('setAllocationScheme', { method: 'step_ladder', steps: [] });
    const voucher = ops.execute('createVoucher', {
      voucher: { voucherNumber: 'ER-1', voucherDate: '2026-01-20' },
    }) as Record<string, unknown>;
    ops.execute('post', {
      entryDate: '2026-01-20',
      voucherId: String(voucher.id),
      text: 'Kosten der Stelle K100',
      lines: [
        {
          account: '6000',
          side: 'debit',
          money: { amount: '240.00', currency: 'EUR' },
          dimensions: [{ type: 'costCenter', code: 'K100' }],
        },
        { account: '1200', side: 'credit', money: { amount: '240.00', currency: 'EUR' } },
      ],
    });
    const run = ops.execute('runCosting', { fiscalYear: 2026, period: 1 }) as Record<string, unknown>;
    ops.execute('releaseCosting', { runId: String(run.runId) });
    return String(run.runId);
  }

  it('reads a released run back through a second tenant instance', () => {
    const runId = seedCosting(tenantOn(TENANT_A));

    const reader = new TenantOperations(tenantOn(TENANT_A));
    const sheet = reader.project('costAllocationSheet', { runId }) as Record<string, unknown>;

    expect(sheet.status).toBe('released');
    expect(sheet.version).toBe(1);
    expect(sheet.grandTotal).toBe('240.00');
    expect(sheet.primary).toEqual([{ costCenter: 'K100', total: '240.00' }]);
  });

  it('counts the next version from the store, not from a counter that restarts', () => {
    seedCosting(tenantOn(TENANT_A));

    // A second run of the SAME period, from a new instance. With the counter that used to live in
    // the service this came back as version 1 — two runs both claiming to be the first.
    const second = new TenantOperations(tenantOn(TENANT_A)).execute('runCosting', {
      fiscalYear: 2026,
      period: 1,
    }) as Record<string, unknown>;

    expect(second.version).toBe(2);
  });

  it('keeps one tenant’s runs out of another’s', () => {
    const runId = seedCosting(tenantOn(TENANT_A));

    const other = new TenantOperations(tenantOn(TENANT_B, 'Andere GmbH'));
    expect(() => other.project('costAllocationSheet', { runId })).toThrow(/does not exist/);
  });
});

describe('tenant scoping', () => {
  const lookup = (tenant: Tenant, port: string, id: string): unknown => {
    const uuid = Uuid.fromString(id);
    switch (port) {
      case 'accounts':
        return tenant.accounts.byId(uuid);
      case 'vouchers':
        return tenant.vouchers.byId(uuid);
      case 'journal':
        return tenant.journal.byId(uuid);
      case 'openItems':
        return tenant.openItems.byId(uuid);
      case 'partners':
        return tenant.partners.byId(uuid);
      default:
        throw new Error(port);
    }
  };

  it('never leaks the other tenant into a listing', () => {
    const a = tenantOn(TENANT_A, 'A');
    writeBooks(a, 'A', '8400');
    const b = tenantOn(TENANT_B, 'B');
    writeBooks(b, 'B', '8500');

    expect(a.accounts.all()).toHaveLength(2);
    expect(b.accounts.all()).toHaveLength(2);
    expect(a.journal.all()).toHaveLength(2);
    expect(a.openItems.all()).toHaveLength(1);
    expect(a.partners.all()).toHaveLength(1);
    expect(a.journal.all()[0]!.text()).toBe('Rechnung A mit Ümläuten');
    expect(b.journal.all()[0]!.text()).toBe('Rechnung B mit Ümläuten');
  });

  it.each([
    ['accounts', 'accountId'],
    ['vouchers', 'voucherId'],
    ['journal', 'entryId'],
    ['openItems', 'openItemId'],
    ['partners', 'partnerId'],
  ])('byId refuses another tenant’s %s', (port, idKey) => {
    const a = tenantOn(TENANT_A, 'A');
    const idsA = writeBooks(a, 'A', '8400');
    const b = tenantOn(TENANT_B, 'B');
    const idsB = writeBooks(b, 'B', '8500');

    expect(lookup(a, port, idsA[idKey]!)).not.toBeNull();
    expect(lookup(a, port, idsB[idKey]!)).toBeNull();
  });

  it('keeps byOriginEntry inside the tenant', () => {
    const a = tenantOn(TENANT_A, 'A');
    const idsA = writeBooks(a, 'A', '8400');
    const b = tenantOn(TENANT_B, 'B');
    const idsB = writeBooks(b, 'B', '8500');

    expect(a.openItems.byOriginEntry(Uuid.fromString(idsA.entryId))).toHaveLength(1);
    expect(a.openItems.byOriginEntry(Uuid.fromString(idsB.entryId))).toEqual([]);
  });

  it('cannot write over another tenant’s row', () => {
    const a = tenantOn(TENANT_A, 'A');
    writeBooks(a, 'A', '8400');
    const b = tenantOn(TENANT_B, 'B');
    const idsB = writeBooks(b, 'B', '8500');

    // An account carrying B's id, handed to A's repository — the shape a tenant mix-up takes in an
    // app that keeps one repository around and passes aggregates between requests.
    a.accounts.save(
      new Account(Uuid.fromString(idsB.accountId), AccountNumber.of('1400'), 'Übernommen von A', 'asset', 'ar'),
    );

    expect(b.accounts.byId(Uuid.fromString(idsB.accountId))?.name).toBe('Forderungen');
  });

  /**
   * IMPL-023: an asset carries the dimensions its machine entries inherit — depreciation for years to
   * come is booked with them, so losing them here makes depreciation impossible on the next start
   * wherever a dimension is mandatory. Written straight through the repository rather than through
   * `acquireAsset`: what is under test is the column round trip, not the posting rules, and the
   * hydration branch this covers is the one the 0.9.0 release gate caught as untested.
   */
  it('carries an asset\u2019s dimensions through the database', () => {
    const a = tenantOn(TENANT_A, 'A');
    const id = Uuid.fromString('0195f000-0000-7000-8000-00000000cccc');
    a.assets.add(
      new Asset(
        id,
        'Fr\u00e4se',
        'machinery',
        AccountNumber.of('0500'),
        Money.of('3600.00', Currency.of('EUR')),
        CalendarDate.of('2026-01-01'),
        'capitalize',
        36,
        [],
        Uuid.fromString('0195f000-0000-7000-8000-00000000dddd'),
        [{ type: 'costCenter', code: 'FERTIGUNG' }],
      ),
    );

    // A second tenant instance on the same database: what is asserted has been through a column.
    const reread = tenantOn(TENANT_A, 'A').assets.byId(id);

    expect(reread).not.toBeNull();
    expect(reread?.dimensions).toEqual([{ type: 'costCenter', code: 'FERTIGUNG' }]);
  });

  it('leaves an asset without dimensions empty rather than undefined', () => {
    const a = tenantOn(TENANT_A, 'A');
    const id = Uuid.fromString('0195f000-0000-7000-8000-00000000eeee');
    a.assets.add(
      new Asset(
        id,
        'Presse',
        'machinery',
        AccountNumber.of('0500'),
        Money.of('1200.00', Currency.of('EUR')),
        CalendarDate.of('2026-01-01'),
        'capitalize',
        36,
        [],
        Uuid.fromString('0195f000-0000-7000-8000-00000000ffff'),
      ),
    );

    expect(tenantOn(TENANT_A, 'A').assets.byId(id)?.dimensions).toEqual([]);
  });
});
