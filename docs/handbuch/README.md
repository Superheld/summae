# summae — Handbook

The **one** document for configuring, initializing, and using the summae
packages. Cross-language: the same API, the same data format, byte-identical
behavior in PHP and Node. Where it helps, examples are given in both languages;
the **PHP implementation is the reference**, Node mirrors it name-for-name.

> The package READMEs are deliberately thin and point here — the complete
> description lives only in this handbook.

> **Looking for a worked example rather than a reference?** The
> [CLI walkthrough](cli-walkthrough.md) runs the whole lifecycle — workspace,
> invoice, payment, settlement, reversal, reports, period and year close,
> export — with real output at every step.

> **Facing an audit?** [GoBD conformance](../gobd-conformance.md) lists every GoBD
> obligation with one of three statuses — mechanically verified (with the test that
> proves it), open (named and scoped), or not verifiable by a library at all (and
> therefore yours). Read the third group first: those are the ones nobody's green
> test suite will remind you about.

**Contents**

1. [Overview & mental model](#1-overview--mental-model)
2. [Installation](#2-installation)
3. [Initialization — creating a tenant](#3-initialization--creating-a-tenant)
4. [Configuration](#4-configuration)
5. [Setup & rule-module data format](#5-setup--rule-module-data-format)
6. [API reference: operations](#6-api-reference-operations)
   - [6.1 Call model](#61-call-model)
   - [6.2 Ledger write operations](#62-ledger-write-operations)
   - [6.3 Tax, mapping & partners](#63-tax-mapping--partners)
   - [6.4 Assets & cost accounting](#64-assets--cost-accounting)
7. [API reference: projections](#7-api-reference-projections)
8. [Value objects](#8-value-objects)
9. [Error catalog](#9-error-catalog)
10. [Determinism & data format](#10-determinism--data-format)
11. [Further reading](#11-further-reading)

---

## 1. Overview & mental model

summae is an **embeddable library**, not an application. You build a **tenant**
(`Tenant`) and talk to it through **a single entry point**: the dispatcher
`TenantOperations`. It knows two methods:

- `execute(op, input)` — **write operations** (posting, creating master data,
  closing …)
- `project(name, params)` — **read-only projections** (trial balance, balance
  sheet, income statement, cash-basis report, VAT return, export …)

Three invariants shape everything:

- **The journal is append-only.** Balances are never stored; they are
  recomputed from the journal on every evaluation. A projection is always a
  fresh view, never a cached value.
- **Money is never a float.** Amounts run through an exact decimal type
  (`Money`), commercially rounded (half-up, away from zero).
- **Determinism.** Same input → byte-identical result. Clock and ID generator
  are injectable; in production the system clock + UUIDv7, in tests a fixed
  clock + deterministic IDs.

Where the data lives is determined by the tenant's **port set** — swappable
without changing the business logic:

| Variant | Persistence | For what |
|---|---|---|
| **In-memory** (PHP + Node) | volatile (RAM) | tests, scripts, conformance runs |
| **Laravel adapter** (PHP) | database (`summae_*` tables) | production in Laravel apps |
| **Knex adapter** (Node) | database (`summae_*` tables, SQLite/Postgres) | production in Node apps |
| **CLI workspace** (PHP + Node) | local SQLite file | terminal/automation |

---

## 2. Installation

### PHP (Composer)

```bash
# Only the framework-free core
composer require superheld/summae-core

# Laravel integration (pulls core in automatically)
composer require superheld/summae-laravel

# Standalone CLI
composer require superheld/summae-cli
```

Requirements: **PHP ≥ 8.3** (recommended with the `bcmath` or `gmp` extension
for fast decimal arithmetic — it also runs without, just more slowly). For the
Laravel integration you additionally need Laravel 11 or 12 and a supported
database (MySQL, MariaDB, PostgreSQL or SQLite — engine-agnostic).

The Laravel ServiceProvider is registered automatically via package discovery —
no entry in `config/app.php` is required.

### Node (npm / pnpm / yarn)

```bash
pnpm add @superheld/summae-core      # or: npm i / yarn add
pnpm add @superheld/summae-knex      # optional: database persistence (Knex + better-sqlite3 / pg)
```

Requirement: **Node ≥ 22**. The core ships dual — **ESM** (`import`) and
**CJS** (`require`), including type declarations. The core's only runtime dependency is
`big.js`; the optional `@superheld/summae-knex` adapter adds `knex` + a driver
(`better-sqlite3` for SQLite, `pg` for Postgres).

> **Publishing status.** All packages are listed in the public registries — the commands
> above (`composer require …` / `pnpm add …`) work directly, with no further configuration.
> For the current version see the [CHANGELOG](../../CHANGELOG.md) or the registry entry.
>
> If you would rather work from the source repo: Node in the clone with
> `pnpm install && pnpm build`; PHP via a path/VCS repository pointing at the
> package directories or the split repos `Superheld/summae-{core,laravel,cli}`.

---

## 3. Initialization — creating a tenant

There are two ways to create a tenant:

1. **`createTenant` (SF-01)** — the declarative bootstrap operation: a tenant is
   created from a **profile** and versioned **rule-module data** and is
   immediately postable (see [§ 5](#5-setup--rule-module-data-format) and
   [createTenant](#createtenant-bootstrap-operation-sf-01)).
2. **Programmatically** via `Tenant::inMemory(...)` (core, in-memory ports) or a
   `DatabaseTenantFactory` for DB persistence — PHP via the **Laravel adapter**,
   Node via the **Knex adapter** (`@superheld/summae-knex`). Here you pass the
   registries (tax codes, mappings, …) yourself as ready-made objects.

Optional parameters have sensible defaults and can be supplied later.

### In-memory (PHP)

```php
use Summae\Core\Tenant;
use Summae\Core\Substrate\Currency;
use Summae\Core\Composition\TenantOperations;

$tenant = Tenant::inMemory('Example Ltd', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
// without clock/IdGenerator → SystemClock + UuidV7IdGenerator
```

`Tenant::inMemory(...)` — parameters:

| Parameter | Type | Required | Default |
|---|---|---|---|
| `name` | `string` | **yes** | — |
| `baseCurrency` | `Currency` | **yes** | — |
| `clock` | `?Clock` | no | `new SystemClock()` |
| `ids` | `?IdGenerator` | no | `new UuidV7IdGenerator($clock)` |
| `dimensions` | `?DimensionRegistry` | no | `DimensionRegistry::empty()` |
| `taxCodes` | `?TaxCodeRegistry` | no | `TaxCodeRegistry::empty()` |
| `taxProfile` | `?TaxProfile` | no | `TaxProfile::default()` (accrual, not small-business, quarterly) |
| `mappings` | `?MappingRegistry` | no | `MappingRegistry::empty()` |

### In-memory (Node)

```ts
import {
  Tenant, Currency, TenantOperations,
  SystemClock, UuidV7IdGenerator,
} from '@superheld/summae-core';

const clock  = new SystemClock();
const tenant = Tenant.inMemory('Example Ltd', Currency.of('EUR'), clock, new UuidV7IdGenerator(clock));
const ops    = new TenantOperations(tenant);
```

### Laravel adapter (PHP, persistent)

```php
use Summae\Core\Substrate\Currency;
use Summae\Core\Composition\TenantOperations;
use Summae\Laravel\DatabaseTenantFactory;

// Factory from the container; uses the configured DB connection (see § 4)
$tenant = app(DatabaseTenantFactory::class)->build('Example Ltd', Currency::of('EUR'));
$ops    = new TenantOperations($tenant);
```

`DatabaseTenantFactory::build(...)` takes the same parameters as `inMemory`,
plus one additional trailing parameter `tenantId` (`?Uuid`, default: freshly
generated) — this lets you resume an existing tenant ID. Prerequisite:
`php artisan migrate` has been run (see § 4).

### Knex adapter (Node, persistent)

```ts
import { SyncDb, installSchema, DatabaseTenantFactory } from '@superheld/summae-knex';
import { Currency, SystemClock, UuidV7IdGenerator, TenantOperations } from '@superheld/summae-core';

const db = new SyncDb('./accounting.sqlite');   // file-backed; `new SyncDb()` → in-memory SQLite
installSchema(db);                              // creates the summae_* tables (once)

const clock  = new SystemClock();
const tenant = DatabaseTenantFactory.build(
  db, 'Example Ltd', Currency.of('USD'), clock, new UuidV7IdGenerator(clock),
  { /* options: taxCodes, mappings, taxProfile, dimensions, tenantId */ },
);
const ops = new TenantOperations(tenant);
```

`DatabaseTenantFactory.build(...)` wires the same services as `Tenant.inMemory`,
only against **DB-backed ports** — the direct counterpart to PHP's Laravel adapter,
writing the **same `summae_*` schema** (the SF-15 cross-test proves byte-identical
`journalExport` across the PHP↔Node boundary on a shared database). `better-sqlite3`
is the SQLite driver; `pg` covers Postgres. The optional `options.tenantId` resumes
an existing tenant. The schema must be installed beforehand (`installSchema`).

### CLI workspace (PHP and Node)

Both CLIs (`summae`) create `summae.json` (tenant metadata + rules) and
`summae.sqlite` (postings). There are two ways to populate the tenant:

```bash
# (a) Select a shipped pack from the library (recommended)
summae init --name "Example Ltd" --pack de --first-fiscal-year 2026 --dir ./accounting

# (b) Own rules file (accounts, fiscal years, tax codes, mappings …)
summae init --name "Example Ltd" --currency EUR --rules rules.json --dir ./accounting
```

Shipped packs: **`de`** (Germany), **`us`** (United States), **`default`**
(account-less base). `--currency` is *not* derived from the pack — it defaults
to `EUR` regardless, so `--pack us` wants `--currency USD` alongside it.

`--pack de` (or `--pack us` / `--pack default`) loads the pack from the **pack library**
(`pack-library/`; overridable with `--pack-library <dir>`), resolves it, and
creates the chart of accounts, tax codes, mappings, depreciation rules, and
policy in *one* step — the chart of accounts thus comes as a **pack choice**,
not as an inline-maintained `rules.json`. `rules.json` (§ 5) remains the route
for your own/deviating rules. Every subsequent call loads the tenant from the
workspace, executes, and the SQLite file persists.

---

## 4. Configuration

### Laravel: database

The package creates its tables over a **Laravel DB connection**. By default the
**default connection** of your app — you need to set nothing further; the
`summae_*` tables land in the same database as the rest of your application.
Credentials go to the usual place:

```dotenv
# .env (standard Laravel, nothing package-specific)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=myproject
DB_USERNAME=app
DB_PASSWORD=secret
```

**Separate database for the accounting** (optional, e.g. for compliance
reasons): define a second connection in `config/database.php` and assign it to
the package:

```php
// config/database.php → 'connections'
'accounting' => [
    'driver'   => 'pgsql',
    'host'     => env('SUMMAE_DB_HOST', '127.0.0.1'),
    'port'     => env('SUMMAE_DB_PORT', '5432'),
    'database' => env('SUMMAE_DB_DATABASE', 'accounting'),
    'username' => env('SUMMAE_DB_USERNAME'),
    'password' => env('SUMMAE_DB_PASSWORD'),
],
```

```dotenv
SUMMAE_DB_CONNECTION=accounting   # the only package-specific setting; empty = app default
```

Migration and (optional) publishing of the config:

```bash
php artisan migrate                              # creates the summae_* tables
php artisan vendor:publish --tag=summae-config   # optional: config/summae.php (only 'connection')
```

The migration is shipped in the package and is found automatically — for
standard use no `vendor:publish` is needed.

### CLI: workspace

The CLI needs **no** database credentials. In the working directory (`--dir`,
default: current directory) it creates two files:

| File | Content |
|---|---|
| `summae.json` | tenant metadata (name, currency, `tenantId`) + rule-module data |
| `summae.sqlite` | the posting data (database/SQLite) |

### Node: in-memory or Knex adapter

**In-memory** needs no configuration — the only things you control are the
determinism hooks (`Clock`, `IdGenerator`) as constructor parameters (see § 10).

**Persistent** (`@superheld/summae-knex`): configuration is the `SyncDb` connection
you pass — `new SyncDb('./accounting.sqlite')` for a file, `new SyncDb()` for an
in-memory SQLite, or a Postgres connection via `pg`. `installSchema(db)` creates the
`summae_*` tables (the same schema PHP's Laravel adapter migrates). No framework
config files; the connection is code.

---

## 5. Setup & rule-module data format

Master data enters the tenant through two combinable styles:

- **Profile style** — `ruleModules.profiles[]` + `chartsOfAccounts[]` +
  `taxCodes[]`; from this `createTenant` builds the tenant (CLI `rules.json`,
  fixtures).
- **Direct style** — for programmatic creation you pass the ready-made
  registries (`TaxCodeRegistry`, `MappingRegistry`, `DimensionRegistry`,
  `TaxProfile`) to `inMemory`/`build`.

The following structures are the authoritative format (from code + fixtures).

### Writing your own pack by hand

A **pack** is a folder `pack-library/<name>-pack/` containing **module files** +
a **manifest**. A **module** is a data file that *serves exactly one policy
kind* — the kind follows unambiguously from its `kind`. For each policy kind you
want to serve, you create one module:

| if you want to serve … | `kind` | `data` contains |
|---|---|---|
| chart of accounts (substrate) | `accounts` | `accounts[]` (`number/name/type/subtype?`) |
| tax (expansion) | `tax` | `taxCodes[]` (`code`, `versions[]` with `rate/mechanism/taxAccount/reportingKey`) |
| balance sheet / income statement / cash-basis (projection) | `mapping` | `mapping` (`kind: balance-sheet\|income-statement\|cash-basis-categories`, `positions[]`) |
| depreciation (expansion) | `depreciation` + `assetAccounts` | depreciation tables resp. the 5 asset contra-accounts |
| rounding/scale (parameters) | `policy` | `packPolicy` (`roundingMode/taxRoundingGranularity/currencyScale`) |

Module skeleton (`pack-library/<name>-pack/<kind>/<id>.json`):
```json
{ "formatVersion": "0.6", "id": "de-ust", "kind": "tax", "version": "2026.1",
  "contributes": ["taxCodes"], "dependsOn": [{ "kind": "accounts", "id": "de-konten" }],
  "data": { "taxCodes": [ { "code": "USt19", "versions": [
    { "validFrom": "2024-01-01", "validTo": null, "rate": "19.00", "mechanism": "standard",
      "taxAccount": "3100", "reportingKey": "81" } ] } ] } }
```

Manifest (`pack-library/<name>-pack/<id>.json`) — lists the modules, carries
`packPolicy` + `defaults`:
```json
{ "formatVersion": "0.6", "id": "de", "version": "2026.2",
  "modules": [ { "kind": "accounts", "id": "de-konten", "version": "2026.2" },
               { "kind": "tax", "id": "de-ust", "version": "2026.1" } ],
  "packPolicy": { "roundingMode": "halfUpAwayFromZero", "taxRoundingGranularity": "perVoucher", "currencyScale": 2 },
  "defaults": { "taxationMethod": "cash", "smallBusiness": false, "vatPeriod": "quarterly" } }
```

**Versions move per module.** Each module carries its own `version`, the manifest
pins the exact one it wants, and the pack's own version rises whenever any of its
modules changes — the two lines above show it: the chart of accounts moved to
`2026.2`, the tax module stayed at `2026.1`. A tenant records the pack id and
version it was created from, so the books always say which rule set produced
them. `YYYY.N` is the shipped convention, not a requirement: the resolver only
compares strings.

Choose it with `summae init --pack de`. The **resolver checks coherence** (does
a tax account point at an account the chart of accounts doesn't have? does a
mapping hit no accounts?) and **fails loudly** (`E_PACK_UNRESOLVED_REF` /
`E_PACK_INCOHERENT`) instead of silently computing the wrong thing. **Packs are
self-contained** — all modules in their own folder, unique IDs, no shared
`modules/`. Full template: `pack-library/de-pack/`.

Every module and manifest in the pack library is additionally **validated
against `testing/testsuite/schema/format.schema.json`** in both languages — a misspelled
field or an undeclared key fails loudly instead of being silently ignored.
Validation runs in the test runners, so the core stays framework-free.

### `profiles[]`

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `id` | string | yes | referenced from `createTenant.input.profile` |
| `name` | string | — | display name |
| `version` | string | yes | pinned into the tenant (output `profile.version`) |
| `chartOfAccounts` | string | yes | ID of an entry in `chartsOfAccounts[]` |
| `taxCodes` | list\<string\> | — | codes expanded from `taxCodes[]` |
| `mappings` | list | — | statement mappings |
| `defaults` | object | — | tax defaults → `TaxProfile` (see below) |

### `chartsOfAccounts[]` + `accounts[]`

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `id` | string | yes | referenced by the profile |
| `accounts[].number` | string | yes | account number (codepoint comparison, leading zeros significant) |
| `accounts[].name` | string | yes | account label |
| `accounts[].type` | string (enum) | yes | `asset`, `liability`, `equity`, `expense`, `revenue` |
| `accounts[].subtype` | string\|null | — | free marker; among other things drives the open-item automation |

`type` determines the balance mechanics: `asset`/`liability`/`equity` are
balance-carrying (carry forward across years), `expense`/`revenue` are per
fiscal year. `subtype` is a free string in the code (no enum check); used in
fixtures: `bank`, `cash`, `ar`, `ap`, `tax_in`, `tax_out`, `fixed_asset`,
`opening_balance`, `transit`.

```json
"chartsOfAccounts": [
  { "id": "coa-mini-test",
    "accounts": [
      { "number": "1200", "name": "Bank", "type": "asset", "subtype": "bank" },
      { "number": "8400", "name": "Revenue 19%", "type": "revenue" },
      { "number": "1776", "name": "USt 19%", "type": "liability", "subtype": "tax_out" }
    ] }
]
```

### `taxCodes[]` with `versions[]`

A code bundles time-staggered versions.

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `code` | string | yes | key (leading; your own codes before DATEV) |
| `versions[].validFrom` | string (date) | yes | start of validity (zoneless) |
| `versions[].validTo` | string\|null | — | end; `null` = open |
| `versions[].rate` | string (decimal) | — | tax rate, e.g. `"19.00"`; default `"0"` |
| `versions[].taxAccount` | string | — | tax account |
| `versions[].reportingKey` | string\|null | — | VAT-return key (e.g. `"81"`, `"66"`, `"41"`) |
| `versions[].mechanism` | string | — | default `"standard"`; see the mechanism table below |
| `versions[].inputTaxAccount` | string\|null | — | input-tax account (e.g. reverse charge) |
| `versions[].inputReportingKey` | string\|null | — | input-tax key |
| `versions[].baseReportingKey` | string\|null | — | tax-base key |

```json
"taxCodes": [
  { "code": "USt19", "versions": [
      { "validFrom": "2024-01-01", "validTo": null, "rate": "19.00", "taxAccount": "1776", "reportingKey": "81" } ] },
  { "code": "igL", "versions": [
      { "validFrom": "2024-01-01", "validTo": null, "rate": "0.00", "mechanism": "intra_community_supply", "reportingKey": "41" } ] }
]
```

Accessing an undefined key → `E_TAXCODE_UNKNOWN`.

#### Tax mechanisms

A **mechanism** is the law-free strategy that turns one tax code's net base into
tax line(s), a base tag, and a gross delta. The pack only *selects* one per tax
code — it carries no code of its own. Four are registered:

| `mechanism` | Tax lines | Gross | Notable |
|---|---|---|---|
| `standard` (default) | one, on the output/input side | net + tax | the ordinary case |
| `reverse_charge` | two — VAT (credit) *and* input tax (debit), each with its own key | net (unchanged) | requires `inputTaxAccount`; counts as `input` in the VAT return |
| `intra_community_supply` | none — base tagged only | net | feeds the **EC sales list** |
| `exempt` | none — base tagged only | net | mechanically like IC supply, but deliberately a *separate* mechanism so the EC sales list does **not** pick it up |

Why `exempt` exists as its own mechanism rather than a rate-0 `standard` code: a
0.00 tax line is rejected on posting (`E_ENTRY_INVALID_AMOUNT`), so an exempt
sale could be previewed with `expandTax` but never recorded in the journal. The
mechanism emits no tax line at all and posts cleanly.

> **Unknown mechanism names fall back to `standard`** rather than raising. The
> registry is core-internal today; whether composition may register mechanisms
> from outside is an open architecture decision (`core/src/CLAUDE.md`).

### `taxProfile` / `defaults`

Directly as `setup.tenant.taxProfile` or as `profile.defaults`.

| Field | Type | Default | Meaning |
|------|-----|---------|-----------|
| `taxationMethod` | `"cash"` \| `"accrual"` | `accrual` | cash vs. accrual taxation (anything ≠ `"cash"` ⇒ accrual) |
| `vatPeriod` | `"monthly"` \| `"quarterly"` | `quarterly` | VAT-return period |
| `smallBusiness` | bool \| list | `false` | small-business scheme; as bool or as a segment list `[{validFrom, value}]` for a mid-year switch |

### `dimensionTypes[]` / `dimensionValues[]` / `dimensionRules[]`

| Block | Field | Type | Meaning |
|-------|------|-----|-----------|
| `dimensionTypes[]` | `code` | string | type code (e.g. `costCenter`) |
| `dimensionValues[]` | `typeCode` / `code` | string | reference to type / value code (unique per `typeCode:code`) |
| `dimensionRules[]` | `accountRange.from`/`.to` | string | account-number range (codepoint comparison) |
| | `requiredDimension` | string | type mandatory within this range |

A violation ⇒ `E_DIMENSION_INVALID` (unknown type/value or missing mandatory
dimension).

```json
"dimensionTypes": [ { "code": "costCenter", "name": "Kostenstelle" } ],
"dimensionValues": [ { "typeCode": "costCenter", "code": "A", "name": "Stelle A" } ],
"ruleModules": { "dimensionRules": [ { "accountRange": { "from": "4000", "to": "4999" }, "requiredDimension": "costCenter" } ] }
```

### `mappings[]`

Statement mappings (balance sheet, income statement, cash-basis categories).
Nodes with `children[]` are resolved recursively; leaves carry `accounts[]`
(selectors: ranges `{from,to}` and/or single accounts `{numbers:[…]}`).

| Field | Type | Meaning |
|------|-----|-----------|
| `id` | string | mapping ID (referenced by projections) |
| `kind` | string | `balance-sheet`, `income-statement`, `cash-basis-categories` |
| `version` | string | version |
| `positions[].key` / `.label` | string | position key / display (default = key) |
| `positions[].side` | string\|null | set at the root node, inherited to leaves |
| `positions[].accounts[]` | list | account selectors |
| `positions[].includeNonCash` / `includesNetIncome` | bool | cash-basis / balance-sheet flags |

```json
"mappings": [
  { "id": "test-bilanz", "kind": "balance-sheet", "version": "1",
    "positions": [
      { "key": "A.1", "label": "Liquide Mittel", "accounts": [ { "from": "1200", "to": "1299" } ] },
      { "key": "A.2", "label": "Forderungen", "accounts": [ { "from": "1400", "to": "1499" } ] }
    ] }
]
```

---

## 6. API reference: operations

### 6.1 Call model

All write operations run through the dispatcher:

```php
$tenantOperations->execute(string $op, array $input): array;   // PHP
```
```ts
tenantOperations.execute(op, input);                           // Node
```

Conventions for this whole section:

- Money values are always objects `{"amount":"119.00","currency":"EUR"}`. Foreign currency is rejected in v1 (only the tenant currency counts).
- Every input may optionally carry `actor` (string) → audit trail, default `"system"`.
- Errors are thrown as a `DomainError` with an `E_*` code (see § 9); when posting, **only the first** error in fixed check order is returned.

#### createTenant (bootstrap operation, SF-01)

Create a tenant from a profile — **not** a normal `execute` op, but a bootstrap
via the `TenantFactory` (dispatched as `op: createTenant` in runner/CLI). The
profile references a chart of accounts + tax codes; the factory expands both,
pins the profile version, and optionally creates the first fiscal year.

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `name` | string | no (default `"Tenant"`) | display name |
| `baseCurrency` | string (ISO-4217) | no (default `"EUR"`) | base currency |
| `profile` | string | **yes** | profile ID from `profiles[]`; unknown → `E_PROFILE_UNKNOWN` |
| `firstFiscalYear` | int | no | when `> 0`, fiscal year `YYYY-01-01…YYYY-12-31` is created |

Output: `id`, `name`, `profile.{id,version}`, `accountCount`, `taxationMethod`.
Error: `E_PROFILE_UNKNOWN` (profile **or** its chart of accounts is missing).

```json
{ "op": "createTenant",
  "input": { "name": "Mustermann Consulting", "baseCurrency": "EUR", "profile": "de-freiberufler-euer", "firstFiscalYear": 2026 },
  "expect": { "result": { "id": "$T1", "profile": { "id": "de-freiberufler-euer", "version": "2026.1" }, "accountCount": 3, "taxationMethod": "cash" } } }
```

### 6.2 Ledger write operations

#### post

Records a posting in the journal (append-only). Automatically creates open items
when posting to AR/AP accounts (debit on `subtype:"ar"` → `receivable`, credit
on `subtype:"ap"` → `payable`).

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `voucherId` | string (UUID) | yes | existing voucher; no posting without a voucher |
| `entryDate` | string (`YYYY-MM-DD`) | yes | posting date (zoneless); determines fiscal year + period |
| `lines` | array | yes | posting lines, at least 2 |
| `text` | string | no | posting text (default `""`) |

Posting line (`lines[]`): `account` (string, yes), `side`
(`"debit"`/`"credit"`, yes), `money` (Money > 0, yes), `dimensions`
(`[{type,code}]`, no), `taxTag` (object, no).

**Check order / error codes:** 1) structure `E_ENTRY_TOO_FEW_LINES`,
`E_ENTRY_INVALID_AMOUNT`; 2) references `E_ENTRY_NO_VOUCHER`,
`E_VOUCHER_UNKNOWN`, `E_ACCOUNT_UNKNOWN`, `E_ACCOUNT_LOCKED`,
`E_DIMENSION_INVALID`; 3) balance `E_ENTRY_UNBALANCED`; 4) time
`E_PERIOD_UNKNOWN`, `E_PERIOD_CLOSED`.

Output: serialized posting (`id`, `sequenceNumber`, `status`, `entryDate`,
`periodRef`, `lines[]`, `reverses`/`reversedBy`, …) plus `openItemsCreated[]`.

```json
// input
{ "entryDate": "2026-03-05", "voucherId": "$V1", "text": "Barverkauf",
  "lines": [
    { "account": "1200", "side": "debit",  "money": { "amount": "119.00", "currency": "EUR" } },
    { "account": "8400", "side": "credit", "money": { "amount": "100.00", "currency": "EUR" } },
    { "account": "1776", "side": "credit", "money": { "amount": "19.00",  "currency": "EUR" } }
  ] }
// → result.sequenceNumber: 1, result.status: "entered"
```

A posting to an AR account creates `openItemsCreated: [{ "kind": "receivable", "money": {…} }]`.

#### postVoucher

The single-call standard case (SF-02/03): it **creates the voucher**,
**expands the tax** from net lines + `taxCode`, and **posts**, all in one step.
Unlike `post`, you supply voucher data and net lines; the gross contra-account
and tax lines are produced automatically.

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `voucher` | object | yes | voucher data |
| `voucher.voucherNumber` | string | yes | voucher number |
| `voucher.voucherDate` | string (date) | yes | missing/invalid → `E_ENTRY_NO_VOUCHER` |
| `voucher.partnerId` | string | no | must exist (`E_PARTNER_UNKNOWN`) |
| `voucher.supplierTaxationMethod` | string | no | `"accrual"` \| `"cash"` — how the *supplier* taxes; anything else is `E_INPUT_INVALID` |
| `taxCode` | string | no | tax code for the expansion |
| `direction` | string | no | `"output"` (default) or `"input"` |
| `netLines` | array of `{account, money}` | no | net lines |
| `counterAccount` | string | yes | gross contra-account (bank/receivable) |
| `entryDate` | string | no | default = `voucher.voucherDate` |

Output: `entry` (like `post`), `openItemsCreated[]`, `grossTotal` (Money),
`taxLines[]`, `voucherId`.

> **A tax code is required in practice.** `taxCode` is formally optional, but a
> net line without one — and without a pack default — is rejected with
> `E_TAXCODE_UNKNOWN` ("line without tax code (no default set)"). A
> configuration that carries no tax codes at all (e.g. `--pack default`)
> therefore posts via `createVoucher` + `post` rather than `postVoucher`.

```json
// input
{ "voucher": { "voucherNumber": "AR-001", "voucherDate": "2026-02-10" },
  "entryDate": "2026-02-10", "text": "Consulting February",
  "taxCode": "USt19", "direction": "output",
  "netLines": [ { "account": "8400", "money": { "amount": "1000.00", "currency": "EUR" } } ],
  "counterAccount": "1200" }
// → grossTotal: {"amount":"1190.00","currency":"EUR"} (net 1000 + 19% VAT)
```

#### createVoucher

Creates a voucher **without** posting — the precursor to `post`, which requires
an existing `voucherId`. Takes the same `voucher` object as `postVoucher`
(fields nested under `voucher`, **not** at the top level).

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `voucher.voucherNumber` | string | no (default `""`) | voucher number |
| `voucher.voucherDate` | string (date) | **yes** | missing/invalid → `E_ENTRY_NO_VOUCHER` |
| `voucher.partnerId` | string | no | must exist (`E_PARTNER_UNKNOWN`) |
| `voucher.supplierTaxationMethod` | string | no | `"accrual"` \| `"cash"` — how the *supplier* taxes; anything else is `E_INPUT_INVALID` |
| `voucher.due`, `serviceDate`, `servicePeriod.{from,to}`, `kind`, `issuer`, `economicYear`, `recurring` | — | no | optional voucher attributes |

Output: `{ "id": <uuid>, "voucherNumber": <string> }`.

```json
{ "voucher": { "voucherNumber": "BK-001", "voucherDate": "2026-03-05" } }
// → { "id": "01a0…", "voucherNumber": "BK-001" }
```

#### correct

Changes the text and/or lines of a posting — only in status `entered`, with an
audit trail (no deletion). `entryId` (yes), `text` (no), `lines` (no, ≥ 2 &
balanced). Output: serialized (changed) posting. Errors: `E_ENTRY_UNKNOWN`,
`E_ENTRY_FINALIZED`, `E_INPUT_INVALID`, `E_ENTRY_HAS_OPEN_ITEMS`, plus the
`lines` errors of `post`.

**At least one of `text`/`lines` must be present** (`E_INPUT_INVALID`). A call
with neither used to return the unchanged posting as a success payload, so a
misspelled field (`txt` instead of `text`) looked like a correction that had
happened.

**The `lines` of a posting that produced open items cannot be changed**
(`E_ENTRY_HAS_OPEN_ITEMS`). The subledger would go on naming an amount, an
account and a due date from a posting that no longer exists. Correcting the
**text** stays allowed; for amounts the GoBD-conform route is `reverse` plus a
fresh posting, which keeps ledger and subledger together.

#### finalize

Finalizes postings (`entered` → `finalized`). Individually (`entryId`) or as a
bulk trigger (`finalizeUntil`: all up to and including the date). Idempotent.
Output: `{ "finalizedCount": <int> }`. Error: `E_ENTRY_UNKNOWN` (neither field
set, or unknown `entryId`).

```json
{ "finalizeUntil": "2026-01-31" }   // → { "finalizedCount": 1 }
```

#### reverse

Reversal by full counter-entry: a new posting with a back-reference
(`reverses`), same accounts/sides, **negated amounts**. `entryId` (yes),
`entryDate` (yes, open period), `text` (no, default `"Reversal <seqNo>"`). Output:
serialized reversal posting; the original gets `reversedBy`. Errors:
`E_ENTRY_UNKNOWN`, `E_ENTRY_ALREADY_REVERSED`, `E_PERIOD_UNKNOWN`,
`E_PERIOD_CLOSED`, `E_ENTRY_HAS_SETTLED_ITEMS`.

```json
// input { "entryId": "$E1", "entryDate": "2026-02-03", "text": "Reversal Office supplies" }
// → lines with money "-240.00", reverses: "$E1"
```

**Open items are cleared along with it.** If the reversed posting produced open
items, each of them is settled against the reversal — a settlement carrying
`"cause": "cancellation"`, which puts the item into status `cancelled` and takes
it out of `openItems`. Nothing is deleted; the item keeps its record and its
history. `cancelled` rather than `settled` on purpose: no money arrived, and a
cash-basis VAT return must not count it as received.

**A reversal is refused once an open item has been touched**
(`E_ENTRY_HAS_SETTLED_ITEMS`). If a payment has already been allocated to the
item, cancelling it would drop that payment out of the open-item history while
the ledger still carries it. Post a credit note or a refund instead — which is
also the correct tax treatment, because a correction belongs in the period in
which it happened, not in the period of the original invoice.

#### settle

Settles open items — an explicit allocation payment → item, also partial,
optionally with a difference (discount/bad debt/minor difference). `entryId`
(yes, the payment posting), `allocations` (yes, ≥ 1), `actor` (no).

Allocation (`allocations[]`): `openItemId` (yes), `money` (Money > 0, including
the difference, yes), `difference` (`{money, kind}` with kind
`"discount"`/`"bad_debt"`/`"minor"`, no).

Output: `{ "openItems": [ … ] }` (affected items with `remaining`, `status` ∈
`open`/`partially_settled`/`settled`/`cancelled`, `settlements[]`). `cancelled`
never comes from `settle` — only a reversal produces it. Errors:
`E_ENTRY_UNKNOWN`, `E_OPENITEM_UNKNOWN`, `E_SETTLEMENT_EXCEEDS_ITEM`,
`E_SETTLEMENT_EXCEEDS_ENTRY`, `E_SETTLEMENT_DIFFERENCE_INVALID`. Validation is
all-or-nothing.

**An allocation may not claim more than the settling posting actually moves on
that account** (`E_SETTLEMENT_EXCEEDS_ENTRY`). The item's remaining amount alone
is not the bound: a 500.00 payment must not close a 1190.00 receivable in full,
or the general ledger keeps a receivable the subledger no longer knows about —
and under cash-basis taxation the VAT return declares tax as collected that never
arrived. The bound is the posting's **net reducing** movement on the account, so
a payment with a cash discount (which books the full receivable and carries the
difference as its own line) stays valid. Settlements already recorded against the
same posting count against the same budget.

```json
// Partial payment
{ "entryId": "$E2", "allocations": [ { "openItemId": "$OP1", "money": { "amount": "500.00", "currency": "EUR" } } ] }
// → remaining 690.00, status "partially_settled"

// with cash discount
{ "entryId": "$E2", "allocations": [ { "openItemId": "$OP1",
  "money": { "amount": "1190.00", "currency": "EUR" },
  "difference": { "money": { "amount": "23.80", "currency": "EUR" }, "kind": "discount" } } ] }
// → remaining 0.00, status "settled"
```

#### createAccount

`number` (yes), `name` (yes), `type` (yes:
asset/liability/equity/expense/revenue), `subtype` (no), `status` (no:
`active`/`locked`). Output: serialized account. Errors:
`E_ACCOUNT_NUMBER_TAKEN`, `E_COA_FORMAT_INVALID`.

#### importChartOfAccounts

Atomic chart-of-accounts import: validate everything first, then create. `rows`
(yes, non-empty; each row carries fields like `createAccount`), `format` (no,
not evaluated in the core). Output: `{ "importedCount": <int> }`. Errors:
`E_COA_FORMAT_INVALID`, `E_ACCOUNT_NUMBER_TAKEN` (also a duplicate within the
batch).

#### lockAccount

Locks an account (`active` → `locked`); afterwards `E_ACCOUNT_LOCKED` on `post`.
`number` (yes). Output: serialized account with `status:"locked"`. Error:
`E_ACCOUNT_UNKNOWN`.

#### createFiscalYear

`year` (yes), `start` (yes), `end` (yes). Without explicit periods, 12 months.
Output: `{ "year": <int>, "periodCount": <int> }`. Error:
`E_FISCALYEAR_OVERLAP` (date overlap or same `year`).

#### closePeriod / reopenPeriod

`fiscalYear` (yes), `period` (yes). Closing only in order. Output:
`{ "fiscalYear", "period", "status" }` (`"closed"` resp. `"open"`). Errors:
`E_PERIOD_UNKNOWN`, `E_PERIOD_OUT_OF_ORDER` (close only), `E_FISCALYEAR_CLOSED`.

#### closeFiscalYear

A pure status change — **no** closing entries. Prerequisite: all periods closed
**and** all postings finalized. `fiscalYear` (yes). Output:
`{ "fiscalYear", "status": "closed" }`. Errors: `E_PERIOD_UNKNOWN`,
`E_PERIOD_OUT_OF_ORDER`, `E_FISCALYEAR_UNFINALIZED_ENTRIES`.

### 6.3 Tax, mapping & partners

#### expandTax

A pure, side-effect-free function: expands net positions into complete posting
lines including tax lines, tax tags, and the gross total (the precursor to
`postVoucher`); changes no state.

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `date` | string (date) | yes | voucher date; version selection if no `serviceDate` |
| `serviceDate` | string (date) | no | service date (§ 27 UStG); takes precedence in version selection |
| `direction` | string | no | `output` (default, credit) or `input` (debit) |
| `taxCode` | string | no | default key for positions without their own |
| `netLines` | array | yes | ≥ 1 net position (`account`, `money`, optional `taxCode`) |

Calculation: tax **per voucher and per rate** (net total per key, rounded
half-up once — not per position); groups sorted by tax account (codepoints).
Small business → no `taxLines`, `taxTag` = null, `grossTotal` = net. Reverse
charge → VAT credit + input-tax debit, `grossTotal` = net. Intra-community
supply → tax-free, only a key tag.

Output: `netLines[]` (with `side`, `taxTag`), `taxLines[]`, `grossTotal`.
Errors: `E_ENTRY_TOO_FEW_LINES`, `E_TAXCODE_UNKNOWN`,
`E_TAXCODE_NO_VALID_VERSION`, `E_ENTRY_INVALID_AMOUNT`.

```json
// input — three lines of 0.33 each → tax rounded per voucher
{ "date": "2026-05-10", "taxCode": "USt19", "direction": "output",
  "netLines": [ {"account":"8400","money":{"amount":"0.33","currency":"EUR"}},
                {"account":"8400","money":{"amount":"0.33","currency":"EUR"}},
                {"account":"8400","money":{"amount":"0.33","currency":"EUR"}} ] }
// → taxLine 1776 credit 0.19 (0.99 × 19% = 0.1881 → 0.19), grossTotal 1.18
```

#### setTaxProfile

Sets/changes the small-business status as of a cutoff date. ⚠ In the code
`setProfile()` evaluates only the `smallBusiness` block;
`taxationMethod`/`vatPeriod` come from the tenant configuration (yet are still
included in the output).

`smallBusiness` (yes): `{ validFrom (yes), value (bool, default false) }`.
Output: the serialized `TaxProfile` (`taxationMethod`, `vatPeriod`,
`smallBusiness[]` sorted by `validFrom`). Error:
`E_PROFILE_RETROACTIVE_CONFLICT` (no `validFrom`, or postings already finalized
as of the cutoff date).

```json
{ "smallBusiness": { "validFrom": "2026-07-01", "value": false } }
// → smallBusiness: [ {"validFrom":"2026-01-01","value":true}, {"validFrom":"2026-07-01","value":false} ]
```

#### importMapping

Imports a statement mapping (balance sheet / income statement / cash-basis).
Checks every relevant account against the positions; overlap is an error, gaps
are warnings. Input under `mapping`: `id` (yes), `kind` (yes), `version` (no),
`positions[]` (yes, structure see § 5).

Output: `{ "imported": true, "id", "kind", "gapWarnings": [ { "account", "assignedTo": "_unassigned" } ] }`.
Error: `E_MAPPING_OVERLAP` (account in more than one position).

#### createPartner

Lean partner master data (open items per partner, VAT ID, EC sales list, DATEV).
All fields optional with defaults: `name` (`""`), `kind`
(`customer`/`supplier`/`both`, default `both`), `vatId`, `paymentTermsDays`,
`accountNumbers[]`, `address`. Output: serialized partner with a generated `id`.
Writes an audit entry.

```json
{ "name": "Alpen Handel GmbH", "kind": "customer", "vatId": "ATU12345678", "paymentTermsDays": 30, "accountNumbers": ["1400"] }
```

#### updatePartner

Updates existing partners; only changed fields are written (diff in the audit
trail). `partnerId` (yes), `name`/`vatId`/`kind`/`paymentTermsDays` (no).
`vatId: null` clears the VAT ID; `accountNumbers`/`address` are not changed
here. Output: serialized partner. Error: `E_PARTNER_UNKNOWN`.

### 6.4 Assets & cost accounting

The asset operations need a **rule module** in the tenant setup (`ruleModule`)
with `gwgThresholds` (dated low-value-asset thresholds), `usefulLife` (useful
life per `assetClass` in months), and `assetAccounts`
(`acquisitionCounterAccount`, `depreciationExpenseAccount`,
`gwgExpenseAccount`). A threshold that opens a pool range (`poolMin`/`poolMax`)
must give **two** answers: `poolYears` — the number of years a pooled asset is
written off over — and `poolReducedOnDisposal` — whether a disposal takes the
item out of the pool. Both are required rather than defaulted, because both are
jurisdiction law and neither follows from pooling as such: Germany writes off
over five years and does *not* reduce the pool on disposal, while the UK and
Australia do reduce theirs. The engine never picks for you (`acquireAsset` and
`disposeAsset` answer `E_PACK_INCOHERENT` if either is missing). Asset postings are finalized immediately (GoBD); cost
accounting is a separate accounting circle and leaves the financial-accounting
journal untouched.

```json
"ruleModule": {
  "gwgThresholds": [ { "validFrom": "2018-01-01", "validTo": null, "immediateMax": "800.00", "poolMin": "250.01", "poolMax": "1000.00", "poolYears": 5, "poolReducedOnDisposal": false } ],
  "usefulLife": [ { "assetClass": "it-hardware", "months": 36 } ],
  "assetAccounts": { "acquisitionCounterAccount": "1200", "depreciationExpenseAccount": "4830", "gwgExpenseAccount": "4855",
                     "disposalProceedsAccount": "8801", "disposalLossAccount": "2320" }
}
```

#### acquireAsset

Records an acquisition and decides the low-value-asset (GWG) routing.

| Field | Type | Required | Meaning |
|---|---|---|---|
| `name` | string | no | label |
| `assetClass` | string | yes when capitalizing | key in `usefulLife` |
| `assetAccount` | string | yes | asset account |
| `acquisitionCost` | Money | yes | acquisition/production cost |
| `acquiredOn` | string (date) | yes | determines the GWG threshold |
| `voucherId` | string (UUID) | yes | voucher (missing → `InvalidValue` ⚠) |
| `gwgChoice` | string | no (`"auto"`) | otherwise `capitalize`/`immediate_expense`/`pool` |
| `usefulLifeMonths` | integer | no | overrides the `usefulLife` lookup for THIS asset; capitalized route only |
| `depreciationMethod` | string | no (`"straight_line"`) | otherwise `declining_balance`; capitalized route only |
| `dimensions` | array of `{type, code}` | no | cost centre etc.; **every** machine entry about this asset inherits them |

GWG routing with `auto`: cost ≤ `immediateMax` → `immediate_expense`;
`poolMin` ≤ cost ≤ `poolMax` → `pool` (over `poolYears`); otherwise →
`capitalize` (useful life from `usefulLife`). Output: serialized asset (`route`,
`usefulLifeMonths`, `depreciationMethod`, …; for `immediate_expense` additionally `expenseAccount`).
Errors: `E_ASSET_UNKNOWN` (no useful life), `E_ACCOUNT_UNKNOWN`, `E_PACK_INCOHERENT`
(declining balance asked for, no rule in force on `acquiredOn`), `E_INPUT_INVALID`.

**`usefulLifeMonths` — when the table is not enough.** The pack's `usefulLife` holds class
averages. Where a jurisdiction lets you prove a different life for an individual asset, no table
can express that, so you pass it here and it wins over the lookup. It also makes an asset class
the pack does not know usable at all, instead of `E_ASSET_UNKNOWN`.

**`depreciationMethod: "declining_balance"`.** A fixed percentage of the *remaining* carrying
amount each year, switching to straight line over the remaining life at the point where that
yields more, with the final year taking the remainder so the schedule sums to the acquisition
cost exactly. The percentage is `min(factor × straight-line rate, cap)` and comes from the pack's
`decliningBalance` entry in force on `acquiredOn` — including its validity window, which is often
short. Ask for the method outside every declared window and you get `E_PACK_INCOHERENT`, never a
rate the library made up.

**Both parameters are refused, not ignored, on the other routes.** A pooled asset takes its term
from `poolYears` and an immediately expensed one has no schedule, so passing either there is
`E_INPUT_INVALID`. Neither parameter implies a route: if you want capitalization, say
`gwgChoice: "capitalize"`.

```json
{ "name": "Laptop", "assetClass": "it-hardware", "assetAccount": "0420",
  "acquisitionCost": { "amount": "3000.00", "currency": "EUR" },
  "acquiredOn": "2026-07-01", "voucherId": "$V1", "gwgChoice": "auto",
  "dimensions": [ { "type": "costCenter", "code": "IT" } ] }
// → route "capitalize", usefulLifeMonths 36
```

> **Set `dimensions` here if any account involved requires one.** Acquisition,
> every depreciation run and the disposal are machine entries — nobody is present
> to name a cost centre at that moment, so they take the asset's. Without it, a
> mandatory dimension on the depreciation account makes depreciation impossible
> to run at all.

#### disposeAsset

`assetId` (yes), `disposedOn` (yes), `proceeds` (no), `proceedsAccount` (no,
default: the pack's `disposalProceedsAccount`), `bankAccount` (no, default
`acquisitionCounterAccount`), `voucherId` (no). Output: serialized asset with
`status:"disposed"`. Errors: `E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`.

The disposal books the whole event, in this order:

1. **Depreciation owed up to `disposedOn`**, if a run has not booked it yet — a
   plan month falls due on its last day. Without this the write-off would use a
   stale carrying amount, and those months would never be depreciated at all,
   since `runDepreciation` skips disposed assets.
2. **The carrying amount off the asset account**, and the difference to the
   proceeds as a gain (`disposalProceedsAccount`) or a loss
   (`disposalLossAccount`) — a scrapping without proceeds is the loss case. A
   fully depreciated asset scrapped for nothing books no entry rather than an
   empty one.

**Exception — pooled assets whose pack keeps them in the pool**
(`poolReducedOnDisposal: false`): nothing is written off, the pool keeps running
its term, and only the proceeds are booked.

⚠ **The month of departure:** depreciation is owed for plan months that have
fallen due, so an asset disposed mid-month gets nothing for that month. Whether
a jurisdiction grants the whole month is a pack question and not answered yet.

#### runDepreciation

Depreciation run, idempotent. `fiscalYear` (yes); with `period` a monthly run,
without it a yearly run. Disposed assets are skipped — **except** pooled ones
whose pack does not reduce the pool on disposal: those keep depreciating until
the term ends, no matter what happened to the individual items. Distribution via largest-remainder (Σ = acquisition
cost exactly). Output: `{ "entriesCreated", "totalDepreciation" }`, resp. on a
no-op `{ "alreadyRun": true, "entriesCreated": 0 }`. Error: `E_PERIOD_UNKNOWN`.

```json
{ "fiscalYear": 2026 }   // → entriesCreated 1, totalDepreciation 500.00 (6/36 of 3000)
```

#### setAllocationScheme

Allocation scheme. `method` (no, default `"step_ladder"`), `steps[]` (`sender` yes,
`receivers[].code` yes, `receivers[].share` no, default `"1"`). Output:
`{ "valid", "method", "stepCount" }`. Errors: `E_INPUT_INVALID` (a method summae does
not perform — it is refused, never approximated), `E_COSTING_CYCLE`; missing `sender`
→ `InvalidValue` ⚠.

```json
{ "method": "step_ladder", "steps": [ { "sender": "VW", "receivers": [ { "code": "FE", "share": "60" }, { "code": "VT", "share": "40" } ] } ] }
```

Two methods:

- **`step_ladder`** — one pass in the order the steps are given. The scheme has to be
  acyclic (`E_COSTING_CYCLE`), because in one pass a centre that has already been
  emptied cannot receive anything back.
- **`simultaneous`** — all cost centres solved at once as a linear system, so centres
  that serve *each other* are allowed. Use it whenever the power plant heats the
  workshop and the workshop maintains the power plant: there is no order in which one
  of them can go first without sending on cost it has not received yet. Only a
  **closed** circle is refused (`E_COSTING_UNSOLVABLE`) — one where a group of centres
  passes everything among themselves and nothing ever reaches a centre that keeps it.

```json
{ "method": "simultaneous", "steps": [ { "sender": "ST", "receivers": [ { "code": "RE", "share": "20" }, { "code": "FE", "share": "80" } ] }, { "sender": "RE", "receivers": [ { "code": "ST", "share": "10" }, { "code": "FE", "share": "90" } ] } ] }
```

The textbook's third procedure, the *direct* method (German *Anbauverfahren*), needs no
mechanism of its own: it is the step ladder with a scheme in which auxiliary centres send
only to main ones. Leaving the auxiliary-to-auxiliary edges out of the scheme *is* the
method.

#### runCosting

Costing run: primary costs from expense lines carrying a `costCenter` dimension,
then allocation by the method the scheme was set with. `fiscalYear` (yes), `period`
(yes). Output: `{ "runId", "status": "draft", "version" }`. Errors:
`E_COSTING_UNSOLVABLE` (see `setAllocationScheme`).

#### releaseCosting

Release (`draft` → `released`). `runId` (yes). Output:
`{ "runId", "status": "released" }`. Errors: `E_COSTING_RUN_UNKNOWN`,
`E_COSTING_RUN_RELEASED`.

---

## 7. API reference: projections

Call: `project(name, params)`. Balances are never stored; they are recomputed
from the journal on every call. Orderings by Unicode codepoints resp.
`sequenceNumber`/date. Money appears per field either as an amount string
(`"178.50"`) or as a Money object — noted below. `asOf`/`throughPeriod` enable
as-of evaluations.

> ⚠ **Period parameters are not uniform.** Most projections take `fiscalYear`,
> but **`vatReturn` takes `year` + `quarter` or `month`** and **`cashBasisReport` takes
> `year`**. `incomeStatement` and `balanceSheet` additionally *require*
> `mapping`. Cheat sheet:
> [CLI walkthrough § 12](cli-walkthrough.md#12-parameter-cheat-sheet).
>
> Getting a name wrong **fails loudly**: every projection declares its parameters,
> and the dispatcher checks them before routing. An undeclared parameter is
> `E_INPUT_INVALID` and is never silently ignored; a declared one of the wrong type
> is rejected rather than coerced; an absent one keeps its documented default. So
> passing `fiscalYear` to `vatReturn` is an error, not an empty report that looks
> plausible.

### trialBalance — trial balance

`fiscalYear` (yes), `throughPeriod` (no, default all), `includeZeroBalances`
(no, default false). `openingBalance` only for balance-carrying accounts;
`balance = openingBalance + debitTotal − creditTotal`. Money as amount strings.

```json
// params { "fiscalYear": 2026, "throughPeriod": 12 }
{ "rows": [ { "account": "1200", "openingBalance": "0.00", "debitTotal": "178.50", "creditTotal": "0.00", "balance": "178.50" } ] }
```

### accountSheet — account ledger

`account` (yes, number; unknown → `E_ACCOUNT_UNKNOWN`), `fiscalYear` (yes),
`throughPeriod` (no). Output: `account`, `name`, `openingBalance`, `lines[]`
(each `sequenceNumber`, `entryDate`, `text`, `side`, `money` [Money],
`runningBalance`), `closingBalance`. ⚠ Shape from code (no fixture).

### auditLog — change history

`from`/`to` (no, date range inclusive). Output: `records[]` with `id`, `at`
(ATOM with zone), `actor`, `objectType`, `objectId`, `action`, `changes`
(map `field → {from,to}`).

```json
// params { "from": "2026-01-01", "to": "2026-12-31" }
{ "records": [ { "objectType": "journalEntry", "action": "corrected",
  "changes": { "text": { "from": "Office supplies", "to": "Office supplies January" } } } ] }
```

### openItems — open-item list

`asOf` (no, cutoff date), `kind` (no, `receivable`/`payable`), `partnerId` (no).
Items with a remaining amount of 0 as of the cutoff date drop out. Output:
`items[]` with `id`, `kind`, `voucherNumber`, `partnerId`, `due`, `money` (original,
Money), `remaining` (Money), `status`.

`partnerId` and `due` are `null` where none is known — present and null, so "no partner
recorded" / "no date agreed" stays distinguishable from "this view does not say". `due`
comes from the **voucher**, so every item created by one voucher shares it; an instalment
plan with a different date per part has no place to record that yet.

```json
// params { "asOf": "2026-02-20", "kind": "receivable" }
{ "items": [ { "voucherNumber": "AR-2026-010", "remaining": { "amount": "690.00", "currency": "EUR" }, "status": "partially_settled" } ] }
```

### assetRegister — asset register

`asOf` (no, cutoff date). Output: `assets[]` with base fields plus
`accumulatedDepreciation` (Money), `bookValue` (Money) and — only for
`route:"capitalize"` — `depreciationSchedule` (map `months<N>to<M>` + `total`).

```json
// params { "asOf": "2026-12-31" }
{ "assets": [ { "name": "Laptop", "acquisitionCost": { "amount": "3000.00", "currency": "EUR" },
  "accumulatedDepreciation": { "amount": "500.00", "currency": "EUR" },
  "bookValue": { "amount": "2500.00", "currency": "EUR" } } ] }
```

### costAllocationSheet — cost allocation sheet (BAB)

`runId` (yes; unknown → `E_COSTING_RUN_UNKNOWN`). `fiscalYear`/`period` are optional
and, if given, have to agree with the run — a mismatch is `E_INPUT_INVALID` rather
than the run's own period returned under someone else's label. Output: `runId`,
`status`, `version`, `method` (which procedure produced these numbers — the two answer
the same question differently, so a sheet that does not say cannot be checked against
anything), `primary[]` and `afterAllocation[]` (each `{costCenter, total}`),
`grandTotal` (strings).

```json
// clearing total 4000 is preserved, sender VW ends at 0
{ "primary": [ { "costCenter": "VW", "total": "1000.00" } ],
  "afterAllocation": [ { "costCenter": "VW", "total": "0.00" } ], "grandTotal": "4000.00" }
```

### vatReturn — VAT return (umsatzsteuer-voranmeldung)

`year` (yes), `quarter` (no), `month` (no, 1–12), `asOf` (no). Give **either**
`quarter` **or** `month` — both together is `E_INPUT_INVALID`, because they describe
different windows and picking one silently is how a return gets filed for the wrong
period; neither means the whole year. The monthly window is not a convenience: where a
jurisdiction prescribes monthly filing above a turnover threshold, it is the filing
period, and computing it as the difference of two cumulative `asOf` calls is wrong as
soon as cash-basis taxation or a reversal is involved. Note that `vatPeriod` on the tax
profile is descriptive only — it records which period a tenant files in and does not
select a window here. Accrual taxation
counts by posting/service date; cash taxation follows the open-item settlements
(`settledAt`, partial payments pro rata). Output: `keys` (each `reportingKey` →
`{base, tax}`; `base` officially rounded down to full euros, `tax` to the cent),
`payload` (Money: Σ output tax − Σ input tax).

```json
// params { "year": 2026, "quarter": 2, "asOf": "2026-07-01" }
{ "keys": { "81": { "base": "1000.00", "tax": "190.00" }, "66": { "tax": "19.00" } },
  "payload": { "amount": "171.00", "currency": "EUR" } }
```

### incomeStatement — income statement (GuV)

`fiscalYear` (yes), `mapping` (yes, income-statement mapping ID; not loaded →
`E_MAPPING_OVERLAP` ⚠), `fromPeriod`/`throughPeriod` (no). Sign: credit − debit;
income-statement accounts only. Output: `positions[]` (`key`, `label`,
`amount`), `netIncome`, `gapWarnings[]`.

**Accounts the mapping does not cover stay visible.** They land in a catch-all
position with the key `_unassigned` (label `Unassigned`) and are listed in
`gapWarnings[]` as `{ account, assignedTo: "_unassigned" }`, sorted by account
number. A gap is a warning, not an error: money that was posted must not vanish
from a statement because a mapping is incomplete. `gapWarnings` is always
present — an empty array when the mapping covers everything.

```json
// params { "fiscalYear": 2026, "throughPeriod": 12, "mapping": "test-guv" }
{ "positions": [ { "key": "1", "label": "Revenue", "amount": "1000.00" },
                 { "key": "2", "label": "Sonstige betriebliche Aufwendungen", "amount": "-300.00" } ],
  "netIncome": "700.00", "gapWarnings": [] }
```

### balanceSheet — balance sheet

`asOf` (no, cutoff date), `fiscalYear` (no), `mapping` (yes, balance-sheet
mapping ID), `incomeMapping` (no; ⚠ not evaluated by `compute()` — the net income
flows in via the `includesNetIncome` leaf of the balance-sheet mapping). Side
assignment via `side`. Output: `assets[]`, `assetsTotal`,
`liabilitiesAndEquity[]`, `liabilitiesAndEquityTotal`, `gapWarnings[]` — balance
identity by construction.

**`fiscalYear` scopes cumulatively**: everything up to and including that year,
i.e. "as at the end of fiscal year N", not "movements of year N". A balance sheet
is a snapshot and has to balance — `trialBalance`'s rule that income accounts
restart each year would tear a hole exactly the size of the prior year's result,
because summae writes no closing entries (`closeFiscalYear` is a pure status
change) and that result was therefore never carried into equity.

**Uncovered accounts stay visible**, as in `incomeStatement`: a `_unassigned`
position per section (label `Unassigned`) plus `gapWarnings[]` — which is what
keeps assets == liabilities + equity when a mapping is incomplete.

```json
// params { "asOf": "2026-12-31", "mapping": "test-bilanz" }
{ "assets": [ { "key": "A.B", "amount": "890.00" } ], "assetsTotal": "890.00",
  "liabilitiesAndEquity": [ { "key": "P.EK", "amount": "700.00" }, { "key": "P.V", "amount": "190.00" } ],
  "liabilitiesAndEquityTotal": "890.00", "gapWarnings": [] }
```

### cashBasisReport — cash-basis report (EÜR)

`year` (yes), `asOf` (no), `mapping` (no; without a mapping the account name
applies). Cash effectiveness via money accounts, the 10-day rule, VAT
income-effective, asset payments not deductible. A deviating fiscal year →
`E_CASHBASIS_DEVIATING_FISCAL_YEAR`. Output: `income[]`/`expenses[]` (each
`{category, amount}`, sorted by category).

```json
// params { "year": 2025, "asOf": "2026-06-07" }
{ "income": [], "expenses": [ { "category": "USt-Zahlung an FA", "amount": "190.00" } ] }
```

### ecSalesList — EC sales list (zusammenfassende meldung, ZM)

`year` (yes), `quarter` (no). Intra-community supplies per VAT ID (from the key
tags of the igL codes; partner via the voucher). Output: `rows[]` (`vatId`,
`amount`, `kind`). Postings without a partner VAT ID drop out.

```json
// params { "year": 2026, "quarter": 1 }
{ "rows": [ { "vatId": "ATU12345678", "amount": "1000.00", "kind": "supply" } ] }
```

### journalExport — GoBD Z3 export

`fiscalYear` (**yes**), `format` (no; the only accepted value is `"gobd-z3"`, which is
also the default — anything else is `E_INPUT_INVALID` rather than silently the Z3
stream under a wrong label). The manifest's `formatVersion` always states the current
data-format version, `"0.6"`. Output: `manifest` (`formatVersion`,
`tenantId`, `exportedAt`, `hashAlgorithm:"sha256"`, `streams`, `contentHashes`),
`fieldCatalog`, `journal` (`entryCount`, `ordering`, `allFinalized`), `data`
(`journal`, `accounts`, `vouchers`, `partners?`, `auditLog`). `contentHashes` =
SHA-256 over RFC-8785-canonicalized rows per stream. The audit trail is always
part of the export.

### auditDataExport — AICPA Audit Data Standard (US)

The **US counterpart** to `journalExport`: the US has no statutory GL export
format, so the voluntary AICPA **Audit Data Standard (General Ledger)** is what
a US auditor expects. Field names follow the official AICPA-ADS/AuditData-API
schema.

`fiscalYear` (no; missing = the whole journal), `asOf` (no; default = latest
posting date in scope). Output: `standard` (`"aicpa-ads-gl"`), `currency`, and
three ADS streams:

| Stream | ADS name | Content |
|---|---|---|
| `journals` | GLDetail | entries + `glLineItems[]` |
| `trialBalance` | GLAccountBalance | beginning/ending balances per account |
| `accounts` | chart of accounts | account master data |

⚠ **Line amounts are signed** — debit positive, credit negative. ADS has no
debit/credit indicator, unlike `datevExport` (`debitCredit: "S"/"H"`) and
`journalExport`.

```json
// params { "fiscalYear": 2026 }
{ "standard": "aicpa-ads-gl", "currency": "EUR",
  "journals": [ { "journalId": "01a0…", "effectiveDate": "2026-02-10", "fiscalYear": 2026, "period": 2,
    "jeHeaderDescription": "Consulting February", "source": "AR-001", "enteredDate": "…",
    "reversalIndicator": false, "reversalJournalId": null,
    "glLineItems": [ { "glAccountNumber": "1400", "journalIdLineNumber": "01a0…-1",
      "jeLineDescription": "Consulting February", "transactionAmount": "1190.00" } ] } ],
  "trialBalance": [ … ], "accounts": [ … ] }
```

Pick the export by jurisdiction, not by preference: `journalExport` (GoBD Z3)
and `datevExport` are German works with German field descriptions;
`auditDataExport` is the US work. They are not translations of each other.

### datevExport — DATEV export

`kind` (no: `entries` default / `accounts` / `partners`); for `entries`
additionally `fiscalYear`/`fromPeriod`/`throughPeriod`. Output:
`{ "kind", "rows": [ … ], "rowCount" }`. Rows differ per `kind` (batch row:
`amount`, `debitCredit`, `account`, `contraAccount`, `buKey`, `documentField1`,
`date` (MMDD), `text`, `finalized`). ⚠ The exact EXTF header format still to be
verified against current DATEV documentation.

```json
// params { "fiscalYear": 2026, "fromPeriod": 1, "throughPeriod": 12 }
{ "rows": [ { "amount": "119.00", "debitCredit": "S", "account": "1200", "contraAccount": "8400",
  "buKey": "3", "documentField1": "AR-77", "date": "0303", "text": "Barverkauf", "finalized": true } ], "rowCount": 1 }
```

---

## 8. Value objects

All value objects live in the namespace `Summae\Core\Shared` (Node: the same
set), are immutable, and are **never constructed via `new`** but through static
factories (`of`, `fromString`, …) that validate and, on a violation, throw
`InvalidValue` (for Money additionally `CurrencyMismatch`). These value/format
errors are **not** part of the domain `E_*` catalog.

### Money

Amount (exact decimal value) + currency. **Never a float.** JSON shape:
`{"amount": "100.00", "currency": "EUR"}` — `amount` is a string with a fixed
scale (EUR: 2 decimal places).

```php
$m = Money::of('100.00', 'EUR');        // scale MUST match; does NOT round
$z = Money::zero('EUR');
$m = Money::fromCalculation('2.225', 'EUR');   // → 2.23 (the only path on which rounding happens)
```
```ts
Money.of("100.00", "EUR"); Money.zero("EUR"); Money.fromCalculation("2.225", "EUR");
```

Rounding: commercial **half-up, away from zero** at `.5` (no banker's rounding):
`2.225 → 2.23`, `-2.345 → -2.35`.

Important methods: `add`/`subtract` (throws `CurrencyMismatch` on a differing
currency), `negate`/`abs`, `compareTo`/`equals`, `isZero`/`isPositive`/
`isNegative`, `amountAsString`, `jsonSerialize`.

**`allocate(...$weights)` — largest-remainder.** Distributes without loss
according to weights; **invariant: Σ parts = original amount**. The remaining
cent goes to the parts with the largest remainder, on a tie to the smallest
index.
```php
Money::of('100.00', 'EUR')->allocate(1, 1, 1);   // → [33.34, 33.33, 33.33]
```
`allocateEvenly(int $parts)` splits into `$parts` equal parts (depreciation
installments, pool fifths). Error (empty/negative/sum 0) → `InvalidValue`.

### Currency

ISO-4217 code + fixed scale. `Currency::of('EUR')` (scale 2). Default scale 2;
registered: JPY/KRW = 0, BHD/KWD/TND = 3. ⚠ v1 is EUR-centric, with no true full
ISO check (any formally valid 3-letter code is accepted). JSON: the bare code
string `"EUR"`.

### CalendarDate

Zoneless calendar date (ISO `Y-m-d`). `CalendarDate::of('2026-06-18')`;
`isBefore`/`isAfter`/`isBetween`, `year`/`month`, `lastDayOfMonth`/
`firstDayOfNextMonth`. Strict validation (`2026-02-30` → `InvalidValue`). JSON:
the ISO string.

### AccountNumber

Account number as a string — **leading zeros significant**, comparison by
Unicode codepoints (`"0420" < "1200" < "8400"`, `"10" < "9"`). 1–64 characters,
no whitespace/control characters. JSON: the string.

### Uuid

UUIDv7 (RFC 9562) — sortable in time as a string. `Uuid::fromString(...)`
(normalizes to lowercase), `Uuid::v7([$clock])`. JSON: the canonical lowercase
string. Fixtures never compare ID values, only placeholder equality.

### Clock / IdGenerator — determinism pairs

Time and IDs are injectable. `Clock.now()`, `IdGenerator.next(): Uuid`.

| Use | Clock | IdGenerator |
|---|---|---|
| **Production** | `SystemClock` | `UuidV7IdGenerator` (real v7) |
| **Tests / conformance** | `FixedClock` | `DeterministicIdGenerator` (clock + counter, no randomness) |

```php
$clock = FixedClock::at('2026-06-18T10:00:00Z');
$ids   = new DeterministicIdGenerator($clock);
$clock->advanceMilliseconds(5);
```
Tests **never** written against `now()`/randomness.

### CanonicalJson

Canonical JSON per **RFC 8785 (JCS)** — the basis of all hashes/comparisons.
`CanonicalJson::encode($value)` (PHP) resp. `canonicalJson(value)` (Node). Key
ordering by UTF-16 code units; **floats are rejected**; integers only
`|x| ≤ 2^53−1`. An empty PHP array = an empty list `[]`; for `{}` use
`stdClass`.

---

## 9. Error catalog

Domain errors are thrown as a `DomainError` (PHP: `Summae\Core\DomainError`,
Node: the same concept/the same codes). Three fields:

| Field | Type | Meaning |
|---|---|---|
| `errorCode` | string | stable `E_*` code — **part of the contract: the same violation → the same code in all implementations** |
| `message` | string | free description (default = `errorCode`) |
| `details` | object | the involved IDs/values |

```php
try { $ops->execute('post', $input); }
catch (\Summae\Core\DomainError $e) { $e->errorCode; $e->details; $e->getMessage(); }
```
```ts
try { ops.execute('post', input); }
catch (e) { if (e instanceof DomainError) { e.errorCode; e.details; e.message; } }
```

**Posting / journal:** `E_ENTRY_TOO_FEW_LINES`, `E_ENTRY_INVALID_AMOUNT`,
`E_ENTRY_UNBALANCED`, `E_ENTRY_NO_VOUCHER`, `E_ENTRY_UNKNOWN`,
`E_ENTRY_FINALIZED`, `E_ENTRY_ALREADY_REVERSED`, `E_ENTRY_HAS_OPEN_ITEMS`
(`correct` on lines that produced open items), `E_ENTRY_HAS_SETTLED_ITEMS`
(`reverse` of a posting whose open item is already settled), `E_VOUCHER_UNKNOWN`.

**Account / dimensions:** `E_ACCOUNT_UNKNOWN`, `E_ACCOUNT_NUMBER_TAKEN`,
`E_ACCOUNT_LOCKED`, `E_COA_FORMAT_INVALID`, `E_DIMENSION_INVALID`.

**Period / fiscal year:** `E_PERIOD_UNKNOWN`, `E_PERIOD_CLOSED`,
`E_PERIOD_OUT_OF_ORDER`, `E_FISCALYEAR_CLOSED`, `E_FISCALYEAR_OVERLAP`,
`E_FISCALYEAR_UNFINALIZED_ENTRIES`.

**Tax:** `E_TAXCODE_UNKNOWN`, `E_TAXCODE_NO_VALID_VERSION`,
`E_PROFILE_RETROACTIVE_CONFLICT`.

**Open items:** `E_OPENITEM_UNKNOWN`, `E_SETTLEMENT_EXCEEDS_ITEM`,
`E_SETTLEMENT_EXCEEDS_ENTRY`, `E_SETTLEMENT_DIFFERENCE_INVALID`.

**Assets:** `E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`.

**Costing (cost accounting):** `E_COSTING_RUN_UNKNOWN`,
`E_COSTING_RUN_RELEASED`, `E_COSTING_CYCLE`.

**Partner:** `E_PARTNER_UNKNOWN`.

**Mapping / profile:** `E_MAPPING_OVERLAP`, `E_PROFILE_UNKNOWN`.

**Cash-basis (EÜR):** `E_CASHBASIS_DEVIATING_FISCAL_YEAR`.

**Pack composition:** `E_PACK_UNRESOLVED_REF` (a manifest names a module that is
not there), `E_PACK_INCOHERENT` (the resolved bundle contradicts itself, e.g. a
tax code without its account), `E_POLICY_INVALID` (a policy value is wrong, or
the manifest's `packPolicy` deviates from the policy module).

**Input:** `E_INPUT_INVALID` — a parameter that is not declared for this
operation/projection, one of the wrong type, or a required field missing.
Undeclared parameters are **rejected, never silently ignored**; a declared one of
the wrong type is **rejected, never coerced** (see § 7).

**Other:** `E_NOT_IMPLEMENTED` (operation/projection not wired in the
dispatcher).

The **CLI** maps this catalog onto exit codes: on errors it prints
`{"error": "E_…", "message": …, "details": …}` and exits with an exit code ≥ 10.
It adds one code of its own, `E_WORKSPACE_INVALID`, for a `summae.json` that is
missing fields or malformed. **Catalog and exit codes cover each other exactly**:
every code listed here has a number of its own, and no number exists without its
catalog entry. A script may therefore branch on the exit alone; `1` is reserved
for an unexpected failure, i.e. a summae bug. The numbers are stable and assigned
in append order — a new code gets the next free number, existing ones never move.

> ⚠ Value/format validation of the value objects (`InvalidValue`,
> `CurrencyMismatch`) is **not** a `DomainError` and not part of this catalog.

---

## 10. Determinism & data format

Same input → byte-identical result, across languages. That makes results
reproducible, testable, and interchangeable between implementations.

- **Clock & IDs injectable** — production: `SystemClock` + `UuidV7IdGenerator`;
  tests: `FixedClock` + `DeterministicIdGenerator` (see § 8).
- **The posting date is zoneless** (`CalendarDate`, no timestamp with a UTC
  shift).
- **Ordering** by Unicode codepoints, **JSON** canonical (RFC 8785).
- **Money never as a float** — `Money`, half-up away-from-zero, `allocate`
  largest-remainder.
- **Exchange between implementations** runs through the JSON data format
  (`journalExport` / import), not through two live engines on the same live DB.
  Another implementation may **read** the same database; concurrent writing by
  two engines onto the same journal is deliberately to be avoided.

---

## 11. Further reading

- **[CLI walkthrough](cli-walkthrough.md)** — the same material task-first:
  empty directory → invoice → payment/settlement → reversal → reports → period
  and year close → export, as one runnable sequence. Start here if you want to
  *do* something; come back to this handbook for the field-level detail.
- **Compatibility contract:** `testing/testsuite/` (fixtures + schema) — the normative
  source against which every implementation is checked byte-identically.
- **PHP developer docs** (architecture, workflow, conformance):
  [implementations/php/docs/](../../implementations/php/docs/README.md).
- **Node developer docs:**
  [implementations/node/README.md](../../implementations/node/README.md).

This handbook is the authoritative user documentation; the package READMEs are
only entry-point pointers.
