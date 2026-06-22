import Database from 'better-sqlite3';
import knexFactory from 'knex';
import type { Knex } from 'knex';

type Row = Record<string, unknown>;

/**
 * Synchronous bridge: **Knex builds the SQL** (dialect-correct, later pg-portable),
 * **better-sqlite3 runs it synchronously**. Needed because the core ports are
 * synchronous (`append(): void`, `byId(): … | null`) — Knex' Promise API would
 * break the contract. Knex runs here only as a builder (`.toSQL()`), without its own
 * connection; the connection is better-sqlite3 alone.
 */
export class SyncDb {
  private readonly db: Database.Database;
  /** Pure query/schema builder (no connection). */
  readonly knex: Knex;

  constructor(filename = ':memory:') {
    this.db = new Database(filename);
    this.db.pragma('foreign_keys = ON');
    this.knex = knexFactory({ client: 'better-sqlite3', useNullAsDefault: true });
  }

  /** Table as a Knex QueryBuilder (only for building, execution via run/all/first). */
  table(name: string): Knex.QueryBuilder {
    return this.knex(name);
  }

  /** Run DDL (schema can produce several statements) synchronously. */
  schema(build: (s: Knex.SchemaBuilder) => Knex.SchemaBuilder): void {
    for (const stmt of build(this.knex.schema).toSQL()) {
      this.db.prepare(stmt.sql).run(...normalize(stmt.bindings));
    }
  }

  /** Write statement (insert/update/delete). */
  run(builder: Knex.QueryBuilder): void {
    const native = builder.toSQL().toNative();
    this.db.prepare(native.sql).run(...normalize(native.bindings));
  }

  /** All matches as raw rows. */
  all(builder: Knex.QueryBuilder): Row[] {
    const native = builder.toSQL().toNative();
    return this.db.prepare(native.sql).all(...normalize(native.bindings)) as Row[];
  }

  /** First match or null. */
  first(builder: Knex.QueryBuilder): Row | null {
    return this.all(builder.limit(1))[0] ?? null;
  }

  close(): void {
    this.db.close();
    void this.knex.destroy();
  }
}

/** better-sqlite3 does not accept undefined/boolean as a binding. */
function normalize(bindings: readonly unknown[]): unknown[] {
  return bindings.map((b) => {
    if (b === undefined || b === null) return null;
    if (typeof b === 'boolean') return b ? 1 : 0;
    return b;
  });
}
