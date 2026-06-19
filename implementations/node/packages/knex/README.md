# @superheld/summae-knex

Datenbank-Adapter (Node) für [`@superheld/summae-core`](../core) — persistiert
Mandanten in das **geteilte `summae_*`-Schema** der PHP-Referenz, via Knex
(Query-/Schema-Builder) + better-sqlite3 (SQLite) bzw. pg (Postgres). Damit können
PHP- und Node-Packages denselben Datenbestand teilen (SF-15).

```bash
npm install @superheld/summae-core @superheld/summae-knex better-sqlite3
```

```ts
import { Currency, TenantOperations, UuidV7IdGenerator, SystemClock } from '@superheld/summae-core';
import { SyncDb, installSchema, DatabaseTenantFactory } from '@superheld/summae-knex';

const db = new SyncDb('buchhaltung.sqlite'); // Datei oder ':memory:'
installSchema(db);                            // einmalig: summae_*-Tabellen anlegen

const clock = new SystemClock();
const tenant = DatabaseTenantFactory.build(db, 'Muster GmbH', Currency.of('EUR'), clock, new UuidV7IdGenerator(clock));
const ops = new TenantOperations(tenant);
// ops.execute('post', …) / ops.project('trialBalance', …) — persistiert in die DB
```

Vollständige API, Konfiguration und Datenformat: **[zentrales Handbuch](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.
