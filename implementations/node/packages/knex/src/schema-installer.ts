import type { Knex } from 'knex';
import type { SyncDb } from './sync-db.js';

/** Like PHP's `SchemaInstaller::PREFIX`. */
export const TABLE_PREFIX = 'summae_';

/**
 * The `summae_*` tables — exactly the layout of the PHP reference
 * (`packages/laravel/src/Schema/SchemaInstaller.php`), so that both languages can work on the same
 * data set. Journal append-only; balances are projections — the database never computes, it holds
 * JSON documents on the aggregate.
 */
const TABLES: ReadonlyArray<{ name: string; define: (t: Knex.TableBuilder) => void }> = [
  {
    name: 'accounts',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.string('number', 64);
      t.string('name');
      t.string('type', 16);
      t.string('subtype', 32).nullable();
      t.string('status', 16).defaultTo('active');
      t.unique(['tenant_id', 'number']);
    },
  },
  {
    name: 'fiscal_years',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.integer('year');
      t.date('start');
      t.date('end');
      t.string('status', 16).defaultTo('open');
      t.json('periods');
      t.unique(['tenant_id', 'year']);
    },
  },
  {
    name: 'vouchers',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.json('payload');
    },
  },
  {
    name: 'journal_entries',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.integer('fiscal_year');
      t.integer('sequence_number');
      t.integer('period');
      t.string('status', 16);
      t.date('entry_date');
      t.date('voucher_date').nullable();
      t.string('recorded_at', 40);
      t.uuid('voucher_id');
      t.text('text');
      t.json('lines');
      t.uuid('reverses').nullable();
      t.uuid('reversed_by').nullable();
      t.unique(['tenant_id', 'fiscal_year', 'sequence_number']);
    },
  },
  {
    name: 'open_items',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.string('kind', 16);
      t.uuid('origin_entry_id').index();
      t.integer('origin_line_index');
      t.string('amount', 32);
      t.string('currency', 3);
      t.uuid('voucher_id');
      t.date('opened_at');
      t.uuid('partner_id').nullable();
      t.json('settlements');
    },
  },
  {
    name: 'partners',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.json('payload');
    },
  },
  {
    name: 'assets',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.json('payload');
      t.json('state');
    },
  },
  {
    // The costing runs (F-KLR-001/004). Period, version and status are columns rather than payload
    // fields because they are what a run is *found* by — the next version of a period, and the
    // released runs an evaluation may read.
    name: 'costing_runs',
    define: (t) => {
      t.uuid('id').primary();
      t.uuid('tenant_id').index();
      t.integer('fiscal_year');
      t.integer('period');
      t.integer('version');
      t.string('status', 16);
      t.json('payload');
      t.unique(['tenant_id', 'fiscal_year', 'period', 'version']);
    },
  },
  {
    /**
     * The tenant itself (SPEC-015) — the one table that is not made of bookkeeping records.
     *
     * `tenant_id` is a column on every other table and used to point at nothing: a tenant existed
     * only in whatever the embedding remembered, so a wrong id opened an empty ledger that was
     * indistinguishable from a new one. It also carries the configuration — tax profile, dimension
     * master data, allocation scheme, imported mappings — which five operations changed and no
     * store kept.
     *
     * Name and currency are columns because they are what a tenant is *listed* by; the
     * configuration is a JSON document because it is only ever read whole.
     */
    name: 'tenants',
    define: (t) => {
      t.uuid('id').primary();
      t.string('name');
      t.string('base_currency', 3);
      t.string('pack_id').nullable();
      t.string('pack_version').nullable();
      t.json('config');
    },
  },
  {
    name: 'audit_log',
    define: (t) => {
      t.bigIncrements('seq');
      t.uuid('id').unique();
      t.uuid('tenant_id').index();
      t.json('payload');
    },
  },
];

/**
 * Installs what is missing, **per table** (SPEC-014).
 *
 * It used to create all of them unconditionally, exactly once, at workspace initialisation, and
 * nothing upgraded an existing database — so the first change that added a table (the costing runs)
 * had no path into a workspace that already existed except recreating it. Running this again now
 * creates only what is absent.
 *
 * What that covers and what it does not is worth stating plainly, because the honest limit is the
 * reason this shape was chosen over a migration runner: it covers **additive** changes — a new
 * table, and by hand a new nullable column — and nothing else. A column that changes its type or a
 * table that has to be rewritten still needs a real migration, which neither language has. Until
 * one exists, a change of that kind means recreating the workspace, and saying so out loud is
 * better than a runner that only looks like one.
 */
export function installSchema(db: SyncDb): void {
  for (const table of TABLES) {
    const name = `${TABLE_PREFIX}${table.name}`;
    if (db.hasTable(name)) continue;
    db.schema((schema) => schema.createTable(name, table.define));
  }
}

/**
 * Remove the whole set again — the twin of PHP's `SchemaInstaller::drop`, which this side simply
 * did not have (IMPL-036).
 *
 * **Idempotent on purpose.** A second run must not fail on tables that are already gone: teardown
 * runs in failure paths too, and a drop that throws there replaces the real error with its own.
 * Reverse creation order, so a future foreign key does not have to be discovered by a crash.
 *
 * It is a **test and teardown** tool, not a migration: everything the tables hold is the books, and
 * nothing in summae calls this on its own.
 */
export function dropSchema(db: SyncDb): void {
  for (const table of [...TABLES].reverse()) {
    const name = `${TABLE_PREFIX}${table.name}`;
    if (!db.hasTable(name)) continue;
    db.schema((schema) => schema.dropTable(name));
  }
}
