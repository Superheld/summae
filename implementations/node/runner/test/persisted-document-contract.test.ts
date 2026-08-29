import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { installSchema, SyncDb, TABLE_PREFIX } from '@superheld/summae-knex';

/**
 * Gate for the half of the shared data format that never leaves through `journalExport` (IMPL-046).
 *
 * The root CLAUDE.md calls the adapters' JSON "the shared data format", and the contract obligations
 * say anything the engine reads is validated against `format.schema.json`. For two years that was
 * true of the exchange format and false of everything the adapters store as an aggregate: assets and
 * costing runs were undeclared, and on 2026-08-29 provisions, deferrals and inventory valuations
 * joined them — three new document kinds in a day, in a format that did not know they existed. The
 * cost was not theoretical: the first comparison of two engines' stored documents found PHP writing
 * an empty map as `[]` where Node wrote `{}`.
 *
 * So this test asks the question nothing asked: **does every table the adapter creates hold a
 * document the format declares?** It is deliberately the *table* list and not a hand-kept list of
 * document types — a sixth aggregate arrives as a table, and a table nobody declared is exactly what
 * this must notice.
 *
 * Two tables are exceptions, and the exception carries its reason (IMPL-047). They are columnar
 * rather than one-document-per-row, and one of them (`tenants.config`) stores a shape that follows
 * its own input closely enough that declaring it is a decision rather than a paragraph. The
 * cross-test compares both byte-exact across engines, so they are unguarded only against a *schema*.
 *
 * The SAME checks live in the PHP PersistedDocumentContractTest.
 */

const REPO_ROOT = join(import.meta.dirname, '..', '..', '..', '..');

/** table (without prefix) → the `$defs` key describing the document it stores. */
const DECLARED: Readonly<Record<string, readonly string[]>> = {
  accounts: ['account'],
  journal_entries: ['journalEntry'],
  vouchers: ['voucher'],
  partners: ['partner'],
  open_items: ['openItem'],
  audit_log: ['auditRecord'],
  assets: ['asset', 'assetState'],
  costing_runs: ['costingRun'],
  provisions: ['provision'],
  deferrals: ['deferral'],
  inventory_valuations: ['inventoryValuation'],
};

const EXCEPTIONS: Readonly<Record<string, string>> = {
  fiscal_years: 'columnar; the `periods` column is JSON that no $defs entry describes — IMPL-047',
  tenants: 'columnar; `config` carries the tenant configuration (SPEC-015) and follows the operation input closely — IMPL-047',
};

function schemaDefs(): Set<string> {
  const schema = JSON.parse(
    readFileSync(join(REPO_ROOT, 'testing', 'testsuite', 'schema', 'format.schema.json'), 'utf8'),
  ) as { $defs?: Record<string, unknown> };
  return new Set(Object.keys(schema.$defs ?? {}));
}

function installedTables(): string[] {
  const db = new SyncDb(':memory:');
  try {
    installSchema(db);
    const rows = db.all(db.table('sqlite_master').select('name').where('type', 'table'));
    return rows
      .map((row) => String(row.name))
      .filter((name) => name.startsWith(TABLE_PREFIX))
      .map((name) => name.slice(TABLE_PREFIX.length))
      .sort();
  } finally {
    db.close();
  }
}

describe('every persisted document is declared in the data format', () => {
  it('installs the schema and finds its tables', () => {
    expect(installedTables().length).toBeGreaterThan(10);
  });

  it('declares a document type for every table the adapter creates', () => {
    const undeclared = installedTables().filter(
      (table) => DECLARED[table] === undefined && EXCEPTIONS[table] === undefined,
    );
    expect(
      undeclared,
      'these tables store documents the format does not declare and no exception covers (IMPL-046)',
    ).toEqual([]);
  });

  it('names only $defs that exist', () => {
    const defs = schemaDefs();
    const missing = Object.values(DECLARED)
      .flat()
      .filter((def) => !defs.has(def));
    expect(missing, 'the map points at $defs the schema does not have').toEqual([]);
  });

  it('keeps every exception attached to a table that exists', () => {
    const tables = new Set(installedTables());
    const stale = Object.keys(EXCEPTIONS).filter((table) => !tables.has(table));
    expect(stale, 'an exception outlived its table — delete it rather than carrying it').toEqual([]);
  });
});
