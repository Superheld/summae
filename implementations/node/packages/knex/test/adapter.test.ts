import {
  Account,
  applyAuditCriteria,
  type AuditCriteria,
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
import { DatabaseTenantRecordRepository, listTenants } from '../src/repositories.js';
import { installSchema, TABLE_PREFIX } from '../src/schema-installer.js';
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
  return DatabaseTenantFactory.build(db, clock, new UuidV7IdGenerator(clock), {
    tenantId: Uuid.fromString(tenantId),
    name,
    baseCurrency: Currency.of('EUR'),
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

  /**
   * IMPL-036, twin of PHP's `testTheStoredJsonIsTheAggregatesOwnSerialization`.
   *
   * The shared data format lives in these columns — PHP writes them, Node reads them (SF-15). If a
   * repository ever encoded a shape of its own instead of the aggregate's, the cross-test would
   * only notice for the fields it happens to compare, and the ones it does not would drift silently
   * in a format whose whole point is that both engines agree on it.
   */
  it('stores the aggregate own serialization, not a shape of the repository', () => {
    const writer = tenantOn(TENANT_A);
    const ids = writeBooks(writer, 'A', '8400');
    const reader = tenantOn(TENANT_A);

    const column = (table: string, id: string, field: string): unknown => {
      const row = db.first(db.table(`${TABLE_PREFIX}${table}`).where('id', id));
      return JSON.parse(String((row as Record<string, unknown>)[field]));
    };

    const voucher = reader.vouchers.byId(Uuid.fromString(ids.voucherId));
    expect(voucher).not.toBeNull();
    expect(column('vouchers', ids.voucherId, 'payload')).toEqual(voucher!.toJSON());

    const partner = reader.partners.byId(Uuid.fromString(ids.partnerId));
    expect(partner).not.toBeNull();
    expect(column('partners', ids.partnerId, 'payload')).toEqual(partner!.toJSON());

    const item = reader.openItems.byId(Uuid.fromString(ids.openItemId));
    expect(item).not.toBeNull();
    expect(column('open_items', ids.openItemId, 'settlements')).toEqual(
      item!.settlements().map((settlement) => settlement.toJSON()),
    );
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

describe('inventory valuations survive the process', () => {
  /**
   * The same shape of test as the costing block above, for the same reason (F-CORE-050).
   *
   * A valuation is the *record of how a stock figure was reached*, and a record that lives in the
   * process which made it is not a record. So: write with one tenant instance, read with a second on
   * the same database, and nothing in between may come from the object graph.
   *
   * The second test is the one worth having. `valuateInventory` posts the **difference** against the
   * current book value, and the next version comes out of the store — so a second valuation of an
   * unchanged period must book nothing *across a process boundary too*. With a counter that lived in
   * the service it would have come back as version 1 and booked the full amount a second time, which
   * on a balance sheet means the stock is there twice.
   *
   * PHP twin: `packages/laravel/tests/InventoryValuationPersistenceTest.php`.
   */
  function seedInventory(tenant: Tenant, fresh = true): TenantOperations {
    const ops = new TenantOperations(tenant);

    // Only on the first instance: the year and the accounts are in the database, which is the whole
    // point — a second tenant object on the same connection reads them rather than creating them.
    if (fresh) {
      ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
      ops.execute('createAccount', { number: '1120', name: 'Fertige Erzeugnisse', type: 'asset', subtype: 'inventory' });
      ops.execute('createAccount', { number: '4100', name: 'Bestandsveränderungen', type: 'revenue' });
    }

    // The pack data is NOT in the database — it arrives with the pack on every open, which is why
    // every factory injects it and why this test has to as well.
    tenant.inventory.setRuleModule({
      inventory: { categories: [{ account: '1120', changeAccount: '4100' }] },
    });

    return ops;
  }

  function valuate(ops: TenantOperations): Record<string, unknown> {
    return ops.execute('valuateInventory', {
      fiscalYear: 2026,
      period: 12,
      valuationDate: '2026-12-31',
      categories: [{ account: '1120', quantity: '400', unitCost: '12.50' }],
    }) as Record<string, unknown>;
  }

  it('reads a valuation back through a second tenant instance', () => {
    valuate(seedInventory(tenantOn(TENANT_A)));

    const reader = new TenantOperations(tenantOn(TENANT_A));
    const report = reader.project('inventoryValuation', {}) as { valuations: Array<Record<string, unknown>> };

    expect(report.valuations).toHaveLength(1);
    const first = report.valuations[0] as Record<string, unknown>;
    expect(first.closingTotal).toBe('5000.00');
    expect(first.change).toBe('5000.00');
    expect(first.version).toBe(1);
    expect(first.valuationDate).toBe('2026-12-31');
    // Every detail of the act, back through a column: the quantity is not Money and must survive as
    // the string it was given.
    const categories = first.categories as Array<Record<string, unknown>>;
    expect(categories[0]?.quantity).toBe('400');
    expect(categories[0]?.source).toBe('input');
    expect(categories[0]?.changeAccount).toBe('4100');
    expect(typeof first.entryId).toBe('string');
  });

  it('books nothing on a second valuation of an unchanged period, across a process boundary', () => {
    valuate(seedInventory(tenantOn(TENANT_A)));

    const second = valuate(seedInventory(tenantOn(TENANT_A), false));

    expect(second.version).toBe(2);
    expect(second.posted).toBe(false);
    expect(second.entryId).toBeNull();
  });

  it('keeps one tenant’s valuations out of another’s', () => {
    valuate(seedInventory(tenantOn(TENANT_A)));

    const other = new TenantOperations(tenantOn(TENANT_B, 'Andere GmbH'));
    const report = other.project('inventoryValuation', {}) as { valuations: unknown[] };

    expect(report.valuations).toEqual([]);
  });
});

describe('provisions survive the process', () => {
  /**
   * A provision outlives the process that formed it, and so does its history (F-CORE-051).
   *
   * The second test is the one that matters. A provision is used, released and re-measured over
   * *years* — the movement list is the record an auditor reads, and a list that only exists in the
   * object graph is not a record at all.
   *
   * PHP twin: `packages/laravel/tests/ProvisionPersistenceTest.php`.
   */
  function seedProvisions(tenant: Tenant, fresh = true): TenantOperations {
    const ops = new TenantOperations(tenant);

    if (fresh) {
      ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
      ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
      ops.execute('createAccount', { number: '3600', name: 'Rückstellungen', type: 'liability', subtype: 'provision' });
      ops.execute('createAccount', { number: '4900', name: 'Erträge', type: 'revenue' });
      ops.execute('createAccount', { number: '6800', name: 'Zuführung', type: 'expense' });
    }

    // Pack data is not in the database — it arrives with the pack on every open.
    tenant.provisionService.setRuleModule({
      provisions: {
        accounts: [{ account: '3600', expenseAccount: '6800', releaseAccount: '4900' }],
        discounting: { fromMonths: 12, basis: 'test' },
      },
    });

    return ops;
  }

  function recognize(ops: TenantOperations): string {
    const result = ops.execute('recognizeProvision', {
      account: '3600',
      reason: 'Prozessrisiko',
      amount: { amount: '5000.00', currency: 'EUR' },
      recognizedOn: '2026-06-30',
    }) as Record<string, unknown>;
    return String(result.provisionId);
  }

  it('reads a provision and its history back through a second tenant instance', () => {
    const ops = seedProvisions(tenantOn(TENANT_A));
    const id = recognize(ops);
    ops.execute('useProvision', {
      provisionId: id,
      amount: { amount: '2000.00', currency: 'EUR' },
      settlementAccount: '1200',
      date: '2026-09-30',
    });

    const reader = new TenantOperations(tenantOn(TENANT_A));
    const register = reader.project('provisionRegister', {}) as {
      provisions: Array<Record<string, unknown>>;
      total: string;
    };

    expect(register.provisions).toHaveLength(1);
    const first = register.provisions[0] as Record<string, unknown>;
    expect(first.carryingAmount).toBe('3000.00');
    expect(first.status).toBe('open');
    expect(register.total).toBe('3000.00');

    const movements = first.movements as Array<Record<string, unknown>>;
    expect(movements, 'the history is the record — it must survive the process').toHaveLength(2);
    expect(movements.map((m) => m.kind)).toEqual(['recognized', 'used']);
    expect(typeof movements[1]?.entryId, 'every movement names the entry it produced').toBe('string');
  });

  it('continues the history from a second instance rather than starting over', () => {
    const id = recognize(seedProvisions(tenantOn(TENANT_A)));

    const second = seedProvisions(tenantOn(TENANT_A), false);
    const released = second.execute('releaseProvision', { provisionId: id, date: '2026-12-31' }) as Record<
      string,
      unknown
    >;

    // The carrying amount came out of the store, not out of a fresh object at its original value —
    // which is what a provision service keeping its own map would have done.
    expect((released.released as Record<string, unknown>).amount).toBe('5000.00');
    expect(released.status).toBe('settled');
  });

  it('keeps one tenant’s provisions out of another’s', () => {
    recognize(seedProvisions(tenantOn(TENANT_A)));

    const other = new TenantOperations(tenantOn(TENANT_B, 'Andere GmbH'));
    const register = other.project('provisionRegister', {}) as { provisions: unknown[] };

    expect(register.provisions).toEqual([]);
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

  /**
   * IMPL-036, twin of PHP's `testByIdRefusesAnotherTenantsRow`. This side checked that *listings*
   * do not leak and that a *write* cannot cross, but not the single most likely call: asking for
   * one row by an id you were handed. A repository that forgets the tenant clause there hands out
   * another company's books to anybody who learns an id.
   */
  it('refuses another tenant row on a direct byId', () => {
    const a = tenantOn(TENANT_A);
    const idsA = writeBooks(a, 'A', '8400');
    const b = tenantOn(TENANT_B);
    const idsB = writeBooks(b, 'B', '8500');

    expect(a.journal.byId(Uuid.fromString(idsA.entryId)), 'own row must be found').not.toBeNull();
    expect(a.journal.byId(Uuid.fromString(idsB.entryId))).toBeNull();
    expect(a.vouchers.byId(Uuid.fromString(idsB.voucherId))).toBeNull();
    expect(a.partners.byId(Uuid.fromString(idsB.partnerId))).toBeNull();
    expect(a.openItems.byId(Uuid.fromString(idsB.openItemId))).toBeNull();
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

/**
 * SPEC-015: the configuration five operations change now outlives the object that changed it.
 *
 * These are the tests the finding says could not exist. A fixture builds one tenant in one process,
 * where a registry held in memory and a registry read from a table behave identically — so the
 * whole class of defect was invisible to 154 green fixtures. Here the tenant is deliberately thrown
 * away and reopened, which is the only way to ask the question at all.
 */
describe('tenant configuration survives the process (SPEC-015)', () => {
  const TENANT_C = '0195f000-0000-7000-8000-00000000cccc';

  it('keeps a dimension type, so the posting that uses it is accepted after a reopen', () => {
    new TenantOperations(tenantOn(TENANT_C, 'Config GmbH')).execute('defineDimensionType', {
      code: 'costCenter',
    });
    new TenantOperations(tenantOn(TENANT_C)).execute('defineDimensionValue', {
      type: 'costCenter',
      code: 'FERTIGUNG',
    });

    // The second call above is already the proof: declaring a VALUE of `costCenter` from a fresh
    // tenant object can only work if the TYPE declared in the first one is still known. Before this,
    // it answered E_DIMENSION_INVALID for a type the caller had just created.
    //
    // A third, independent open confirms it from the other side: redeclaring the type is refused as
    // a duplicate, which an empty registry would have accepted.
    expect(() =>
      new TenantOperations(tenantOn(TENANT_C)).execute('defineDimensionType', { code: 'costCenter' }),
    ).toThrow(/E_DIMENSION_INVALID|already/i);

    expect(new DatabaseTenantRecordRepository(db, Uuid.fromString(TENANT_C)).load()?.config).toMatchObject({
      dimensionTypes: [{ code: 'costCenter' }],
      dimensionValues: [{ typeCode: 'costCenter', code: 'FERTIGUNG' }],
    });
  });

  it('keeps a tax profile change', () => {
    new TenantOperations(tenantOn(TENANT_C, 'Config GmbH')).execute('setTaxProfile', {
      smallBusiness: { validFrom: '2026-01-01', value: true },
    });

    expect(tenantOn(TENANT_C).tax.profile().smallBusinessAt(CalendarDate.of('2026-06-01'))).toBe(true);
  });

  it('keeps an allocation scheme, and replays it without auditing a change nobody made', () => {
    const first = tenantOn(TENANT_C, 'Config GmbH');
    new TenantOperations(first).execute('setAllocationScheme', {
      method: 'step_ladder',
      // `code`, not `costCenter`: this test carried the wrong key until SPEC-017 made the contract
      // reach into elements, and the receiver was silently dropped while the test stayed green.
      steps: [{ sender: 'HILFS', receivers: [{ code: 'FERTIGUNG', share: '1' }] }],
    });
    const auditedOnce = first.audit.all().filter((r) => r.objectType === 'allocationScheme').length;

    const reopened = tenantOn(TENANT_C);
    expect(reopened.audit.all().filter((r) => r.objectType === 'allocationScheme')).toHaveLength(auditedOnce);

    // That the scheme reached the live object, and not merely the table: the audit record of the
    // NEXT change reports what it replaced, and `stepCount.from` can only be 1 if the reopened
    // service was actually carrying the stored step.
    new TenantOperations(reopened).execute('setAllocationScheme', { method: 'step_ladder', steps: [] });
    const change = reopened.audit
      .all()
      .filter((record) => record.objectType === 'allocationScheme')
      .at(-1);
    expect(change?.changes.stepCount).toEqual({ from: 1, to: 0 });
  });

  it('names its tenant, so an id that belongs to no books is distinguishable from a new one', () => {
    tenantOn(TENANT_C, 'Config GmbH');

    expect(listTenants(db).map((row) => row.name)).toContain('Config GmbH');
    expect(new DatabaseTenantRecordRepository(db, Uuid.fromString(TENANT_B)).load()).toBeNull();
  });

  it('lets the stored record win over what a later open passes in', () => {
    tenantOn(TENANT_C, 'The name it was created with');

    // The seed rule: a second open passing a different name changes nothing. Two sources of truth
    // that drift is the state this finding came out of.
    expect(tenantOn(TENANT_C, 'A different name later').name).toBe('The name it was created with');
  });
});

/**
 * The SQL filter and the in-memory filter answer the same question the same way (SPEC-018).
 *
 * `AuditTrail.find` exists twice over: once as SQL that declines to read rows, once as
 * `applyAuditCriteria` walking a list. That is the arrangement the quality policy calls a *shared
 * data* check — two implementations of one rule, driven with the same input, compared. Without it
 * the database path could quietly answer differently and every fixture would stay green, because
 * fixtures run against the in-memory core.
 *
 * The criteria are not a sample: every filter the port declares, alone and combined, plus the
 * paging edges (offset past the end, limit zero, an empty id set).
 *
 * The PHP twin is `packages/laravel/tests/AuditQueryEquivalenceTest.php`.
 */
describe('the audit query answers alike in SQL and in memory (SPEC-018)', () => {
  it('matches the in-memory filter for every declared criterion', () => {
    const tenant = tenantOn(TENANT_A);
    const ops = new TenantOperations(tenant);

    ops.execute('createAccount', { actor: 'anna', number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
    ops.execute('createAccount', { actor: 'anna', number: '8400', name: 'Erlöse', type: 'revenue' });
    ops.execute('createFiscalYear', { actor: 'bruce', year: 2026, start: '2026-01-01', end: '2026-12-31' });
    const voucher = ops.execute('createVoucher', {
      actor: 'bruce',
      voucher: { voucherNumber: 'AR-1', voucherDate: '2026-02-01' },
    }) as { id: string };
    const entry = ops.execute('post', {
      actor: 'bruce',
      entryDate: '2026-02-01',
      voucherId: voucher.id,
      text: 'Rechnung',
      lines: [
        { account: '1200', side: 'debit', money: { amount: '100.00', currency: 'EUR' } },
        { account: '8400', side: 'credit', money: { amount: '100.00', currency: 'EUR' } },
      ],
    }) as { id: string };
    ops.execute('lockAccount', { actor: 'anna', number: '8400' });

    const trail = tenant.audit;
    const all = trail.all();
    expect(all.length, 'the trail needs enough records for paging to mean anything').toBeGreaterThan(4);
    const accountRecord = all.find((record) => record.objectType === 'account');
    expect(accountRecord).toBeDefined();
    const accountId = accountRecord?.objectId.value ?? '';

    const criteria: AuditCriteria[] = [
      {},
      { objectType: 'account' },
      { objectType: 'journalEntry', action: 'created' },
      { action: 'created' },
      { actor: 'anna' },
      { actor: 'bruce' },
      { actor: 'niemand' },
      { objectId: entry.id },
      { objectId: accountId },
      { objectIds: [entry.id, accountId] },
      { objectIds: [entry.id] },
      // An empty set means "none of them", not "all of them" — the case a naive IN clause gets
      // exactly backwards.
      { objectIds: [] },
      { from: '2026-06-07' },
      { to: '2026-06-07' },
      { from: '2026-06-08' },
      { to: '2026-06-06' },
      { limit: 2 },
      { offset: 1, limit: 2 },
      { offset: 1 },
      { limit: 0 },
      { offset: 999 },
      { objectType: 'account', actor: 'anna', limit: 1 },
    ];

    for (const c of criteria) {
      const fromDatabase = trail.find(c);
      const inMemory = applyAuditCriteria(all, c);
      const label = JSON.stringify(c);
      expect(fromDatabase.count, `count differs for ${label}`).toBe(inMemory.count);
      expect(fromDatabase.records.map((r) => r.id.value), `records differ for ${label}`).toEqual(
        inMemory.records.map((r) => r.id.value),
      );
    }
  });
});
