import { beforeEach, describe, expect, it } from 'vitest';
import * as H from '../src/hydrator.js';
import { dropSchema, installSchema, TABLE_PREFIX } from '../src/schema-installer.js';
import { SyncDb } from '../src/sync-db.js';

/**
 * IMPL-036 — the twin of PHP's `HydratorAndSchemaTest`, which this side simply did not have.
 *
 * `implementations/node/CLAUDE.md` says the knex suite is the twin of `packages/laravel/tests` and
 * that the two are to be kept in step. They were not — 60 cases here against 76 there — and the
 * difference was not spread evenly: the hydrator and the schema installer had **no** Node tests at
 * all. That uneven part is the finding, not the count; a first pass at measuring this compared PHP
 * test *methods* against a bad grep for `it(` and got 32-against-15, which overstated the gap by
 * roughly four times. Counting files or methods across two test frameworks measures the frameworks.
 * The gap that mattered was a whole module with no test, and that one was real.
 *
 * It is the worst place for a gap to sit. The hydrator is where the *shared* data
 * format is produced and consumed (PHP writes these documents, Node reads them — SF-15), and its
 * defensive branches never fire on the happy path the conformance runner walks, which is exactly
 * why they are worth pinning: a wrong default there does not crash, it silently drops data.
 *
 * Building it surfaced a real API gap in passing: PHP had `SchemaInstaller::drop` and Node had no
 * way to drop the schema at all.
 *
 * PHP twin: `packages/laravel/tests/HydratorAndSchemaTest.php` — same file name on purpose, so the
 * next person comparing the two suites can do it by listing them.
 */

const TABLES = [
  'accounts',
  'fiscal_years',
  'vouchers',
  'journal_entries',
  'open_items',
  'partners',
  'assets',
  'audit_log',
];

let db: SyncDb;

beforeEach(() => {
  db = new SyncDb(':memory:');
  installSchema(db);
});

describe('hydrator: money', () => {
  it('reads an amount and a currency back', () => {
    expect(H.money({ amount: '12.34', currency: 'EUR' }).amountAsString()).toBe('12.34');
  });

  it('falls back to the documented defaults rather than crashing', () => {
    // A malformed document must not take the process down mid-read; zero EUR is the documented
    // fallback, and the amount is still validated by Money itself.
    const fallback = H.money({});
    expect(fallback.amountAsString()).toBe('0.00');
    expect(fallback.currency.code).toBe('EUR');
  });
});

describe('hydrator: entry lines', () => {
  it('carries dimensions and tax tags back out, and drops what is incomplete', () => {
    const lines = H.entryLines([
      {
        accountId: '0195f000-0000-7000-8000-000000000001',
        account: '8400',
        side: 'credit',
        money: { amount: '100.00', currency: 'EUR' },
        dimensions: [
          { type: 'costCenter', code: '100' },
          { type: 'costCenter' }, // incomplete → skipped, not guessed
          'not-an-array',
        ],
        taxTag: { code: 'USt19', reportingKey: '81' },
      },
      {
        accountId: '0195f000-0000-7000-8000-000000000002',
        account: '1200',
        side: 'debit',
        money: { amount: '100.00', currency: 'EUR' },
      },
    ]);

    expect(lines).toHaveLength(2);
    // `Side` is a union type here and an enum in PHP — the value on the wire is the same string,
    // which is what the shared format cares about.
    expect(lines[0]!.side).toBe('credit');
    expect(lines[0]!.dimensions, 'an incomplete dimension is dropped, not invented').toHaveLength(1);
    expect(lines[0]!.dimensions[0]!.type).toBe('costCenter');
    expect(lines[0]!.taxTag).toEqual({ code: 'USt19', reportingKey: '81' });
    expect(lines[1]!.dimensions).toEqual([]);
    expect(lines[1]!.taxTag).toBeNull();
  });
});

describe('hydrator: dates', () => {
  it('reads the date half of a timestamp column', () => {
    // SQLite hands back "2026-03-01", Postgres "2026-03-01 00:00:00" for the same column. The Node
    // adapter is SQLite-only today, but the format is shared and a PHP-written column is read here.
    expect(H.date('2026-03-01')?.iso).toBe('2026-03-01');
    expect(H.date('2026-03-01 00:00:00')?.iso).toBe('2026-03-01');
  });

  it('answers null for everything that is not a date', () => {
    expect(H.date(null)).toBeNull();
    expect(H.date('')).toBeNull();
    expect(H.date(1234)).toBeNull();
  });
});

describe('hydrator: encode and decode', () => {
  it('round-trips unescaped UTF-8', () => {
    const encoded = H.encode({ text: 'Erlöse € Ümläute' });
    expect(encoded, 'UTF-8 stays readable in the column').toContain('Erlöse € Ümläute');
    expect(H.decode(encoded)).toEqual({ text: 'Erlöse € Ümläute' });
  });

  it('treats an absent column as empty', () => {
    expect(H.decode(null)).toEqual({});
    expect(H.decodeList(null)).toEqual([]);
    expect(H.decodeList('[{"a":1}]')).toEqual([{ a: 1 }]);
  });

  it('refuses broken JSON instead of returning empty', () => {
    // A truncated column is corruption, not "no data": answering {} here would present a posting
    // with no lines as a valid posting. PHP raises JsonException, Node a SyntaxError — different
    // class, same refusal, and neither one silently continues.
    expect(() => H.decode('{"lines": [')).toThrow();
    expect(() => H.decodeList('[{"a":')).toThrow();
  });
});

describe('schema installer', () => {
  it('creates the whole set', () => {
    for (const table of TABLES) {
      expect(db.hasTable(`${TABLE_PREFIX}${table}`), table).toBe(true);
    }
  });

  it('drops the whole set, and drops again without blowing up', () => {
    dropSchema(db);
    for (const table of TABLES) {
      expect(db.hasTable(`${TABLE_PREFIX}${table}`), table).toBe(false);
    }

    // Idempotent: teardown runs in failure paths too, and a drop that throws there replaces the
    // real error with its own.
    dropSchema(db);
    expect(db.hasTable(`${TABLE_PREFIX}accounts`)).toBe(false);
  });

  it('installs again over a live schema without touching what is there', () => {
    // SPEC-014: a workspace created before a table existed gains it on open rather than having to
    // be recreated, so a second install must be a no-op on the tables that already stand.
    db.run(db.table(`${TABLE_PREFIX}accounts`).insert({
      id: '0195f000-0000-7000-8000-000000000001',
      tenant_id: '0195f000-0000-7000-8000-00000000aaaa',
      number: '1200',
      name: 'Bank',
      type: 'asset',
    }));

    installSchema(db);

    expect(db.all(db.table(`${TABLE_PREFIX}accounts`))).toHaveLength(1);
  });

  it('makes the account number unique per tenant and not globally', () => {
    // The unique index is (tenant_id, number): two tenants must both be able to run a "1200",
    // and one tenant must not be able to run it twice.
    const row = (tenant: string, id: string) => ({
      id,
      tenant_id: tenant,
      number: '1200',
      name: 'Bank',
      type: 'asset',
    });

    db.run(db.table(`${TABLE_PREFIX}accounts`).insert(row('0195f000-0000-7000-8000-00000000aaaa', '0195f000-0000-7000-8000-000000000001')));
    db.run(db.table(`${TABLE_PREFIX}accounts`).insert(row('0195f000-0000-7000-8000-00000000bbbb', '0195f000-0000-7000-8000-000000000002')));

    expect(db.all(db.table(`${TABLE_PREFIX}accounts`))).toHaveLength(2);

    expect(() =>
      db.run(db.table(`${TABLE_PREFIX}accounts`).insert(row('0195f000-0000-7000-8000-00000000aaaa', '0195f000-0000-7000-8000-000000000003'))),
    ).toThrow();
  });
});
