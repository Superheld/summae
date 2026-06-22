import type { SyncDb } from './sync-db.js';

/** Like PHP's `SchemaInstaller::PREFIX`. */
export const TABLE_PREFIX = 'summae_';

/**
 * Creates the 8 `summae_*` tables — exactly the layout of the PHP reference
 * (`packages/laravel/src/Schema/SchemaInstaller.php`), so that both languages
 * can work on the same data set. Journal append-only; balances are
 * projections — the database never computes, it holds JSON documents on the aggregate.
 */
export function installSchema(db: SyncDb): void {
  db.schema((schema) =>
    schema
      .createTable(`${TABLE_PREFIX}accounts`, (t) => {
        t.uuid('id').primary();
        t.uuid('tenant_id').index();
        t.string('number', 64);
        t.string('name');
        t.string('type', 16);
        t.string('subtype', 32).nullable();
        t.string('status', 16).defaultTo('active');
        t.unique(['tenant_id', 'number']);
      })
      .createTable(`${TABLE_PREFIX}fiscal_years`, (t) => {
        t.uuid('id').primary();
        t.uuid('tenant_id').index();
        t.integer('year');
        t.date('start');
        t.date('end');
        t.string('status', 16).defaultTo('open');
        t.json('periods');
        t.unique(['tenant_id', 'year']);
      })
      .createTable(`${TABLE_PREFIX}vouchers`, (t) => {
        t.uuid('id').primary();
        t.uuid('tenant_id').index();
        t.json('payload');
      })
      .createTable(`${TABLE_PREFIX}journal_entries`, (t) => {
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
      })
      .createTable(`${TABLE_PREFIX}open_items`, (t) => {
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
      })
      .createTable(`${TABLE_PREFIX}partners`, (t) => {
        t.uuid('id').primary();
        t.uuid('tenant_id').index();
        t.json('payload');
      })
      .createTable(`${TABLE_PREFIX}assets`, (t) => {
        t.uuid('id').primary();
        t.uuid('tenant_id').index();
        t.json('payload');
        t.json('state');
      })
      .createTable(`${TABLE_PREFIX}audit_log`, (t) => {
        t.bigIncrements('seq');
        t.uuid('id').unique();
        t.uuid('tenant_id').index();
        t.json('payload');
      }),
  );
}
