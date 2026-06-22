# @superheld/summae-knex

Database adapter (Node) for [`@superheld/summae-core`](../core) — persists
tenants into the **shared `summae_*` schema** of the PHP reference, via Knex
(query/schema builder) + better-sqlite3 (SQLite) or pg (Postgres). This lets
PHP and Node packages share the same data set (SF-15).

```bash
npm install @superheld/summae-core @superheld/summae-knex better-sqlite3
```

```ts
import { Currency, TenantOperations, UuidV7IdGenerator, SystemClock } from '@superheld/summae-core';
import { SyncDb, installSchema, DatabaseTenantFactory } from '@superheld/summae-knex';

const db = new SyncDb('accounting.sqlite'); // file or ':memory:'
installSchema(db);                            // once: create the summae_* tables

const clock = new SystemClock();
const tenant = DatabaseTenantFactory.build(db, 'Example Ltd', Currency.of('EUR'), clock, new UuidV7IdGenerator(clock));
const ops = new TenantOperations(tenant);
// ops.execute('post', …) / ops.project('trialBalance', …) — persisted to the DB
```

Full API, configuration and data format: **[central handbook](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.
