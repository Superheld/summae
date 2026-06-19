import Database from 'better-sqlite3';
import knexFactory from 'knex';
import type { Knex } from 'knex';

type Row = Record<string, unknown>;

/**
 * Synchrone Brücke: **Knex baut das SQL** (dialektkorrekt, später pg-portabel),
 * **better-sqlite3 führt es synchron aus**. Nötig, weil die Core-Ports synchron
 * sind (`append(): void`, `byId(): … | null`) — Knex' Promise-API würde den
 * Vertrag brechen. Knex läuft hier nur als Builder (`.toSQL()`), ohne eigene
 * Verbindung; die Verbindung ist allein better-sqlite3.
 */
export class SyncDb {
  private readonly db: Database.Database;
  /** Reiner Query-/Schema-Builder (keine Verbindung). */
  readonly knex: Knex;

  constructor(filename = ':memory:') {
    this.db = new Database(filename);
    this.db.pragma('foreign_keys = ON');
    this.knex = knexFactory({ client: 'better-sqlite3', useNullAsDefault: true });
  }

  /** Tabelle als Knex-QueryBuilder (nur zum Bauen, Ausführung via run/all/first). */
  table(name: string): Knex.QueryBuilder {
    return this.knex(name);
  }

  /** DDL (Schema kann mehrere Statements erzeugen) synchron ausführen. */
  schema(build: (s: Knex.SchemaBuilder) => Knex.SchemaBuilder): void {
    for (const stmt of build(this.knex.schema).toSQL()) {
      this.db.prepare(stmt.sql).run(...normalize(stmt.bindings));
    }
  }

  /** Schreibende Anweisung (insert/update/delete). */
  run(builder: Knex.QueryBuilder): void {
    const native = builder.toSQL().toNative();
    this.db.prepare(native.sql).run(...normalize(native.bindings));
  }

  /** Alle Treffer als rohe Zeilen. */
  all(builder: Knex.QueryBuilder): Row[] {
    const native = builder.toSQL().toNative();
    return this.db.prepare(native.sql).all(...normalize(native.bindings)) as Row[];
  }

  /** Erster Treffer oder null. */
  first(builder: Knex.QueryBuilder): Row | null {
    return this.all(builder.limit(1))[0] ?? null;
  }

  close(): void {
    this.db.close();
    void this.knex.destroy();
  }
}

/** better-sqlite3 akzeptiert kein undefined/boolean als Bindung. */
function normalize(bindings: readonly unknown[]): unknown[] {
  return bindings.map((b) => {
    if (b === undefined || b === null) return null;
    if (typeof b === 'boolean') return b ? 1 : 0;
    return b;
  });
}
