import {
  Currency,
  DomainError,
  FixedClock,
  Money,
  type Tenant,
  TenantOperations,
  Uuid,
  UuidV7IdGenerator,
} from '@superheld/summae-core';
import { beforeEach, describe, expect, it } from 'vitest';
import { DatabaseTenantFactory } from '../src/database-tenant-factory.js';
import * as H from '../src/hydrator.js';
import { installSchema, TABLE_PREFIX } from '../src/schema-installer.js';
import { SyncDb } from '../src/sync-db.js';

/**
 * `E_AMOUNT_SCALE_MISMATCH` — the store's amounts carry exactly the tenant's decimal places
 * (IMPL-040).
 *
 * The code sat in `fehlerkatalog.md` and in both exit-code tables and was raised by nothing: the
 * only catalogue code reachable through the API with no test behind it. What the check protects is
 * the shared data set — a store written by one runtime at scale 3 opened by a tenant at scale 2 is
 * SF-15's own scenario, and SF-15 passes because both runtimes agree, not because anything verifies
 * the amounts.
 *
 * Building it found the defect underneath: the hydrator built the currency from the stored code
 * with no scale override, so it read every amount on the ISO default no matter what the tenant's
 * pack says. At scale 3 that threw a raw `InvalidValue` out of the adapter; at scale 0 it silently
 * widened `"1234"` to `"1234.00"`. No fixture that re-hydrates money runs at a scale other than 2.
 *
 * `E_ENTRY_INVALID_AMOUNT` keeps the API-input side (`core/post-malformed` pins it): that code
 * judges an amount a caller offered, this one judges an amount already in the books.
 *
 * PHP twin: AmountScaleTest.
 */

const TENANT = '0195f000-0000-7000-8000-00000000cccc';

let db: SyncDb;

beforeEach(() => {
  db = new SyncDb(':memory:');
  installSchema(db);
});

function tenantAtScale(scale: number): Tenant {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  return DatabaseTenantFactory.build(db, clock, new UuidV7IdGenerator(clock), {
    tenantId: Uuid.fromString(TENANT),
    name: 'Skalen GmbH',
    baseCurrency: Currency.of('EUR', scale),
  });
}

/** A tenant with a receivable, so an open item exists whose amount is a column of its own. */
function seedReceivable(tenant: Tenant, amount: string): void {
  const ops = new TenantOperations(tenant);
  ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
  ops.execute('createAccount', { number: '10000', name: 'Kunde', type: 'asset', subtype: 'ar' });
  ops.execute('createAccount', { number: '8400', name: 'Erlöse', type: 'revenue' });
  const voucher = ops.execute('createVoucher', {
    voucher: { voucherNumber: 'RE-1', voucherDate: '2026-03-01' },
  }) as Record<string, unknown>;
  ops.execute('post', {
    entryDate: '2026-03-01',
    voucherId: voucher.id as string,
    text: 'Ausgangsrechnung',
    lines: [
      { account: '10000', side: 'debit', money: { amount, currency: 'EUR' } },
      { account: '8400', side: 'credit', money: { amount, currency: 'EUR' } },
    ],
  });
}

function rewriteStoredAmount(amount: string): void {
  db.run(db.knex(`${TABLE_PREFIX}open_items`).update({ amount }));
}

describe('E_AMOUNT_SCALE_MISMATCH', () => {
  // The bug the finding did not know about: a scale-3 tenant could not read its own books back.
  // Before the fix, reading a scale-3 open item threw InvalidValue ("Invalid amount for currency
  // EUR (scale 2)") from inside the adapter, because the hydrator had rebuilt the currency at its
  // ISO default.
  it('lets a tenant read its own books back on its own scale', () => {
    seedReceivable(tenantAtScale(3), '107.501');

    // A second tenant instance on the same database: everything asserted has been through a column.
    const open = new TenantOperations(tenantAtScale(3)).project('openItems', {}) as Record<string, unknown>;
    const items = open.items as Array<Record<string, unknown>>;

    expect(items).toHaveLength(1);
    expect((items[0]!.money as Record<string, unknown>).amount).toBe('107.501');
  });

  // The reader direction. The row is rewritten straight in the column rather than through a
  // repository — that is the whole point. A store on the wrong scale cannot be produced by this
  // engine; it arrives from another runtime, a restore, or a hand edit, and that is exactly when
  // the books must not be reshaped quietly.
  it('refuses a stored amount on the wrong scale, by name', () => {
    seedReceivable(tenantAtScale(2), '100.00');
    rewriteStoredAmount('100.000');

    const ops = new TenantOperations(tenantAtScale(2));
    try {
      ops.project('openItems', {});
      expect.unreachable('a stored amount on the wrong scale must not be read as if it were right');
    } catch (error) {
      expect(error).toBeInstanceOf(DomainError);
      expect((error as DomainError).errorCode).toBe('E_AMOUNT_SCALE_MISMATCH');
    }
  });

  // Too FEW places is the same defect and the easier one to miss: "100.0" is a value the engine can
  // represent, so nothing would have complained — it would simply have been widened.
  it('treats mandatory zeros as mandatory', () => {
    seedReceivable(tenantAtScale(2), '100.00');
    rewriteStoredAmount('100.0');

    const ops = new TenantOperations(tenantAtScale(2));
    try {
      ops.project('openItems', {});
      expect.unreachable('"100.0" is not the canonical form of 100.00 and must not be padded silently');
    } catch (error) {
      expect((error as DomainError).errorCode).toBe('E_AMOUNT_SCALE_MISMATCH');
    }
  });

  // The writer direction, at the one seam where an amount leaves as a bare string rather than
  // through a Money object. Everything else is serialised by Money itself, which is canonical by
  // construction — so this is the only place a writer could reshape an amount by hand.
  it('refuses to store an amount off the tenant scale', () => {
    expect(() => H.assertScale(Money.of('100.00', 'EUR').amountAsString(), Currency.of('EUR', 3))).toThrow(
      /E_AMOUNT_SCALE_MISMATCH|decimal place/,
    );

    // And the happy path really is happy, in both the zero-scale and the wide case.
    expect(H.assertScale('1234', Currency.of('JPY'))).toBe('1234');
    expect(H.assertScale('1.500', Currency.of('EUR', 3))).toBe('1.500');
  });

  // An absent amount keeps the documented zero fallback. A malformed document must not take the
  // process down mid-read, and "no amount at all" is a different thing from "an amount on the wrong
  // scale" — only the second is a claim somebody made about the books.
  it('still reads an absent amount as the documented zero', () => {
    expect(H.money({}, Currency.of('EUR', 3)).amountAsString()).toBe('0.000');
  });
});
