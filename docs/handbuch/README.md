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

> **Storing customer names?** [GDPR conformance](../gdpr-conformance.md) inventories every
> field in which personal data can end up, states where the right to erasure collides with
> the retention duty and which one wins, and lists what a library cannot answer for you.
> summae is not a processor: it runs inside your process and sends nothing anywhere — which
> is precisely why almost everything on that page is yours.

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
plus `tenantId` (`?Uuid`, default: freshly generated) — this lets you resume an
existing tenant ID. Prerequisite: `php artisan migrate` has been run (see § 4).

`name` and `baseCurrency` are **optional** here: a tenant that already exists is
described by its own row, and `build(tenantId: $id)` is the whole call. What you
pass on an open that finds an existing tenant is ignored — see
[what is stored and what you store](#what-summae-stores-and-what-you-store).

### Knex adapter (Node, persistent)

```ts
import { SyncDb, installSchema, DatabaseTenantFactory } from '@superheld/summae-knex';
import { Currency, SystemClock, UuidV7IdGenerator, TenantOperations } from '@superheld/summae-core';

const db = new SyncDb('./accounting.sqlite');   // file-backed; `new SyncDb()` → in-memory SQLite
installSchema(db);                              // creates the summae_* tables (once)

const clock  = new SystemClock();
const tenant = DatabaseTenantFactory.build(db, clock, new UuidV7IdGenerator(clock), {
  name: 'Example Ltd',          // seed: used when this tenant is created, ignored afterwards
  baseCurrency: Currency.of('USD'),
  // taxCodes, mappings, taxProfile, dimensions, packIdentity, tenantId
});
const ops = new TenantOperations(tenant);
```

`DatabaseTenantFactory.build(...)` wires the same services as `Tenant.inMemory`,
only against **DB-backed ports** — the direct counterpart to PHP's Laravel adapter,
writing the **same `summae_*` schema** (the SF-15 cross-test proves byte-identical
`journalExport` across the PHP↔Node boundary on a shared database). `better-sqlite3`
is the SQLite driver; `pg` covers Postgres. The optional `options.tenantId` resumes
an existing tenant; for one that already exists, `build(db, clock, ids, { tenantId })`
is the whole call, because the tenant's name, currency and configuration come back
from its own row. The schema must be installed beforehand (`installSchema`).

`listTenants(db)` (PHP: `DatabaseTenantRecordRepository::listTenants($connection)`)
answers which tenants a store holds, so you can look one up instead of keeping a
second list beside the books. It is not a projection — a projection is computed *on* a
tenant, and this question has none to run on — and it is **not a tenant register**: it
reads the rows summae wrote and nothing else. Registering, naming, selecting and setting
up tenants stays the embedding application's, along with anything a person chooses
between. What changed is only that the books can now be asked what they contain.

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
(neutral chart, no jurisdiction). `--currency` is *not* derived from the pack — it
defaults to `EUR` regardless, so `--pack us` wants `--currency USD` alongside it.

> **`default` ships no mappings, deliberately.** A jurisdiction-free chart of
> accounts has no lawful statement layout it could bring: every balance-sheet and
> income-statement gliederung is somebody's law. So on a `default` tenant
> `balanceSheet`, `incomeStatement` and `cashBasisReport` have nothing to work
> with until you load one with `importMapping` — the refusal says so and carries
> `available: []`, and `tenantConfiguration.mappings` answers the same question
> without an error. Everything else works: posting, journal, trial balance,
> account sheets, the audit trail, and the appropriation of profit.

`--pack de` (or `--pack us` / `--pack default`) loads the pack from the **pack library**
(`pack-library/`; overridable with `--pack-library <dir>`), resolves it, and
creates the chart of accounts, tax codes, mappings, depreciation rules, and
policy in *one* step — the chart of accounts thus comes as a **pack choice**,
not as an inline-maintained `rules.json`. `rules.json` (§ 5) remains the route
for your own/deviating rules. Every subsequent call loads the tenant from the
workspace, executes, and the SQLite file persists.

### What summae stores, and what you store

The first question every embedding runs into, and the one that used to have no written
answer. Short version: **summae stores the books and the tenant; you store the pack.**

**What summae persists** — ten `summae_*` tables, identical in both languages (the SF-15
cross-test proves a database written by one is read identically by the other):

| table | holds |
|---|---|
| `summae_tenants` | the tenant itself: id, name, base currency, which pack it was composed from, and its configuration (see below) |
| `summae_accounts` | the chart of accounts, seeded from the pack and yours to adapt afterwards |
| `summae_fiscal_years` | fiscal years and their period states |
| `summae_vouchers` · `summae_journal_entries` | the Belegfunktion and the journal |
| `summae_open_items` | receivables/payables with their settlements |
| `summae_partners` · `summae_assets` | master data |
| `summae_costing_runs` | costing runs, versioned per period |
| `summae_audit_log` | the trail |

**What the tenant's configuration covers.** Four things live in `summae_tenants.config`,
and they are exactly what the four configuration operations change:

| | changed by | seeded from |
|---|---|---|
| tax profile | `setTaxProfile` | the pack's `profile.defaults`, or what you pass at creation |
| dimension types and values | `defineDimensionType` / `defineDimensionValue` | nothing — cost centres are yours, not a jurisdiction's |
| allocation scheme and rates | `setAllocationScheme` | nothing |
| imported mappings | `importMapping` | the pack's mappings are the base; imports layer on top |

**Reading it back.** All of it, plus the pack's mandatory-dimension rules, comes out of the
[`tenantConfiguration`](#tenantconfiguration--what-this-tenant-is-set-up-as) projection. You do
not have to keep a second copy of what you configured — and since the stored record wins, you
should not: a copy that cannot be compared is a copy that drifts.

**What you keep.** One thing, and it is not small: **the resolved pack** (or your own
rule bundle) — the chart template, tax codes, mapping definitions, depreciation rules,
`packPolicy`. It is versioned product data that you pin and ship, so summae takes it on
every open and never stores a copy: two answers to "which rules is this tenant on" is
one answer too many. `summae_tenants` records *which* pack and version a tenant was
created from, as provenance for `systemDescription` — not as a substitute for having it.

You also choose the `tenantId` (or let the IdGenerator name one) and remember it, the way
you remember any primary key.

**The seed rule.** Name, currency, tax profile and dimension master data may be passed at
construction. They are written on the **first** open of a tenant that has no row yet, and
**ignored on every open after that** — the stored row is the truth. Passing them again is
harmless; leaving them out once the tenant exists is the intended shape. The reason is
worth stating plainly: if your configuration file and summae's table both claimed to hold
the truth, the two would drift the first time an operation changed one of them.

A practical consequence, if you are moving an embedding onto this: you no longer have to
list your cost centres in your own configuration *and* declare them through
`defineDimensionType`. Do one or the other. (Doing both used to be the only way to make
cost accounting work, and it answered `E_DIMENSION_INVALID` for a code you were declaring
for the first time.)

**What summae does not store, by design:** voucher files and documents (referenced, never
held), user identities (`actor` is recorded as you supply it, never verified), and any
retention or deletion schedule. Those are the embedding application's — the full boundary
is in `systemDescription`'s `notProvided`.

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
| stock valuation (expansion) | `inventory` | `categories[]` (`account`, `changeAccount`, `label?`) — which accounts hold stock and where each one's change is booked |
| provisions (expansion) | `provisions` | `accounts[]` (`account`, `expenseAccount`, `releaseAccount`) + `discounting` (`fromMonths`, `basis`) — the discount **rate** is deliberately not pack data |

`formatVersion` names the **data format the file was authored against**, not the pack's own
version — a shipped module may declare an older one and stay perfectly valid, because a module
carries nothing the later formats changed. Write the current one (`0.7`) in a new file.

Module skeleton (`pack-library/<name>-pack/<kind>/<id>.json`):
```json
{ "formatVersion": "0.7", "id": "de-ust", "kind": "tax", "version": "2026.1",
  "contributes": ["taxCodes"], "dependsOn": [{ "kind": "accounts", "id": "de-konten" }],
  "data": { "taxCodes": [ { "code": "USt19", "versions": [
    { "validFrom": "2024-01-01", "validTo": null, "rate": "19.00", "mechanism": "standard",
      "taxAccount": "3100", "reportingKey": "81" } ] } ] } }
```

Manifest (`pack-library/<name>-pack/<id>.json`) — lists the modules, carries
`packPolicy` + `defaults`:
```json
{ "formatVersion": "0.7", "id": "de", "version": "2026.4",
  "modules": [ { "kind": "accounts", "id": "de-konten", "version": "2026.3" },
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

**A published `(id, version)` is frozen.** Once a version has shipped, that pair
names one bundle for good: a changed module gets a new module version, and a
manifest whose references move gets a new manifest version. Old versions may stay
in the library beside the new ones, so a pin like `{ "id": "de", "version":
"2026.3" }` keeps resolving what it always resolved. Asking **without** a version
means *current*, and current is the **highest** version — never whichever file the
directory walk reached first, which would differ between two machines. Versions
are compared **segment by segment**, numerically where both segments are numbers,
so `2026.10` follows `2026.9`; a segment that is not a number falls back to code
points, so a pack that versions itself some other way keeps the order it had.
(Until 2026-08-28 the whole string was compared by code point, which put `2026.10`
*below* `2026.9` — a pack's tenth release would have looked published while the
ninth kept resolving.)

**`contentDigest` is the part nobody can forget.** `resolvePack` returns it
alongside `id` and `version`, and a tenant carries it in `pack.contentDigest`: a
SHA-256 over the canonical JSON of the whole resolution, identical in every
implementation. Two tenants with the same digest run on the same rules, whatever
their labels say; the same version showing two digests means a published version
changed underneath somebody, which is exactly the thing a hand-written number
cannot report about itself. Compare digests when you need certainty; read the
version when you need a name.

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
| `accounts[].subtype` | string (enum)\|null | — | canonical subtype, a **closed** repertoire (see below); among other things drives the open-item automation |

`type` determines the balance mechanics: `asset`/`liability`/`equity` are
balance-carrying (carry forward across years), `expense`/`revenue` are per
fiscal year.

`subtype` is a **closed repertoire** since format 0.9 — thirteen values, and anything else is
refused rather than stored:

| Value | Read by the engine for |
|---|---|
| `bank` | payment account; profit-neutral movement in the cash-basis projection |
| `cash` | the cash journal (`cashJournal`), cash-basis |
| `transit` | money in transit — not a profit event |
| `ar` | a debit opens a **receivable** |
| `ap` | a credit opens a **payable** |
| `tax_in` | VAT return (input side), cash-basis, DATEV export |
| `tax_out` | VAT return (output side), cash-basis, DATEV export |
| `result_allocation` | where an appropriated result lands |
| `inventory` | stock — the only accounts `valuateInventory` may value onto |
| `provision` | provisions — the only accounts `recognizeProvision` may form one on |
| `fixed_asset` | *annotation only* — the asset expansion uses its own module |
| `opening_balance` | *annotation only* — the chart's opening-balance account |
| `private` | *annotation only* — owner's drawings and contributions |

It used to be a free string, and that was a real defect rather than a loose end: a chart that wrote
`tax-out` instead of `tax_out` produced an account that **looked** annotated and behaved like an
unannotated one — the VAT return skipped it and nothing in the output said a tax account had gone
missing. A pack with an unknown subtype now fails at `resolvePack` (`E_PACK_INCOHERENT`);
`createAccount` refuses with `E_INPUT_INVALID` and `importChartOfAccounts` with
`E_COA_FORMAT_INVALID` naming the row. **Absent is not unknown** — most accounts in most charts
carry no subtype at all, and nothing here applies to them.

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
| `taxationMethod` | `"cash"` \| `"accrual"` | `accrual` | cash vs. accrual taxation; **any other value is `E_INPUT_INVALID`** |
| `vatPeriod` | `"monthly"` \| `"quarterly"` \| `"yearly"` | `quarterly` | which window the tenant files in — **descriptive only**, it selects no window in `vatReturn`; any other value is `E_INPUT_INVALID` |
| `smallBusiness` | bool \| list | `false` | small-business scheme; as bool or as a segment list `[{validFrom, value}]` for a mid-year switch |

> **Absent is a default; a wrong value is a mistake.** Both fields used to fall back silently —
> anything that was not `"cash"` became accrual and anything that was not `"monthly"` became
> quarterly. A typo in a configuration file therefore decided whether VAT falls due on invoice or on
> payment, and a tenant that wrote `"yearly"` filed quarterly, with no error and no warning. Leaving
> a field out still gives you the documented default.

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

### `accountCombinationRules[]` / `accountUsageRules[]` — the other two ways a pack says no

`dimensionRules` is one of **three** words the `constraint` kind speaks. All three are checked over
the **whole entry**, once, after its lines resolve.

| Word | Says | Violation |
|---|---|---|
| `dimensionRules` | this account may not be posted without that dimension | `E_DIMENSION_INVALID` |
| `accountCombinationRules` | an entry touching `whenAccountIn` must also touch `requireAccountIn`, or must **not** touch `forbidAccountIn` | `E_COMBINATION_REQUIRED` / `E_COMBINATION_FORBIDDEN` |
| `accountUsageRules` | an entry must not touch `forbidAccountIn` **at all** | `E_ACCOUNT_USE_FORBIDDEN` |

A combination rule carries **exactly one** of `requireAccountIn`/`forbidAccountIn` — both together
would be two rules under one name. The predicate is about the entry, never about sides: the case it
was built for (a granted discount must carry its VAT correction) has both lines on the *same* side.

**`appliesWhen` conditions either kind on the tenant.** A closed set of two facts, and every named
condition must hold while any listed value satisfies one:

```json
"accountUsageRules": [
  { "appliesWhen": { "legalForm": ["gmbh", "ug", "ag", "eg"] },
    "forbidAccountIn": { "from": "2400", "to": "2400" },
    "note": "a capital company has no private account" } ]
```

- `legalForm` — one of the forms *this pack* declares in its `legalForms` module, set through
  [`setEntityProfile`](#setentityprofile). Naming a form the pack does not declare is
  `E_PACK_INCOHERENT` at resolve time, because a mistyped form would otherwise leave the rule
  permanently asleep and the pack looking stricter than it is.
- `taxationMethod` — `accrual` or `cash`, which comes from the pack at tenant creation.

**A rule whose fact is missing does not apply.** A tenant that never called `setEntityProfile` has
no legal form, so a rule keyed on one stays dormant — it is neither enforced nor hidden.
`tenantConfiguration` reports it either way, which is how you tell "there is no such rule" from
"the rule is waiting for a fact you have not supplied".

Deliberately **not** conditions: an amount (a threshold belongs to the one module that owns it, not
restated in a second place) and small-business status (time-segmented; use `vatReturn.gapWarnings`,
which already reports movement on a tax account carrying no tax code). What all three words cannot
do at all: deadlines, anything across entries, or a rule about a settlement — `settle` posts nothing.
Those are projections' work (`duplicateVouchers`, `cashJournal`, `unfinalizedEntries`).

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

Exactly one leaf of a balance-sheet mapping carries `includesNetIncome`, and that
leaf must also claim the chart's `result_allocation` accounts — otherwise an
appropriation entry cancels itself out inside whatever position swallowed the
account, and the balance sheet reports an appropriated result as unappropriated.
A wholesale range around the equity accounts usually has to be cut around it,
since a number in two positions is `E_MAPPING_OVERLAP`.

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

> ⚠ **Getting an input name or type wrong fails loudly.** Every operation declares its
> inputs, and the dispatcher checks them before routing — the same contract the
> projections have had (§ 7), on the side that writes to the books. An **undeclared**
> input is `E_INPUT_INVALID` and is never silently ignored, so `usefulLifeYears`
> cannot quietly leave the pack's useful life in charge. A declared input of the
> **wrong type** is rejected rather than coerced: `"30"` is not `30`, and an amount
> is Money (`{"amount":"2000.00","currency":"EUR"}`), never a bare number. An
> **absent** input keeps its documented default, and `null` counts as absent.
>
> **Since 0.13.0 that reaches *into* structures.** `lines`, `netLines`, `allocations`, `steps`,
> `rates`, the `voucher` object and the rest declare what is inside them, and the same two rules
> apply at every declared depth: `dimension` instead of `dimensions` on a posting line is
> `E_INPUT_INVALID` naming the path (`post: unknown input "lines[0].dimension"`) rather than a
> posting that books correctly and silently drops the cost centre. Where another schema owns a
> structure it is passed through untouched — a mapping document belongs to the data format, and a
> partner's `address` is free-form master data summae stores whole.
>
> What is *not* checked here is whether a required input is present: that stays with
> the operation, which answers with a better code than this layer could —
> `E_VOUCHER_UNKNOWN`, `E_ASSET_UNKNOWN`, `E_ENTRY_NO_VOUCHER` say *what* is missing.

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
(`[{type,code}]`, no), `taxTag` (object, no), `openItem` (object, no, **without effect**).

> **`openItem` on a line does nothing**, and is declared only so that saying so is possible.
> Whether a line opens a receivable or a payable follows from the account's `subtype` (`ar`/`ap`)
> and the line's side — an answer that cannot disagree with the account, which is why it is not
> steerable from the call. Fixtures have passed `openItem: {"kind": "receivable"}` since v0.2 and
> nothing has ever read it.

> **`taxTag` is what puts a line into the VAT return**, and it is worth reading before you hand-write
> one. `vatReturn` is built from tax-*coded* postings, not from what sits on the tax accounts: a line
> without a tag contributes nothing, however correct the account is (the same posting shows up fine
> in the trial balance and on the account sheet, which is why
> [`vatReturn.gapWarnings`](#vatreturn--vat-return-umsatzsteuer-voranmeldung) exists). The shape is
> `{ "code", "appliedVersion", "reportingKey", "baseMoney" }` — the tax code, the `validFrom` of the
> rule version applied, the key the amount is reported under, and the base the tax was computed on.
> **`baseMoney` is signed by the tag, not by the line's side**, so a correction that reduces a
> previously reported base carries a negative `baseMoney`.
>
> Normally you never write one: [`postVoucher`](#postvoucher) and [`expandTax`](#expandtax) produce
> tags from the tax code, and [`reduction: true`](#postvoucher) produces the negative-base case. Use
> the raw field when you are reproducing a posting that already exists — an opening balance takeover,
> a migration — where the tags have to say what the old system said.

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
| `voucher.kind` | string | no | what the voucher is (`invoice_out`, `invoice_in`, …) — recorded, never interpreted |
| `voucher.serviceDate` | string (date) | no | supply date; takes precedence over the voucher date when a tax rule version is selected |
| `voucher.servicePeriod` | object | no | a from/to window for a service spanning periods; stored whole |
| `voucher.due` | string (date) | no | due date, carried onto the open item this posting creates |
| `voucher.economicYear` | integer | no | the year the voucher belongs to economically, where that differs from its date |
| `voucher.issuer` | string | no | who issued it |
| `voucher.recurring` | boolean | no | marks a recurring voucher; recorded, never acted on |
| `taxCode` | string | no | tax code for the expansion |
| `direction` | string | no | `"output"` (default) or `"input"` |
| `reduction` | boolean | no | books the **mirror** of what `direction` describes and tags it so the reporting key goes *down* — a consideration reduction after the fact (see below) |
| `netLines` | array of `{account, money, taxCode?, dimensions?}` | no | net lines; `dimensions` carries onto the resulting net line, not onto the tax line |
| `lines` | array | no | complete posting lines instead of net lines — same shape as on [`post`](#post): `account`, `side`, `money`, `dimensions`, `taxTag` (whose keys are `code`, `appliedVersion`, `reportingKey`, `baseMoney`), `openItem` without effect |
| `counterAccount` | string | yes | gross contra-account (bank/receivable) |
| `entryDate` | string | no | default = `voucher.voucherDate` |

Output: `entry` (like `post`), `openItemsCreated[]`, `grossTotal` (Money),
`taxLines[]`, `voucherId`.

> **Dimensions belong to the net line, never to the tax line.** A net line's
> `dimensions` (`[{ "type": "costCenter", "code": "FERTIGUNG" }]`) are carried through the tax
> expansion onto the posting line for that account. The derived tax line and the gross
> contra-account get none: input tax belongs to the tax account and a payable to the creditor —
> neither belongs to a department, and splitting them across cost centres would invent an
> allocation nobody asked for. The values are validated by the ledger as always, so an undeclared
> cost centre is `E_DIMENSION_INVALID` here exactly as it is on `post`.
>
> This is what makes an operating expense with input tax usable in cost accounting — the ordinary
> way a cost reaches a cost centre. Until 2026-08-24 the expansion dropped it: the posting
> succeeded, the figures were right, and the entry reached the journal with `dimensions: []`.

> **`reduction: true` — a consideration reduction after the fact.** A cash discount taken, a credit
> note, a price reduction after a complaint: the consideration changes *after* the supply was
> reported, so the taxable base and the tax have to come back down in the period the change
> happens (§ 17 UStG under the DE pack). Set `reduction: true` and everything about the call stays
> the same — same `taxCode`, same `direction` as the original supply, net amount of the reduction
> in `netLines`. What changes is that **every line swaps sides** (net, tax and the gross contra
> line) and the tax tag carries a *negative* base, so the reporting key the supply was reported
> under goes down rather than a second key going up.
>
> ```json
> // 2 % discount on a 1,190.00 invoice = 23.80 gross = 20.00 net + 3.80 VAT
> { "voucher": { "voucherNumber": "AR-001-SK", "voucherDate": "2026-05-12" },
>   "taxCode": "USt19", "direction": "output", "reduction": true,
>   "netLines": [ { "account": "8731", "money": { "amount": "20.00", "currency": "EUR" } } ],
>   "counterAccount": "1400" }
> // books 8731 debit 20.00 / 1776 debit 3.80 / 1400 credit 23.80
> // → key 81 falls from 1000.00 / 190.00 to 980.00 / 186.20
> ```
>
> **What is yours to decide** is *whether* a given reduction changes the taxable base — that is
> jurisdiction law and depends on the case. summae supplies the mirror, not the judgement; there is
> no rule here that decides a discount for you.
>
> The same thing is reachable without the flag, by writing the `taxTag` yourself on a plain
> [`post`](#post) — that is how it worked before 0.13.0, and it still works. It asks you to know
> the tag shape, the applied rule version and the reporting key, which is a German form number on
> your screen; `reduction: true` exists so you do not have to.

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
balanced — the same element shape as on [`post`](#post): `account`, `side`, `money`, `dimensions`
(`type`/`code`), `taxTag` (`code`, `appliedVersion`, `reportingKey`, `baseMoney`) and `openItem`
without effect). Output: serialized (changed) posting. Errors: `E_ENTRY_UNKNOWN`,
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
`entryDate` (yes, open period), `text` (no, default `"Reversal <seqNo>"`),
`voucherId` (no — the reversal reuses the original's voucher unless you give it
one of its own). Output:
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
`active`/`locked`), `validFrom` (no, date), `validTo` (no, date). Output: serialized account.
Errors: `E_ACCOUNT_NUMBER_TAKEN`, `E_COA_FORMAT_INVALID`, `E_INPUT_INVALID` (a `validTo` before
its `validFrom` — a window that closes before it opens accepts no posting at all, so the account
would be created dead; or a `subtype` outside the repertoire).

**`subtype` is a closed repertoire** — one of the thirteen canonical values listed under
`chartsOfAccounts[]`, or absent. A value outside it is `E_INPUT_INVALID` with the offending value
and the known list in `details`, rather than being stored: before format 0.9 a hyphen for an
underscore created a liability account that no VAT return would ever count, and the only way to
notice was to compare the return against the ledger. Absent is not unknown — most accounts carry
no subtype and nothing here applies to them.

**`validFrom`/`validTo` are the window in which the account may be posted to**, and both are
unbounded when absent, which is what almost every account will be. It is **not** a lock, and the
difference is why both exist: `lockAccount` is unconditional and about *now*, so it refuses even a
late correction dated before the lock — exactly wrong for an account you are retiring at a year
end. The window is judged against the **posting's own date**, so an account valid to `2026-12-31`
keeps accepting a December correction booked in February and refuses January. A posting outside the
window is `E_ACCOUNT_NOT_VALID_AT_DATE`, with `number`, `entryDate` and both bounds in `details`.

**Writes only, never reads.** An account outside its window still appears in every report that has
postings on it, and carries its balance forward like any other — the history happened. Setting a
window is master data and changes nothing about postings that already exist; it decides what may be
booked from now on. [`accounts`](#accounts--the-chart-of-accounts) reports both bounds, so a screen
can grey out what the chosen date does not allow instead of letting the user post and translating
an error code afterwards.

#### defineDimensionType / defineDimensionValue

Dimension master data — the axes a posting line may carry (`costCenter`, `project`,
`segment`) and the values on them. `defineDimensionType`: `code` (yes). Output:
`{ "code" }`. `defineDimensionValue`: `type` (yes, must already exist), `code` (yes).
Output: `{ "type", "code" }`. Errors: `E_DIMENSION_INVALID` — unknown type, empty
code, or a type/value already defined.

**Stored with the tenant** — the change outlives the process, see [what summae stores](#what-summae-stores-and-what-you-store).

```json
{ "code": "costCenter" }
{ "type": "costCenter", "code": "MAT" }
```

These are the **tenant's** master data, not the pack's: "Materialstelle" is a fact
about one company, not about a jurisdiction. That is why no shipped pack carries
them and why a tenant created from a pack starts with no dimensions at all — until
you declare them here, a line carrying `costCenter` is rejected, and with it every
cost-accounting operation.

#### importChartOfAccounts

Atomic chart-of-accounts import: validate everything first, then create. `rows`
(yes, non-empty; each row carries `number`, `name`, `type`, `subtype`, `status`, `validFrom` and
`validTo` — the same fields as [`createAccount`](#createaccount)), `format` (no, not evaluated in
the core). Output: `{ "importedCount": <int> }`. Errors:
`E_COA_FORMAT_INVALID` (an unparsable row — including a `subtype` outside the repertoire —
naming the row index), `E_ACCOUNT_NUMBER_TAKEN` (also a duplicate within the
batch). The import is atomic, so one bad row creates none of the others: half a chart of accounts
is worse than none.

#### lockAccount

Locks an account (`active` → `locked`); afterwards `E_ACCOUNT_LOCKED` on `post`.
`number` (yes). Output: serialized account with `status:"locked"`. Error:
`E_ACCOUNT_UNKNOWN`.

#### unlockAccount

The way back (`locked` → `active`). `number` (yes). Output: serialized account
with `status:"active"`. Error: `E_ACCOUNT_UNKNOWN`. Both directions are in the
audit trail, as `locked` and `unlocked` with the status diff.

**Unlocking changes nothing about the past.** A lock stops *new* postings; it
never hid the old ones, and the account keeps every posting ever made on it.
That is also why the operation exists at all: the irreversibility of a lock is
not a legal requirement anywhere — what the law asks of master data is that the
change be logged, which it is — so a mis-clicked lock should not have to be
repaired by abandoning the account and opening a second one under a new number.
Read the current status with the `accounts` projection (§ 7).

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

What the close deliberately does not do is carry the result into equity — see
`appropriateResult` below for why that is a separate decision and a separate entry.

#### appropriateResult

Books a resolution on the appropriation of profit (F-CORE-024/SF-25). You state
the decision; the pack supplies the accounts, so no caller has to know that a
carry-forward on the `de` pack means `2300 an 2100`.

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `fiscalYear` | integer | yes | the year whose result is being appropriated |
| `entryDate` | string (date) | yes | **the date of the resolution**, normally in the *following* year |
| `voucherId` | string | yes | the voucher (the minutes) — create it with `createVoucher` first |
| `text` | string | no | entry text; defaults to `Appropriation of the result <year>` |
| `appropriations[]` | list | yes | one entry per target |
| `appropriations[].target` | string | yes | a target **the pack offers** — `carryForward`, `distribution`, … |
| `appropriations[].money` | money | yes | always a **positive** amount, in the tenant currency |
| `actor` | string | no | who recorded it |

Which targets exist is the pack's answer, not the core's: `de` offers
`carryForward` (2100) and `distribution` (3500), `us` and `default` offer
`carryForward` only — a jurisdiction that closes its books straight into retained
earnings has no second target to offer. Ask a tenant what it accepts before you
build a form: the shipped packs are listed in §5, and an unoffered target is
refused by name rather than guessed at.

**Direction follows the books, not the caller.** A profit leaves the
`result_allocation` account in debit and reaches its targets in credit; a loss
does the same journey backwards. Amounts stay positive either way.

**What may be appropriated** is the result *not yet appropriated* — ask for it
with the `unappropriatedResult` projection rather than deriving it: that
projection's `available` for a year is the very function this operation refuses
against, so the number on the screen and the number in the refusal cannot drift
apart. It is also the figure `balanceSheet` reports in its `includesNetIncome`
position. Appropriating less than is available is fine — the rest stays where it
is and can be appropriated later.

Output: `{ "entry", "fiscalYear", "appropriated": [{ "target", "account", "money" }],
"remaining" }`. The entry is a normal posting: correctable, reversible, audited
like any other. Errors: `E_APPROPRIATION_UNSUPPORTED` (the pack declares no
appropriation, or not this target), `E_APPROPRIATION_EXCEEDS_RESULT` (more than
the books carry, or nothing to appropriate at all), plus everything `post` can
raise — `E_PERIOD_CLOSED`, `E_VOUCHER_UNKNOWN`, `E_ACCOUNT_UNKNOWN`.

```json
// after a 2026 result of 900.00, resolved on 2027-05-20
{ "fiscalYear": 2026, "entryDate": "2027-05-20", "voucherId": "…",
  "appropriations": [ { "target": "carryForward",
                        "money": { "amount": "900.00", "currency": "EUR" } } ] }
→ { "appropriated": [ { "target": "carryForward", "account": "2100", … } ],
    "remaining": "0.00" }
```

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
| `reduction` | boolean | no | mirror every side and tag a negative base — a consideration reduction after the fact, see [`postVoucher`](#postvoucher) |
| `taxCode` | string | no | default key for positions without their own |
| `netLines` | array | yes | ≥ 1 net position (`account`, `money`, optional `taxCode`, optional `dimensions` of `{type, code}`) |

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

Sets/changes the small-business status as of a cutoff date — **and only that.**
`taxationMethod` and `vatPeriod` are set when the tenant is created and are not
changeable through this operation; they appear in the output because the output is the
whole profile, not a diff. Both are stored with the tenant, so what you read back is what
the engine runs on (`systemDescription.taxProfile` reports the same thing without
changing anything).

`smallBusiness` (yes): `{ validFrom (yes), value (bool, default false) }`. `reason` (no) is
accepted and **without effect** — declared so the gap is visible rather than hidden behind a
tolerant reader; nothing stores it.
Output: the serialized `TaxProfile` (`taxationMethod`, `vatPeriod`,
`smallBusiness[]` sorted by `validFrom`). Error:
`E_PROFILE_RETROACTIVE_CONFLICT` (no `validFrom`, or postings already finalized
as of the cutoff date).

**Stored with the tenant** — the change outlives the process, see [what summae stores](#what-summae-stores-and-what-you-store).

```json
{ "smallBusiness": { "validFrom": "2026-07-01", "value": false } }
// → smallBusiness: [ {"validFrom":"2026-01-01","value":true}, {"validFrom":"2026-07-01","value":false} ]
```

#### setEntityProfile

Says **what the entity is** — its legal form, and where a jurisdiction grades entities by
size, its size class. Nothing in the books changes; what changes is what summae can
answer about them, namely whether a resolution on the result is owed at all and by when
(`unappropriatedResult`).

| Field | Type | Required | Meaning |
|------|-----|---------|-----------|
| `legalForm` | string | yes | one the **pack** declares — `gmbh`, `ug`, `ag`, `eg`, `kg`, `ohg`, `gbr`, `einzelunternehmen` on `de` |
| `sizeClass` | string | no | one the pack declares — `small`, `medium`, `large` on `de`; the `us` pack grades nothing and accepts none |
| `actor` | string | no | who recorded it |

Which forms exist is the pack's answer, not the core's, and an unknown one is refused by
name with the offered list attached rather than accepted and quietly ignored — a misspelt
form would otherwise report "no resolution required" for a company that owes one, which is
the one wrong answer that looks like a right one. Read the list from
`tenantConfiguration.legalForms` before you build a form; a pack with no catalogue at all
reports an empty list, and then this operation refuses everything.

⚠ **The size class is declared, not computed.** Where a jurisdiction grades entities by size
it does so on measures the books only partly hold — headcount, for one — so summae does not
derive it. Say nothing and you get the regular deadline, which is the conservative one.

Output: `{ "legalForm", "label", "sizeClass", "resolutionRequired", "resolutionDeadlineMonths" }`.
Error: `E_INPUT_INVALID` (missing, or a form/size class the pack does not know — `details.offered`
lists what it does).

**Stored with the tenant**, unlike `actorAuthentication`: this describes the company whose books
these are, not the installation running them, and the change is an audited event
(`entityProfile/changed`) with a date. On reopening, a form the pack has since dropped comes back
as it was stored and simply stops resolving to a deadline — a pack version must not make old books
unopenable.

```json
{ "legalForm": "gmbh", "sizeClass": "small" }
→ { "legalForm": "gmbh", "label": "Gesellschaft mit beschränkter Haftung",
    "sizeClass": "small", "resolutionRequired": true, "resolutionDeadlineMonths": 11 }
```

#### importMapping

Imports a statement mapping (balance sheet / income statement / cash-basis).
Checks every relevant account against the positions; overlap is an error, gaps
are warnings. Input under `mapping`: `id` (yes), `kind` (yes), `version` (no),
`positions[]` (yes, structure see § 5).

Output: `{ "imported": true, "id", "kind", "gapWarnings": [ { "account", "assignedTo": "_unassigned" } ] }`.
Error: `E_MAPPING_OVERLAP` (account in more than one position).

**Stored with the tenant** — the change outlives the process, see [what summae stores](#what-summae-stores-and-what-you-store).

#### createPartner

Lean partner master data (open items per partner, VAT ID, EC sales list, DATEV).
`name` (**yes**, non-empty), `kind` (no, default `both` — one of
`customer`/`supplier`/`both`, anything else is `E_INPUT_INVALID`), `vatId` (no),
`paymentTermsDays` (no), `accountNumbers[]` (no), `address` (no). Output:
serialized partner with a generated `id`. Writes an audit entry.

⚠ **`accountNumbers` are checked against the chart** on `createPartner` and `updatePartner` alike:
a number the books do not carry is `E_ACCOUNT_UNKNOWN`. A partner linked to an account that does not
exist is master data wrong for every reader of the books, not only for the screen that entered it.
The whole-list semantics are unchanged — a valid list replaces the previous one, an empty list
clears the link.

⚠ **A nameless partner is refused**, and so is a whitespace-only one. `name`
used to default to `""`, which produced a partner indistinguishable from the
next and impossible to pick out of a list; a caller that relied on that has to
supply a name now. `kind` used to take any string, so a misspelt one was stored
as given and surfaced as a category nothing could filter on.

```json
{ "name": "Alpen Handel GmbH", "kind": "customer", "vatId": "ATU12345678", "paymentTermsDays": 30, "accountNumbers": ["1400"] }
```

#### updatePartner

Updates existing partners; only changed fields are written (diff in the audit
trail). `partnerId` (yes), `name`/`kind`/`vatId`/`paymentTermsDays`/
`accountNumbers`/`address` (all no — an absent field is left alone). Output:
serialized partner. Errors: `E_PARTNER_UNKNOWN`, `E_INPUT_INVALID` (empty
`name`, unknown `kind`).

**Clearing a field** is `null`: `vatId: null` drops the VAT ID and
`paymentTermsDays: null` drops the payment term. `name` cannot be cleared —
absent leaves it alone, present and empty is refused.

**`accountNumbers` and `address` replace wholesale**, they do not merge: what
you send is what the partner has afterwards. Both were create-only until 0.11,
which made a wrong account link permanent — the only way back was a new partner
under a new id, while every open item stayed on the old one.

A partner the books reference is **kept**: an id that open items point at must
not vanish, and the retention duty (§ 147 AO / § 257 HGB) outranks the right to
erasure for everything in the books — Art. 17(3)(b) GDPR says so in as many
words. What such a partner has instead is a status; see `deactivatePartner`.

A partner the books have **never** referenced is a different question with a
different answer, and it is `erasePartner`.

#### deactivatePartner / reactivatePartner

`partnerId` (yes). Output: serialized partner with `status:"inactive"` resp.
`"active"`. Error: `E_PARTNER_UNKNOWN`. Both are audited (`deactivated` /
`reactivated` with the status diff).

**A state, not a control.** An inactive partner refuses nothing: its open items
still settle, its vouchers still read, and a posting that names it is not
rejected. Whether a picker still offers it is your workflow — the library
records the fact, the application decides what follows from it. That is the
difference to `lockAccount`, which does refuse postings, and the two words are
different on purpose. The status is part of the partner record and of the
`journalExport` partner stream (data format 0.7).

#### erasePartner

`partnerId` (yes), `actor`. Output: `{ "id": "…", "erasedAuditRecords": n }`.
Errors: `E_PARTNER_UNKNOWN`, `E_PARTNER_IN_USE`.

**The Art. 17 GDPR case, and the only operation in summae that removes
anything.** `deactivatePartner` says *we no longer trade with them*;
`erasePartner` says *this personal data must not be here at all*. Only the
second is ever a legal obligation, and it applies to exactly one situation: a
partner that no voucher and no open item has ever named. A record created by a
typo has no retention duty behind it, so keeping it forever was never a
compliance decision.

**It refuses loudly when the books do reference the partner.**
`E_PARTNER_IN_USE` — deliberately not `E_PARTNER_UNKNOWN`, because the partner
exists and is kept on purpose. `details` carries `vouchers` and `openItems`, so
you can answer a data subject with a reason and a retention basis instead of a
bare no.

**It also erases the trail's records about that partner, and this is the point.**
`createPartner` writes the name and, if you gave one, the address into the audit
record's `changes`. Removing only the partner row would move the personal data to
the place nobody looks rather than remove it. So the records *about this partner*
go, `erasedAuditRecords` tells you how many, and one new record takes their place:
id, actor, moment, and `existed: true → false` as its diff — no personal payload
at all. The trail keeps the fact that an erasure happened, which is what an audit
asks of it.

⚠ **This is the one hole in "the trail is append-only because no code path
deletes from it".** It is reachable from this operation and from nowhere else:
the journal, the entries, and the trail's records about them cannot be touched by
any API summae offers. See [GDPR conformance](../gdpr-conformance.md) for the
whole picture and [GoBD conformance](../gobd-conformance.md) §13 for why the two
do not conflict.

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
| `specialDepreciation` | boolean | no | elects the additional allowance the pack declares, once and at acquisition (see below) |
| `totalUnits` | integer | no | the expected total output for `units_of_production` — kilometres, operating hours, copies |

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

The carrying amount is the acquisition cost less **everything already
depreciated**, whatever date those entries carry — not the value the asset had
on `disposedOn`. The difference shows when a yearly run has already booked the
whole year on 31 December and the asset then leaves in September: the year keeps
its twelve months of depreciation, and the disposal takes what is left as its
gain or loss. The income statement carries the same total either way; only the
split between depreciation and disposal result moves. Reading the value as of
the disposal date instead would write off more than stands on the account and
leave an asset account with a credit balance (IMPL-026).

**Exception — pooled assets whose pack keeps them in the pool**
(`poolReducedOnDisposal: false`): nothing is written off, the pool keeps running
its term, and only the proceeds are booked.

⚠ **The month of departure:** depreciation is owed for plan months that have
fallen due, so an asset disposed mid-month gets nothing for that month. Whether
a jurisdiction grants the whole month is a pack question and not answered yet.

#### recognizeProvision

Form a provision. `account` (yes), `reason` (yes), `amount` (yes, Money — the **undiscounted**
best estimate of what it will take to settle), `recognizedOn` (yes), `dueDate` (no),
`discountRate` (no), `actor` (no). Output: `{ provisionId, settlementAmount, carryingAmount,
discounted, discountRate, entryId }`.

A provision is the one balance-sheet item you must recognise for something that has **not yet
happened** — an obligation whose amount or timing is uncertain. That is why it is a duty and not
an option: leaving it out overstates both your result and your equity, in the direction that
flatters. Booked as an expense against the provision account; which expense account and which
release account belong to which provision account is your pack's answer, not yours.

`account` must carry `subtype: "provision"` and must be declared by the pack —
`E_PROVISION_ACCOUNT_INVALID` for the first, `E_PACK_INCOHERENT` for the second, because the two
need different fixes.

**Discounting.** If your pack says long-dated provisions are discounted (Germany: from a remaining
term of twelve months, § 253 Abs. 2 HGB) and `dueDate` puts this one past that line, a
`discountRate` is **required** — `E_PROVISION_DISCOUNT_RATE_REQUIRED` otherwise, never a silent
undiscounted booking. The rate is not in the pack on purpose: in Germany it is an average of the
last seven years' market rates that the Bundesbank publishes **monthly**, and a legal rate sitting
stale in a data file while looking authoritative is worse than one that is absent.

The convention is stated rather than assumed: whole years compound, the remaining stub months
accrue simple interest —

```
carrying = amount / ( (1 + r)^years × (1 + r × months/12) )
```

— because a genuine fractional power is a transcendental, and computing one in PHP and in Node
would put the two a cent apart on some inputs.

```json
{ "account": "3600", "reason": "Gewährleistung 2029",
  "amount": { "amount": "10000.00", "currency": "EUR" },
  "recognizedOn": "2026-06-30", "dueDate": "2029-06-30", "discountRate": "2.00" }
// → carryingAmount 9423.22 (three whole years at 2 %)
```

#### useProvision

The obligation came true. `provisionId` (yes), `amount` (yes, Money — what it actually cost),
`settlementAccount` (yes), `date` (yes), `actor` (no). Output:
`{ provisionId, usedFromProvision, excessExpense, carryingAmount, status, entryId }`.

**The overshoot is the case to understand.** If the invoice is larger than the provision, what was
provided for comes out of the provision and the rest is an expense **of the year the invoice
arrived** — not a retroactive correction of the year the provision was formed. Netting the two
would move an expense across a year that is closed. `excessExpense` names that part explicitly;
`0.00` means the estimate held.

Using less than the provision carries leaves the rest standing. If the remainder is not needed,
release it — that is a different event and it books to a different account.

#### releaseProvision

The reason ceased. `provisionId` (yes), `amount` (no — default: the whole carrying amount),
`date` (yes), `actor` (no). Output: `{ provisionId, released, carryingAmount, status, entryId }`.

Booked against the pack's release account: a released provision is **income the business never had
to pay**, which is a genuinely different thing from an obligation that came true. Releasing more
than the provision carries is `E_PROVISION_EXCEEDS_CARRYING` — the difference would be income
invented out of a sign error.

#### remeasureProvision

The estimate moved while the obligation stands. `provisionId` (yes), `amount` (yes, Money — the new
**undiscounted** estimate), `date` (yes), `discountRate` (no), `actor` (no). Output:
`{ provisionId, change, carryingAmount, discounted, discountRate, status, entryId }`.

An increase is further expense; a decrease books to the release account, because a partial reversal
of a provision *is* a release — and using the same account keeps the two from being told apart by
accident in the ledger. Discounting is re-applied from `date` to the original `dueDate`, so a
provision that simply comes closer to maturity unwinds its discount here rather than silently.

`change: 0.00` and `entryId: null` mean the estimate did not move; the movement is still recorded,
because "we looked and it was still right" is part of the history.

#### adjustInputTax

Correct a deducted input tax when the use of the thing it was deducted for has changed.
`originalInputTax` (yes, Money), `originalSharePercent` (yes), `currentSharePercent` (yes),
`assetKind` (yes), `reason` (yes), `date` (yes), `actor` (no). Output:
`{ due, amount, correctionYears, sharePointsChanged, reportingKey, entryId }` — or, where no
correction is owed, `{ due: false, notDueBecause, threshold, ... , entryId: null }`.

**The boundary runs through the middle of this rule, and knowing where saves you an argument.** The
*register* — which assets are under observation and until when — is **yours**, and for a reason that
survives inspection: the trigger is a change of use, which is never posted. summae sees postings; it
cannot see the day a van starts being driven privately. The *arithmetic* is not yours to reproduce:
a figure produced wrongly looks exactly as authoritative as one produced rightly, which is a reason
to compute it where figures are fixture-pinned and verified across two languages.

Your pack supplies every number: how many years the observation period runs for each `assetKind`
(under the DE pack, five for movables and ten for immovables, § 15a UStG), the two de-minimis
thresholds (§ 44 UStDV), the accounts and the reporting key. An `assetKind` your pack does not
declare is `E_PACK_INCOHERENT` — never a default, because five years where the rule means ten halves
every correction.

**A threshold answer is `due: false` with the threshold named, not an amount of `0.00`.** "No
correction is owed" and "we did not compute one" are different answers, and only one of them lets
you close the file.

```json
{ "originalInputTax": { "amount": "19000.00", "currency": "EUR" },
  "originalSharePercent": "100.00", "currentSharePercent": "60.00",
  "assetKind": "movable", "reason": "Fahrzeug zu 40 % privat", "date": "2026-12-31" }
// → due: true, amount −1520.00 (19,000 × −40 % ÷ 5), booked to expense against input tax
```

The tax line carries your pack's `reportingKey`, so the correction reaches the VAT return where the
jurisdiction expects it. Without it the entry would balance, sit correctly on the account, and
contribute nothing to what you file — which is exactly what `vatReturn.gapWarnings` exists to catch.

#### recognizeDeferral

Defer an amount and fix its release plan. `kind` (yes — `prepaidExpense` or `deferredIncome`),
`reason` (yes), `counterAccount` (yes), `amount` (yes, Money), `recognizedOn` (yes),
`firstFiscalYear` (yes), `firstPeriod` (yes), `periods` (yes), `actor` (no). Output:
`{ deferralId, kind, amount, periods, entryId }`.

**The accounts were never the gap** — the German pack has carried both from the start. What was
missing is the *plan*. An insurance premium paid in December for the following year could be
deferred and then had to be released by hand, month after month, from memory: exactly the failure
`runDepreciation` prevents for arithmetic that is identical.

The two kinds are opposites, not variants:

| `kind` | what it is | at recognition | at release |
|---|---|---|---|
| `prepaidExpense` | money already **paid** for a service still to come — an asset | debit the pack's prepaid account, credit your `counterAccount` (the expense) | the reverse |
| `deferredIncome` | money already **received** for a service still to be rendered — a liability | debit your `counterAccount` (the revenue), credit the pack's deferred account | the reverse |

Anything else is `E_INPUT_INVALID` with the known list: a third kind would be a third direction of
posting, not a variant of these two.

Which account holds each kind is your **pack's** answer; which expense or revenue the amount belongs
to is yours, because that is a fact about the transaction rather than about the jurisdiction.

The plan is laid out with `allocate` (largest remainder), so the instalments sum to the amount
exactly, and it runs over your tenant's **real** periods — a twelve-month plan starting in period 11
lands its last instalments in the following fiscal year rather than in periods that do not exist.

```json
{ "kind": "prepaidExpense", "reason": "Versicherung 2027", "counterAccount": "6080",
  "amount": { "amount": "1200.00", "currency": "EUR" },
  "recognizedOn": "2026-12-31", "firstFiscalYear": 2027, "firstPeriod": 1, "periods": 12 }
```

Note what has to have happened first: a deferral presumes the original payment or receipt was
booked. summae defers what is there; it does not invent the transaction.

#### runDeferralRelease

Release what a period owes, for every deferral. `fiscalYear` (yes), `period` (yes), `actor` (no).
Output: `{ entriesCreated, totalReleased }`, or `{ alreadyRun: true, entriesCreated: 0 }`.

**Deliberately the depreciation run's shape**, down to the answer: one period at a time, idempotent,
`alreadyRun` where there was nothing left to do. Somebody who has closed a period with
`runDepreciation` should not have to learn a second vocabulary for the same act. Idempotent because
each deferral records *which periods it has released* — not because a balance is checked, which
would book twice after a restart.

A run is a run, not a catch-up: releasing period 4 does not release periods 2 and 3 on the way past.
Run each period you owe, in whatever order you like.

#### reportAssetUsage

Depreciation by output. `assetId` (yes), `fiscalYear` (yes), `units` (yes, a whole
number ≥ 1 — the output *since the last report*), `voucherId` (no — a machine voucher
`LAFA-…` is created otherwise). Output: `{ "assetId", "entryId", "amount",
"reportedUnits", "totalUnits", "capped", "bookValue" }`. Errors: `E_ASSET_UNKNOWN`,
`E_ASSET_DISPOSED`, `E_INPUT_INVALID` (the asset is not depreciated by output, is
already fully written off, or the report writes off nothing).

Chosen at acquisition with `"depreciationMethod": "units_of_production"` and
`"totalUnits"` (the expected total output — kilometres, operating hours, copies).
Either without the other is refused rather than ignored.

```json
{ "assetId": "…", "fiscalYear": 2026, "units": 90000 }
```

Such an asset has **no schedule**, and `runDepreciation` passes it by. Time-based
depreciation knows at acquisition what every future period will take; this one cannot,
because the number comes from goods movements and meter readings that are not in the
books.

The arithmetic is cumulative: each report splits the acquisition cost between what the
asset has now given and what it has not, and books the difference against what is
already written off. Computing each period on its own would let rounding drift and
leave a stray cent on a fully used-up asset; this way the report that reaches the total
output lands on the cost exactly. Outliving the estimate is not an error — the booking
is capped at the book value and `capped` says so — but once the asset is written off,
further output is refused rather than booked as a silent `0.00`.

#### bookSpecialDepreciation

An additional allowance next to the ordinary plan. `assetId` (yes), `fiscalYear`
(yes), `amount` (yes, Money > 0), `voucherId` (no — a machine voucher `SAFA-…` is
created otherwise). Output: `{ "assetId", "entryId", "amount", "remainingAllowance",
"bookValue" }`. Errors: `E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`, `E_INPUT_INVALID`
(no allowance elected, outside the window, more than is left).

Elected once, at acquisition, with `"specialDepreciation": true` on `acquireAsset`;
the rate and the length of the window come from the pack (`specialDepreciation` in
the depreciation module), and a pack without one refuses the election with
`E_PACK_INCOHERENT` rather than inventing a rate.

```json
{ "assetId": "…", "fiscalYear": 2026, "amount": { "amount": "8000.00", "currency": "EUR" } }
```

It is **not** a depreciation method. While the window is open the ordinary plan runs
on unchanged, on the original basis — that is what "alongside" means. The amount and
its timing are yours: an allowance of "up to 40 % over five years" is exactly that,
and any split is as valid as any other. When the window closes, the core re-bases
once: whatever book value is left is spread over the plan months still open, the same
way a write-down does it. Without that the plan would keep asking for its original
yearly amount and run the book value below zero.

Whether you are **entitled** to the allowance — a profit limit, a minimum share of
business use — is a fact about the business rather than about the books. summae
cannot know it and does not pretend to; it enforces only the budget and the window.

#### writeDownAsset

Unplanned write-down (impairment). `assetId` (yes), `amount` (yes, Money > 0 and not
more than the current book value), `date` (yes), `reason` (yes), `voucherId` (no —
a machine voucher `AFAA-…` is created when you do not supply one). Output:
`{ "assetId", "entryId", "amount", "bookValue", "remainingPlanMonths" }`. Errors:
`E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`, `E_VOUCHER_UNKNOWN`, `E_INPUT_INVALID`
(missing reason, amount ≤ 0, or more than the book value).

```json
{ "assetId": "…", "amount": { "amount": "1800.00", "currency": "EUR" },
  "date": "2027-06-30", "reason": "Dauerhafte Wertminderung laut Gutachten" }
```

The planned schedule answers wear and tear; it has nothing to say about a machine
damaged in March. Booking that by hand past the asset register leaves the register
and the ledger disagreeing about what the asset is worth, and disposing of it is
wrong because it still exists.

Two things happen. The write-down is posted (`impairmentExpenseAccount` from the pack
if it has one, otherwise the ordinary depreciation account) and it lowers the book
value at once. And **the remaining plan is rewritten**: what is left is spread evenly
over the plan months not yet booked. Leaving the plan alone would depreciate past
zero; stopping it would finish the asset early. The reduced value carried over the
remaining life is what a lasting impairment means.

`reason` is required and not decoration — an unplanned write-down that does not say
why is not auditable, and that is the whole difference between an impairment and a
mistake. It goes into the entry text and the audit record.

#### writeUpAsset

Reverse an earlier write-down when its reason has ceased. `assetId` (yes), `amount` (yes, Money),
`date` (yes), `reason` (yes), `voucherId` (no), `actor` (no). Output:
`{ assetId, entryId, amount, bookValue, ceiling, stillReversible }`.

**This is a duty, not an option** (§ 253 Abs. 5 HGB under the DE pack). Without it an asset written
down in a bad year stays down for ever, which understates your equity and your result exactly as
permanently as the write-down was meant to state them prudently for one year.

Two caps, and the second is the one that surprises people:

| cap | what it means |
|---|---|
| `stillReversible` | nothing may be written back that was not written down — a write-up **reverses**, it does not create value. Over it: `E_ASSET_WRITE_UP_EXCEEDS_WRITE_DOWN` |
| `ceiling` | the **amortised acquisition cost**: what the book value would be if the write-down had never happened. Over it: `E_ASSET_WRITE_UP_EXCEEDS_CEILING` |

The ceiling is stricter than it looks. A write-down does not only lower the book value, it lowers
every remaining planned instalment — so as the plan runs on, the book value drifts *above* the
untouched plan. Reversing the write-down in full some years later would therefore carry the asset
over its amortised cost. summae keeps a shadow plan for exactly this and reports `ceiling` on every
call, so you can ask before you try.

**What you decide and what summae decides.** Whether the reason has ceased, and by how much the
value has recovered, is an appraisal — a judgement about the world, which no library makes. So the
amount is yours. The ceiling is arithmetic, and that is the part that is enforced.

Your pack must name `assetAccounts.writeUpIncomeAccount`; there is no fallback, and the asymmetry
with `writeDownAsset` (which falls back to the depreciation account) is deliberate. A write-down
without its own account lands on ordinary depreciation, which is merely less informative — both are
a charge against the same asset. On the income side the only nearby account is *gain on disposal*,
and a write-up is not one: booking it there would file the figure under a heading that says
something untrue about it.

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

#### valuateInventory

Value stock at a reporting date and book the change. `fiscalYear` (yes), `period` (yes),
`valuationDate` (yes), `categories[]` (yes), `runId` (no), `producedQuantity` (no),
`actor` (no). Output:
`{ "valuationId", "version", "closingTotal", "change", "posted", "entryId" }`.

**What summae knows and what it refuses to know.** It does not know what is in your warehouse.
There is no product master, no goods movement, no bill of material and no stock ledger — those are
your application's data, and nothing here carries a quantity forward. What summae owns is the *act
of valuing*: which accounts, which quantities, at what unit value, where that value came from, what
a comparison with a market value did, and which entry it produced. That record is kept, and it is
kept for the same reason the asset register is: an engine that books a change in stock and cannot
say how it reached the number has not valued anything.

Each entry of `categories[]`:

| key | meaning |
|---|---|
| `account` | the stock account, and it **must** carry `subtype: "inventory"` — otherwise `E_INVENTORY_ACCOUNT_INVALID` |
| `quantity` | the counted quantity, as a **decimal string**. Not a JSON number: `0.1` is not `0.1`, and a quantity that reads back differently in PHP and Node breaks byte parity at the first export |
| `unitCost` | the cost per unit, as a decimal string. Optional — see below |
| `marketValue` | the value per unit at the reporting date, as a decimal string. Optional |

**Where a unit value comes from, and it is never guessed.** Give `unitCost` and it is used. Leave it
out and summae derives it from a **released** costing run: `runId` supplies the production cost
(the § 255 components you configured in `setAllocationScheme`), `producedQuantity` says what output
that total relates to, and the division happens here — the one place where both numbers are declared
inputs of the same call. A draft run is refused with `E_COSTING_RUN_NOT_RELEASED`: valuing a balance
sheet out of a figure its own producer has not stood behind is not a rounding question.

**Lower of cost or market.** Give `marketValue` and the lower of the two is used; the row says which
(`unitValue`, `writtenDownToMarket`). Whether comparing is a duty, an option or forbidden is your
jurisdiction's business — the arithmetic of taking the lower of two numbers and saying which one you
took is not.

**What gets booked.** Per category: the difference between the closing value and what the accounts
already carry, debited to the stock account and credited to the account your pack names for that
category (or the reverse for a decrease), in **one** entry for the whole valuation, finalized
immediately like a depreciation run. If nothing changed, nothing is booked and `posted` is `false`.

**Repeating it is safe, and that is not an accident.** A second valuation of the same period is a new
*version*; because the posting is always the difference against the current book value, an unchanged
period books nothing and a corrected one books the correction. There is no idempotency key to get
wrong.

```json
{ "fiscalYear": 2026, "period": 12, "valuationDate": "2026-12-31",
  "runId": "…", "producedQuantity": "3000",
  "categories": [
    { "account": "1120", "quantity": "800", "marketValue": "38.00" },
    { "account": "1130", "quantity": "150", "unitCost": "12.00" }
  ] }
```

Errors: `E_INVENTORY_ACCOUNT_INVALID`, `E_COSTING_RUN_NOT_RELEASED`, `E_COSTING_RUN_UNKNOWN`,
`E_ACCOUNT_UNKNOWN`, `E_PACK_INCOHERENT` (the pack declares no stock categories, or none for this
account), `E_INPUT_INVALID` (no categories at all — a valuation of nothing is not a valuation of
zero; a negative quantity; a missing unit value with no run to derive one from).

#### setAllocationScheme

Allocation scheme. `method` (no, default `"step_ladder"`), `steps[]` (`sender` yes,
`receivers[].code` yes, `receivers[].share` no, default `"1"`). Output:
`{ "valid", "method", "stepCount" }`. Errors: `E_INPUT_INVALID` (a method summae does
not perform — it is refused, never approximated), `E_COSTING_CYCLE`; missing `sender`
→ `InvalidValue` ⚠.

**`rates[]` — the overhead rates**, set here rather than by an operation of their own because a
rate is computed *from* an allocation and frozen *into* a run, and two operations would need two
freezing rules for one moment. Each entry: `costCenter` (which centre the rate belongs to), `label`
(what to call it in the report, default = the cost centre), and `base` — the denominator, given as
`base.accounts` (direct-cost accounts) and/or `base.costCenters`. **`accounts` belongs under
`base`**: at rate level it is not read, which is exactly the silent-drop this contract now refuses.

**`productionCost` — what may be capitalized** (§ 255 HGB territory under the DE pack, and a
different answer elsewhere, which is why the treatments come from the pack). `include` is the
tenant's election among the *optional* components, by id; `components[]` declares them, each with an
`id` and a `base` of the same shape as a rate's. A component the pack forbids is `E_INPUT_INVALID`;
one the pack does not declare at all is `E_PACK_INCOHERENT`.

```json
{ "method": "step_ladder",
  "steps": [ { "sender": "VW", "receivers": [ { "code": "FE", "share": "1" } ] } ],
  "rates": [ { "costCenter": "FE", "label": "Fertigungszuschlag",
               "base": { "accounts": ["5000"], "costCenters": ["FE"] } } ],
  "productionCost": { "include": ["verwaltung"],
                      "components": [ { "id": "material", "base": { "accounts": ["5000"] } } ] } }
```

**Stored with the tenant** — the change outlives the process, see [what summae stores](#what-summae-stores-and-what-you-store).
A stored scheme is replayed on the next open **when it is first used** (by `setAllocationScheme` or
`runCosting`), not while the tenant is built: it may name production-cost treatments only the pack
answers, and opening the books must not fail on a scheme reading a journal does not need.

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

**Runs are persisted**, so a released run is still there in the next process —
which is what makes `costAllocationSheet`, `overheadRates` and `productionCost`
usable from an application that builds a tenant per request. The version of a
period comes from the store, so a second run of the same period is version 2
however many restarts lie in between. With a persistent adapter the runs live in
`summae_costing_runs`; in memory they live as long as the tenant does.

#### allocate

`total` (yes, Money), `weights` (yes, list of numbers or numeric strings).
Output: `{ "parts": [Money…], "total": Money }`. **Writes nothing** — no
journal entry, no state; it is the largest-remainder split of `Money.allocate`
(§ 8) reachable through the same dispatcher as everything else, so a caller that
only speaks the API can split an amount without reimplementing the rounding.

The scale comes from the tenant's currency (the pack's `currencyScale`), and the
remainder goes to the earliest parts, so `100.00` over three equal weights is
`33.34 / 33.33 / 33.33` and the sum is exactly the total. Use it wherever an
amount has to be distributed before it is posted — a cost split, an instalment
plan, an allocation key — rather than dividing in your own code and posting a
rounding difference.

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
`throughPeriod` (no). Output: `account`, `name`, `openingBalance`, `lines[]`,
`closingBalance`. Each line carries `sequenceNumber`, `entryId`, `entryDate`,
`text`, `side`, `money` [Money], `runningBalance`, `contraAccounts[]` — plus the
reversal fields when the entry is one (see below).

**`entryId` is the same identity `journal` publishes** and the audit trail records.
A screen that lets the reader open a line looks the entry up by it; before it
existed the only route was `journal` with `fromDate` and `toDate` on the same day
plus a filter by `sequenceNumber`, which is a search where a lookup belongs.

**`contraAccounts[]` are the accounts on the other side of the same entry**, as
`{account, name}`, deduplicated and sorted by number. It answers what a T-account
raises on every line — *6000 in debit, against what?* — and it is a **list**
because it has to be: a plain entry has one counter account, an entry with a tax
code has two or more, and a field naming "the" counter account would have to pick
one and thereby invent a fact. The side is decided **per line**: on a debit line
the credit accounts answer, on a credit line the debit ones, so the same entry
reads differently from the two sheets it appears on — which is correct.

```json
// accountSheet { "account": "6000", "fiscalYear": 2026 }
{ "sequenceNumber": 2, "entryId": "…", "side": "debit",
  "money": { "amount": "200.00", "currency": "EUR" }, "runningBalance": "300.00",
  "contraAccounts": [ { "account": "1200", "name": "Bank" } ] }
```

### auditLog — change history

`from`/`to` (no, date range inclusive) · `objectType`, `objectId`, `actor`, `action` (no) ·
`offset`, `limit` (no). Output: `count`, `offset`, `limit` and `records[]` with `id`, `at`
(ATOM with zone), `actor`, `objectType`, `objectId`, `action`, `changes`
(map `field → {from,to}`).

**Filters combine with AND; an absent one filters nothing.** They are how the questions an
auditor actually asks get asked: *what happened to this posting* (`objectId`), *who touched
accounts* (`objectType: "account"`), *what did this user do* (`actor`). Until 0.13.0 only the date
range existed, so the whole trail had to be fetched and filtered outside — which carries the
fastest-growing table in the system across a boundary in order to discard most of it, and makes
traceability a property of your application rather than of the books.

**`count` is the number of matches *before* paging**, so a page header can say "51–100 of 3,204"
without a second call — the same contract [`journal`](#journal--the-journal-windowed-and-paged)
publishes. An absent `limit` means everything from the offset on: no default page size is invented,
because that would silently truncate a caller who never asked for pages. Order is the trail's
recording order, which is already its total order.

**Every record carries a before/after diff**, creations included — those read `{"from": null, …}`.
What the diff holds are the identifying fields, never a copy of the object: an account's current
state is in [`accounts`](#accounts--the-chart-of-accounts), a posting's lines are in the
append-only [`journal`](#journal--the-journal-windowed-and-paged). A trail that duplicates the
object would be a second source of truth rather than a history.

The `actor` is recorded exactly as you supply it and is never verified — binding it to an
authenticated identity is your application's job, and
[`systemDescription`](#systemdescription--technical-system-description) says so in `notProvided`.

```json
// params { "objectId": "…", "limit": 50 }
{ "count": 2, "offset": 0, "limit": 50,
  "records": [ { "objectType": "journalEntry", "action": "corrected",
  "changes": { "text": { "from": "Office supplies", "to": "Office supplies January" } } } ] }
```

### auditTrailIntegrity — is the trail still the trail?

No parameters. Output: `records`, `chained`, `unchained`, `redacted`, `head`, `intact` and
`breaks[]` with `recordId`, `at`, `reason` and `detail`.

**What it answers, and why it did not exist before.** The audit trail has always been append-only
because *no code path updates or deletes it* — the port offers `append` and `all` and nothing else.
That is a property of the procedure, not of the data: an auditor could read the source or trust your
deployment, and a direct `UPDATE` against a `summae_*` table left no trace at all. Since format 0.8
every record carries the hash of its predecessor (SHA-256 over canonical JSON, RFC 8785), so
changing, removing or inserting a record breaks the link at its successor. This projection walks the
chain and reports what it finds.

**Read the four counts as four different states, not as one number:**

- **`chained`** — verified: the record hashes to the value it carries and links to its predecessor.
- **`unchained`** — written before format 0.8 and carrying no hash. Not a break. They can only sit
  at the front; one appearing *after* a chained record is an insertion and is reported as a break.
- **`redacted`** — erased under a privacy right
  ([`erasePartner`](#erasepartner--erase-a-partner-and-what-the-trail-says-about-them)). The shell
  keeps both hashes so the chain still resolves across it; its content cannot be verified, because
  there is none left. A lawful erasure and a manipulation must not look alike, and this is what keeps
  them apart.
- **`breaks[]`** — everything else, each with the reason: `contentMismatch` (the record no longer
  hashes to its own value), `linkMismatch` (its link does not name the preceding record),
  `unchainedAfterChained` (an insertion).

**Two things it cannot do, and you need both.** A chain never notices records dropped from the
**end** — that is what `head` is for: keep it somewhere summae cannot reach and compare it later.
And two concurrent appends can read the same head and both link to it; that fork is reported as a
break, truthfully, because from the data alone a fork and a removal are the same picture.

This covers the **trail**, not the postings. A chain over the journal would need the reserved field
`previousEntryHash`, which the data format forbids writers to populate while readers are instructed
to ignore it — a chain every conforming reader is told to skip would be evidence for nobody.

```json
// params {}
{ "records": 42, "chained": 40, "unchained": 0, "redacted": 2,
  "head": "9f2c…", "intact": true, "breaks": [] }
```

### journal — the journal, windowed and paged

`fiscalYear` (**yes**), `fromDate` (no), `toDate` (no), `offset` (no, default
`0`), `limit` (no — absent means everything from the offset on). Output:
`fiscalYear`, `count`, `offset`, `limit` and `entries[]`, ordered by
`sequenceNumber`.

Each entry carries `sequenceNumber`, `entryId`, `actor`, `status`
(`entered`/`finalized`), `entryDate`, `voucherNumber`, `voucherDate`, `text`,
`reverses`, `reversedBy` and its **complete** `lines[]` — `account`,
`accountName`, `side`, `money`, `dimensions`, `taxTag`.

`actor` is **who recorded the posting** — the actor of the `post` that created it, `"system"` when
none was supplied. The entry itself carries no author; the fact lives in the audit trail, and this
is summae reading it for you rather than you rebuilding it. See
[`unfinalizedEntries`](#unfinalizedentries--postings-still-open) for why that matters.

**This is the projection to fill a journal view with**, not `journalExport` and
not `datevExport`. The export is lossless but builds five streams with a
SHA-256 each and has neither window nor paging — an archive format, paid for on
every page load. `datevExport` has the window and the weight but is DATEV-shaped
and therefore **lossy for split entries**: an expense with its input tax against
one bank line collapses into a single row and the tax line is gone.

**Paging counts entries, not lines.** A page boundary inside a split entry would
reproduce exactly the defect this projection avoids. `count` is the number of
entries in the window *before* paging, so a page header can say "51–100 of
3,204" without a second call. An offset past the end is an empty page, not an
error.

```json
// params { "fiscalYear": 2026, "fromDate": "2026-02-01", "toDate": "2026-02-28", "limit": 50 }
{ "fiscalYear": 2026, "count": 1, "offset": 0, "limit": 50,
  "entries": [ { "sequenceNumber": 2, "status": "entered", "entryDate": "2026-02-03",
    "voucherNumber": "ER-BÜRO", "text": "Bürobedarf",
    "lines": [ { "account": "6800", "accountName": "Bürobedarf", "side": "debit",
                 "money": { "amount": "40.00", "currency": "EUR" } },
               { "account": "1200", "accountName": "Bank", "side": "credit",
                 "money": { "amount": "40.00", "currency": "EUR" } } ] } ] }
```

### accounts — the chart of accounts

No parameters. Output: `accounts[]` with `number`, `name`, `type`, `subtype`,
`status`, `validFrom` and `validTo`, ordered by account number. Nothing else — no balances (that
is `trialBalance`), no movements (`accountSheet`), no hashes.

The two fields worth naming are the two that were hard to get before.
**`subtype`** says what an account is *for* — which one is the bank, which the
cash box, which receivables and payables — and it is what an application should
use to preselect a counter account. It is one of thirteen canonical values or
`null` (the repertoire is listed under `chartsOfAccounts[]`), so a caller may switch on it
exhaustively rather than defensively. Reading the **pack** instead is the trap: the
pack is the chart the tenant *started* from, and one `createAccount` later it is
a guess. **`status`** is the read side of `lockAccount`; a locked account stays
in the list, because it is still part of the chart and merely refuses postings.

**`validFrom`/`validTo`** are the window in which the account may be posted to (both `null` for
almost every account). They are the read side of the same field on
[`createAccount`](#createaccount), and unlike `status` they depend on a **date**: an account is
usable for one posting and not for another, so a picker has to be filtered against the date the
user chose, not against the account alone.

```json
// params {}
{ "accounts": [
  { "number": "1000", "name": "Kasse", "type": "asset", "subtype": "cash", "status": "active", "validFrom": null, "validTo": null },
  { "number": "8400", "name": "Erlöse", "type": "revenue", "subtype": null, "status": "locked", "validFrom": null, "validTo": null } ] }
```

### fiscalYears — fiscal years and period status

`fiscalYear` (no, scopes to one year). Output: `fiscalYears[]` with `year`,
`start`, `end`, `status` and `periods[]` (`period`, `start`, `end`, `status`),
ordered by year and period number.

This is the read side of `closePeriod`, `reopenPeriod` and `closeFiscalYear`.
Use it rather than replaying `auditLog`: the log is a **trail, not a state**, and
reconstructing "period 3 is open" from it goes wrong the moment a period is
closed by something that did not pass through your application.

`start` and `end` are what make a period *list* possible. A fiscal year running
July to June has twelve periods that are not the twelve calendar months, and
period 1 is July — an application that assumes otherwise offers input the ledger
will refuse. A year's own `status` is separate from its periods': closing every
period does not close the year, `closeFiscalYear` does.

```json
// params { "fiscalYear": 2026 }
{ "fiscalYears": [ { "year": 2026, "start": "2025-07-01", "end": "2026-06-30", "status": "open",
  "periods": [ { "period": 1, "start": "2025-07-01", "end": "2025-07-31", "status": "closed" },
               { "period": 2, "start": "2025-08-01", "end": "2025-08-31", "status": "open" } ] } ] }
```

### unappropriatedResult — what a resolution may still appropriate

`fiscalYear` (no, scopes `byFiscalYear` to one year). Output: `cumulativeResult`,
`appropriated`, `unappropriated`, `legalForm`, `resolutionRequired`, `resolutionBasis`
and `byFiscalYear[]` with `fiscalYear`, `result`, `cumulativeResult`, `available`
and `resolutionDueBy`.

This is the read side of `appropriateResult`, and until it existed the figure
could only be obtained by doing something wrong: it left the library as the
`available` detail of an `E_APPROPRIATION_EXCEEDS_RESULT` refusal, so an
application pre-filling a resolution dialog had to provoke that error on purpose
or read the balance-sheet position carrying `includesNetIncome` — which
presupposes a mapping and knowing which position that is.

**One pot, not one per year.** The `result_allocation` accounts carry what has
been appropriated and nothing in them says which year's profit they consumed, so
the three top-level figures describe the pot as a whole: `cumulativeResult` is
everything earned, `appropriated` what the allocation accounts hold,
`unappropriated` the difference. `byFiscalYear[]` says where the pot came from — `result` is that year
alone, `cumulativeResult` everything through it.

⚠ **`available` is the one field that is per year, and it is the contract.** It is
exactly what `appropriateResult` will permit for a resolution naming that year —
the same function, not a second implementation of it: what was earned through
that year, minus everything already appropriated. Above, 2026's 900.00 is gone
because the carry-forward consumed it; what is left was earned in 2027 and has to
be resolved naming 2027. A year whose figure runs past the pot reports `0.00`
rather than a phantom loss. Positive is a profit, negative a loss, as everywhere
else.

**When a resolution is due** is the pack's answer plus the tenant's own. Declare what the
company is with `setEntityProfile`, and each year reports `resolutionDueBy` — the end of the
nth month after **that year's** end, so a fiscal year running July to June is not measured
from December. `resolutionRequired` is `true`/`false` when it can be answered and **`null`
when nobody has said what the company is**: an application must be able to tell "this
jurisdiction requires no resolution" from "this tenant has not been configured", and a
default would erase the difference. `resolutionBasis` carries the pack's own citation, for
a screen that wants to say why.

Monitoring the date is yours. summae reports what the data say; who gets reminded, and what
happens when the date passes, is your workflow — the same line as everywhere else.

```json
// params {}  — 2026 earned 900.00, 2027 earned 500.00, 900.00 already carried forward,
//              tenant declared as a small GmbH on the de pack
{ "cumulativeResult": "1400.00", "appropriated": "900.00", "unappropriated": "500.00",
  "legalForm": "gmbh", "resolutionRequired": true, "resolutionBasis": "§ 42a Abs. 2 GmbHG",
  "byFiscalYear": [ { "fiscalYear": 2026, "result": "900.00", "cumulativeResult": "900.00",
                      "available": "0.00", "resolutionDueBy": "2027-11-30" },
                    { "fiscalYear": 2027, "result": "500.00", "cumulativeResult": "1400.00",
                      "available": "500.00", "resolutionDueBy": "2028-11-30" } ] }
```

### cashJournal — cash book (Kassenbuch)

`fiscalYear` (**yes**). Reports every account of subtype `cash`, so the pack (or
your chart) decides what counts as a cash register — nothing is flagged on the
posting. Output: `fiscalYear`, `accounts[]` with `account`, `name`,
`openingBalance`, `movements[]` and `closingBalance`; plus `negativeBalances[]`
and `cashCountable`.

Each movement carries `sequenceNumber`, `entryDate`, `voucherId`, `text`,
`side`, `money` and the **`runningBalance` after it**. The opening balance
carries over from the years before — a drawer does not start empty in January.

⚠ **`cashCountable` is the point of the projection.** A cash balance can never
be negative: you cannot hold less than no cash. The running balance is checked
at **every** movement, not at the close, because a day that dips below zero and
recovers is exactly what a closing balance hides. Every such point lands in
`negativeBalances[]` (`account`, `sequenceNumber`, `entryDate`,
`runningBalance`), and `cashCountable` is `false` while that list is non-empty.
Reported, never blocked — whether it stops a workflow is your application's
decision.

```json
// params { "fiscalYear": 2026 }
{ "fiscalYear": 2026, "cashCountable": false,
  "accounts": [ { "account": "1600", "openingBalance": "150.00", "closingBalance": "40.00" } ],
  "negativeBalances": [ { "account": "1600", "entryDate": "2026-03-04", "runningBalance": "-60.00" } ] }
```

### unfinalizedEntries — postings still open

`asOf` (no, default: today by the injected clock), `olderThanDays` (no, default
`0`), `fiscalYear` (no). Output: `asOf`, `olderThanDays`, `count`,
`oldestAgeInDays` and `entries[]` with `entryId`, `sequenceNumber`, `entryDate`,
`recordedAt`, `fiscalYear`, `period`, `ageInDays`, `text` and `actor`, in journal order.

**`actor` is who recorded the posting**, and it is here for **separation of duties**: the rule that
nobody may finalize a batch containing their own postings. Without it the only place the answer
existed was the audit trail, so an application checking the rule read the *entire* trail on every
finalization and rebuilt the mapping itself — a check that scales with the age of the books instead
of with the size of the batch, and an application reconstructing library state from a history.
`"system"` when the posting supplied no actor.

Note what summae does **not** do with it: it neither enforces the rule nor verifies who the actor
is (see [`systemDescription`](#systemdescription--technical-system-description)'s `notProvided`).
Binding the actor to an authenticated identity, and deciding what happens when the rule is broken,
is your application's.

The age is measured from the **`entryDate`**, not from `recordedAt`: a posting
recorded late for an old date is precisely the case a finalization deadline is
about, and measuring from the moment of recording would hide it.

Which age is too old is **not** the library's answer. GoBD asks for finalization
"at the latest with the VAT return", which is one jurisdiction's rule; the
substrate only makes the deadline observable. Pass your own `olderThanDays` and
decide what happens.

```json
// params { "asOf": "2026-03-31", "olderThanDays": 30 }
{ "asOf": "2026-03-31", "olderThanDays": 30, "count": 2, "oldestAgeInDays": 74,
  "entries": [ { "sequenceNumber": 7, "entryDate": "2026-01-16", "ageInDays": 74, "text": "Miete Januar" } ] }
```

### duplicateVouchers — the same document entered twice

No parameters. Output: `count` (groups), `voucherCount` (vouchers involved) and
`duplicates[]` with `voucherNumber`, `partnerId`, `partnerName`, `issuer`, `count`,
`stillPosted` and `vouchers[]` — each with `voucherId`, `voucherDate`, `postedTotal` (Money)
and `entries[]` (`entryId`, `sequenceNumber`, `fiscalYear`, `entryDate`, `status`,
`reverses`, `reversedBy`).

**What it is for.** `voucherNumber` is a free string and summae enforces no uniqueness on it,
so the same incoming invoice booked twice gives you two vouchers, two balanced entries and
**two input-tax deductions** — with every invariant satisfied. The entries balance, both carry
a voucher, both sit in an open period, the trial balance adds up. Nothing anywhere looks
wrong; the money is simply claimed twice. This projection is the only place that says so.

**Grouping is by document identity, not by number.** The key is the issuer plus the number:
`partnerId` where the voucher names a partner, otherwise the free-text `issuer`, otherwise
neither (those group among themselves). Two suppliers may both send their invoice number 1,
and a tenant using supplier numbers as its own will meet that in its first year — which is why
this is a **report and not a refusal**. A uniqueness rule would be wrong in a way you could not
work around. Same line as [`vatReturn`](#vatreturn--vat-return)'s `gapWarnings`.

**Three things are deliberately not reported**, because a warning list with noise in it stops
being read: a voucher with an empty `voucherNumber` (nothing to compare), a voucher flagged
`recurring` (a standing document repeating its number is what the flag means), and — per
voucher — entries that are a reversal or have been reversed. `postedTotal` counts only what
still moves the books, so a duplicate you already corrected reads `0.00` and stays in the list
**with its history** rather than vanishing; `stillPosted` is how many of the group still count.

**No parameters, and a date window least of all.** An invoice entered in December and again in
January is exactly the case this exists for, and any window on the voucher date hides it at the
boundary.

```json
// params { }
{ "count": 1, "voucherCount": 2,
  "duplicates": [ { "voucherNumber": "RE-4711", "partnerName": "Lieferant Nord GmbH",
                    "count": 2, "stillPosted": 2,
                    "vouchers": [ { "voucherDate": "2026-02-03", "postedTotal": { "amount": "595.00", "currency": "EUR" } },
                                  { "voucherDate": "2026-02-17", "postedTotal": { "amount": "595.00", "currency": "EUR" } } ] } ] }
```

### openItems — open-item list

`asOf` (no, cutoff date), `kind` (no, `receivable`/`payable`), `partnerId` (no).
Items with a remaining amount of 0 as of the cutoff date drop out. Output:
`items[]` with `id`, `kind`, `voucherNumber`, `partnerId`, `partnerName`, `due`,
`money` (original, Money), `remaining` (Money), `status`.

`partnerId`, `partnerName` and `due` are `null` where none is known — present and null, so
"no partner recorded" / "no date agreed" stays distinguishable from "this view does not
say". `due` comes from the **voucher**, so every item created by one voucher shares it; an
instalment plan with a different date per part has no place to record that yet.

`partnerName` is the name the partner has **now**, read from the master record rather than
copied onto the item when it was opened: an open item is a claim against whoever the partner
is today, and a renamed customer must not be dunned under its old name.

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
  "bookValue": { "amount": "2500.00", "currency": "EUR" },
  "specialDepreciation": { "elected": false, "allowance": null, "remaining": null } } ] }
```

Every row carries **`specialDepreciation`** — `elected` (did this asset take the additional
allowance), `allowance` (the budget it was granted) and `remaining` (what is left of it). Read it
before offering the allowance on a row: `bookSpecialDepreciation` answers with `remainingAllowance`
*after* it has run, which is too late to decide whether the control belongs on that row at all.

### costingRuns — which costing runs exist

`fiscalYear` (no), `period` (no) — both filters optional, because the answer to *which runs
exist* has to be reachable without already knowing one. Output: `runs[]`, each
`{runId, fiscalYear, period, version, status, method}`, ordered by period and then version —
the order the repository already keeps and the order a period's history reads in.

This is where a `runId` comes from. `costAllocationSheet`, `overheadRates` and `productionCost`
all require one, and until this projection existed the only way to hold a valid id was to have
kept the one `runCosting` returned — so an embedding ended up keeping its own table of run ids
beside the books, which is a second register of library state and drifts the first time a run is
created some other way.

Deliberately thin: it reports what a run *is*, never what it computed. The three projections
above are where a run's figures live, and a total repeated here would be a second answer to a
question that already has one.

```json
// params { "fiscalYear": 2026 }
{ "runs": [
    { "runId": "…", "fiscalYear": 2026, "period": 3, "version": 1, "status": "released", "method": "step_ladder" },
    { "runId": "…", "fiscalYear": 2026, "period": 3, "version": 2, "status": "draft",    "method": "step_ladder" }
  ] }
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

### overheadRates — overhead rates (Zuschlagssätze)

`runId` (yes; unknown → `E_COSTING_RUN_UNKNOWN`). Output: `runId`, `status`, `version`,
`method`, `rates[]` (each `{costCenter, label, overhead, base, rate}`) and `warnings[]`.

Where the allocation sheet says what a cost centre ended up carrying, a rate says how
that attaches to a product. The numerator is the centre after allocation; the
denominator is declared per rate in `setAllocationScheme`:

```json
{ "rates": [
    { "costCenter": "MAT",  "label": "Materialgemeinkosten",    "base": { "accounts": ["4000"] } },
    { "costCenter": "FERT", "label": "Fertigungsgemeinkosten",  "base": { "accounts": ["4100"] } },
    { "costCenter": "VW",   "label": "Verwaltungsgemeinkosten", "base": { "accounts": ["4000", "4100"], "costCenters": ["MAT", "FERT"] } }
  ] }
```

`accounts` are summed as posted in the period (debit minus credit), `costCenters` as
those centres stand *after* allocation, and the two add up. That one primitive covers
the classic set without a special case: material and production overhead over their own
direct costs, administration and sales overhead over cost of production — which is
exactly "the direct-cost accounts plus the two production centres". Direct costs are
read per **account** rather than through the `costCenter` dimension on purpose: they
belong to the product, not to a department, and are normally booked without a centre.

`rate` is a percentage with four decimals, rounded commercially (half-up, away from
zero). It is **`null`** when the base came out zero — a rate over an empty base is not
zero and not infinite but undefined, and `0.0000` would be applied to products as
though it meant something. The centre is then named in `warnings`.

Rates are computed during `runCosting` and frozen into the run, so changing the scheme
afterwards does not change what a released run says.

### productionCost — production cost (inventory valuation)

`runId` (yes; unknown → `E_COSTING_RUN_UNKNOWN`). Output: `runId`, `status`,
`version`, `total` and `components[]` (each `{id, amount, treatment, included}`).

The one cost-accounting figure that reaches the balance sheet: inventory is carried at
production cost, so which components may be counted into it is law rather than
preference. summae splits that the way it splits everything else — **the core adds the
components up, the pack says which ones may enter.** Each component the pack knows
carries one of three treatments:

| treatment | meaning |
|---|---|
| `mandatory` | must be capitalised — always counted |
| `optional` | the preparer's choice — counted only if named in `include` |
| `forbidden` | must not be capitalised — never counted, and electing it is refused |

Components are declared in `setAllocationScheme`, with the same base primitive the
overhead rates use:

```json
{ "productionCost": {
    "include": ["administration"],
    "components": [
      { "id": "materialDirect",         "base": { "accounts": ["4000"] } },
      { "id": "productionOverhead",     "base": { "costCenters": ["FERT"] } },
      { "id": "administration",         "base": { "costCenters": ["VW"] } }
    ] } }
```

Three refusals, each replacing a silent answer: a component the pack does not declare
is `E_PACK_INCOHERENT` (counting or dropping it unnoticed would move the balance sheet
either way); electing a `forbidden` one is `E_INPUT_INVALID` rather than a quiet
exclusion; and asking for the figure without configuring components is refused rather
than answered `0.00`. The projection returns **every** configured component, including
the excluded ones, with its treatment — a valuation that shows only its own total
cannot be checked against the rule it claims to follow.

The same books therefore value differently under different packs: the `de` and `us`
packs agree on full absorption of production cost and disagree about general
administration, and that single row of pack data is the whole difference.

What this does **not** do is divide by a quantity. Per-unit production cost needs
produced quantities, and summae carries none — goods movements and production orders
are your application's data. summae answers what the components add up to and why.

### assetSchedule — the fixed-asset movement schedule

`fiscalYear` (yes). Output: `fiscalYear`, `assets[]` (one row per asset), `byAccount[]` (the same
figures per asset account) and `totals`.

Each row carries the twelve figures a statutory schedule wants:

| | |
|---|---|
| `openingCost` · `additions` · `disposals` · `transfers` · `closingCost` | the cost side of the year |
| `openingDepreciation` · `depreciationOfYear` · `writeUpsOfYear` · `depreciationOnDisposals` · `closingDepreciation` | what has been written off it |
| `openingBookValue` · `closingBookValue` | what is left, at both ends |

**This is not `assetRegister` with different words.** The register reports the *stock* — cost,
accumulated depreciation, book value, at a cutoff date. The schedule reports the *year*: what stood
there at the start, what came in, what went out, what was written off, what is left. § 268 Abs. 2
HGB asks for the second, and every figure in it was already in your journal.

Three things to read carefully:

- **A disposal takes its whole accumulated depreciation with it.** It is reported under
  `depreciationOnDisposals` and the closing accumulated depreciation is zero. Netting it into the
  year's depreciation would show a year that wrote off less than it did.
- **A write-up is reported positive, under its own name.** Internally it is stored as a negative
  depreciation so every other reader picks it up without a special case; a schedule that showed it
  as "less depreciation" would hide a legally distinct event.
- **`transfers` is always `0.00`, and that is a statement rather than an omission.** summae has no
  operation that moves an asset between positions, so the column is *structurally* zero. It is here
  because a schedule missing it would be incomplete for whoever files it — not because a transfer
  could have happened and did not.

Assets acquired after the year, or disposed before it, are left out entirely rather than shown as a
row of zeros a reader has to discount.

### deferralRegister — what is deferred, over what, and how far it has run

`kind` (no), `status` (no — `open` or `settled`). Output: `deferrals[]` and `outstandingTotal`.

Each row: `deferralId`, `kind`, `reason`, `account`, `counterAccount`, `recognizedOn`, `amount`,
`released`, `outstanding`, `status`, and `plan[]` — each instalment
`{ fiscalYear, period, amount, released }`.

The `released` flag on each instalment is the point. *When will this be gone* and *what has actually
happened so far* are the two questions, and answering them from two separate lists that have to be
lined up is how a hand-kept schedule went wrong in the first place. One list, one flag.

### provisionRegister — what is set aside, for what, and what happened to it

`status` (no — `open` or `settled`), `asOf` (no). Output: `provisions[]` and `total` (the sum of
the carrying amounts reported).

Each row: `provisionId`, `reason`, `account`, `recognizedOn`, `dueDate`, `settlementAmount` (the
undiscounted estimate), `carryingAmount`, `discountRate`, `status`, and `movements[]` — each
`{ kind, date, amount, entryId, note }` with `kind` one of `recognized`, `used`, `released`,
`remeasured`.

**The movements are the point.** The balance of a provision account answers almost nothing an
auditor asks. Was it *used* because the obligation materialised, *released* because the reason
ceased, or *re-measured* because the estimate moved? Three different events, three different
postings, three different meanings — and a netted balance shows none of them. Every movement names
the entry it produced, so the register and the journal can be walked against each other in both
directions.

`asOf` cuts the **movements**, not the provisions: a provision recognised after the date is left
out entirely, one recognised before it appears with only the movements up to that date. That is
what makes the register usable as at a balance-sheet date rather than only as at today.

`status: "settled"` means the carrying amount is zero, however it got there — the *how* is in the
movements, and the difference between a release and a use is exactly what a status field must not
collapse.

### inventoryValuation — what was valued, how, and out of what

`fiscalYear` (no), `period` (no). Output: `valuations[]`, each
`{ valuationId, fiscalYear, period, version, valuationDate, runId, categories[], closingTotal,
change, entryId }`.

The read side of `valuateInventory`, and the reason the act is recorded at all. Each category row
carries `quantity`, `unitCost`, `marketValue`, the `unitValue` actually used, `source`
(`"input"` or `"productionCost"` — where the unit cost came from), `openingValue`, `closingValue`,
`change`, `changeAccount` and `writtenDownToMarket`.

A valuation that showed only its own total would be unauditable, exactly as a production cost
showing only its total would be — an inventory has to be able to show *how* it reached a figure,
not just the figure. `entryId` is `null` where nothing was booked because nothing had changed;
that is an answer, not a gap.

Every version is reported, oldest first. A re-valuation is a correction somebody made, and the
version it corrected is part of the story.

### measurementConsistency — did the way you measure change?

No parameters. Output: `runs[]` (each `{runId, fiscalYear, period, version, included[],
elected[]}`), `withoutBasis[]`, `changes[]` (each `{fromRunId, toRunId, from, to, added[],
removed[], acrossFiscalYears}`) and `consistent`.

Every framework that lets you choose how to measure something also requires you to keep
choosing the same way, and to say so when you do not (§ 252 Abs. 1 Nr. 6 HGB under the DE
pack, with the exception in Abs. 2). summae has exactly one such choice today —
`productionCost.include`, the optional components in `setAllocationScheme` — and it sits in
the tenant configuration, where nothing stops you changing it between two runs. A released
run has always **frozen** the basis it was computed under; what was missing until now was
anyone comparing two of them.

This is the comparison. It walks the **released** runs in order, states the basis each one
used, and lists every change between two consecutive runs. `included[]` is what actually
entered the valuation; `elected[]` is the optional subset you chose — a component that is
`mandatory` under your pack is in the basis because the pack says so, not because you
elected it, and mixing the two would read as a change of mind when a pack version changed.

⚠ **It reports, it does not refuse.** A changed election is not an error: the same
provisions that demand consistency allow a justified departure, so a library that rejected
one would be enforcing half a rule. What you get is the guarantee that the departure cannot
pass unnoticed — the same line `gapWarnings` and `duplicateVouchers` draw. `acrossFiscalYears`
marks the case that needs an explanation in your notes: within one year the change is still
absorbed by that year's own result, across a boundary the two years stop being comparable.

`withoutBasis[]` is the third state and it is not padding: a released run configured with no
production cost at all has not changed the basis, so it is not a `change` — but if you are
comparing two years you need to know the second measured nothing rather than measured the
same. An omission that is not stated reads as agreement.

Every released run appears, not just the newest per period. Re-releasing a period under a
different basis **is** a change of measurement, and one that whoever read the earlier version
has already relied on.

No parameters, and that is a decision: a `fiscalYear` filter would hide exactly the
across-year change this exists to report. When stock and provisions arrive with measurement
options of their own, they will appear here rather than in a second projection — the question
"did the way you measure change" is asked once.

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
`payload` (Money: Σ output tax − Σ input tax) and `gapWarnings[]`.

⚠ **`gapWarnings` is the field to read before filing.** The return is built from
tax-*coded* postings — the tax tag carries the reporting key. A posting made by
hand onto a tax account (`subtype` `tax_in`/`tax_out`) has no such tag, so it
balances, satisfies every invariant, shows correct figures on the accounts and
in the trial balance, and contributes **nothing** to the return. Every such line
in the window is listed here with `reason`
(`tax_account_without_tax_code`), `sequenceNumber`, `entryDate`, `account`,
`side` and `money`, in journal order.

It is reported, never blocked: a correction posting legitimately touches a tax
account, and refusing it would stop the repair along with the mistake. An empty
list is the statement "nothing in this period bypassed the tax codes". Which
accounts count comes from the chart, so a jurisdiction without input-tax
deduction has no `tax_in` account and never sees the warning.

```json
// params { "year": 2026, "quarter": 2, "asOf": "2026-07-01" }
{ "keys": { "81": { "base": "1000.00", "tax": "190.00" }, "66": { "tax": "19.00" } },
  "payload": { "amount": "171.00", "currency": "EUR" },
  "gapWarnings": [ { "reason": "tax_account_without_tax_code", "sequenceNumber": 7,
                     "entryDate": "2026-04-12", "account": "1576", "side": "debit",
                     "money": { "amount": "14.25", "currency": "EUR" } } ] }
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
change) and that result is not carried into equity on its own.

**Carrying the result forward is an entry you make, not something the close does.**
Appropriating profit is a resolution — who decides, and whether the result is
distributed, put into reserves or carried forward, is not something a library can
know — so it arrives as an ordinary posting: the pack's `result_allocation`
account against retained earnings or a distribution liability (`de`: `2300` to
`2100`; `us`: `3300` to `3100`). The position with `includesNetIncome` reports the
**cumulative result minus the balance of the `result_allocation` accounts**, i.e.
the result *not yet appropriated*, so the amount moves out of it and into equity
the moment you book the resolution. Book it with the date of the resolution, which
usually falls in the following fiscal year. Until you do, the position keeps
reporting the accumulated result of every year since the books opened — which is
correct, and is why its label should not promise "this year".

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
`{category, amount}`, sorted by category), `totalIncome`, `totalExpenses` and
**`surplus`** — the figure the statement exists to produce.

```json
// params { "year": 2025, "asOf": "2026-06-07" }
{ "income": [ { "category": "Betriebseinnahmen", "amount": "5000.00" } ],
  "expenses": [ { "category": "Raumkosten", "amount": "1200.00" } ],
  "totalIncome": "5000.00", "totalExpenses": "1200.00", "surplus": "3800.00" }
```

> **Do not compute the surplus yourself.** It is here for the same reason
> `incomeStatement` publishes `netIncome` and `balanceSheet` both totals: subtracting one of a
> projection's fields from another is exactly the arithmetic this library asks embeddings not to do,
> and until 2026-08-24 the third statement of that family was the one that made it necessary.

### ecSalesList — EC sales list (zusammenfassende meldung, ZM)

`year` (yes), `quarter` (no). Intra-community supplies per VAT ID (from the key
tags of the igL codes; partner via the voucher). Output: `rows[]` (`vatId`,
`amount`, `kind`) and `gapWarnings[]`.

**A supply that cannot be reported is reported as unreportable.** The list is keyed by VAT ID, so a
supply whose partner has none has no row to go in — and until 0.13.0 it simply vanished: two
postings, one with a VAT ID and one without, and the answer was one row and nothing else. That is
the dangerous direction, because without the recipient's VAT ID the supply is not exempt in the
first place (§ 6a Abs. 1 Nr. 3 UStG), so what dropped out was exactly the case where something is
wrong. Each warning carries `reason`, `sequenceNumber`, `entryDate`, `reportingKey`, `money` and
`partnerId`, in journal order — the same shape and the same reasoning as
[`vatReturn.gapWarnings`](#vatreturn--vat-return-umsatzsteuer-voranmeldung).

Two reasons, because the fix differs: `partner_without_vat_id` (the voucher names a partner, the
partner has no VAT ID — add it to the master data) and `supply_without_partner` (the voucher names
no partner at all — the posting has to say who it went to).

It is **not** a refusal. Whether a missing VAT ID makes the supply taxable is your call and your
jurisdiction's; summae's job is that the case is never invisible.

```json
// params { "year": 2026, "quarter": 1 }
{ "rows": [ { "vatId": "ATU12345678", "amount": "1000.00", "kind": "supply" } ],
  "gapWarnings": [ { "reason": "partner_without_vat_id", "sequenceNumber": 2,
                     "entryDate": "2026-03-11", "reportingKey": "41",
                     "money": { "amount": "500.00", "currency": "EUR" },
                     "partnerId": "…" } ] }
```

### journalExport — GoBD Z3 export

`fiscalYear` (**yes**), `format` (no; the only accepted value is `"gobd-z3"`, which is
also the default — anything else is `E_INPUT_INVALID` rather than silently the Z3
stream under a wrong label). The manifest's `formatVersion` always states the current
data-format version, `"0.10"`. Output: `manifest` (`formatVersion`,
`tenantId`, `exportedAt`, `hashAlgorithm:"sha256"`, `streams`, `contentHashes`),
`fieldCatalog`, `journal` (`entryCount`, `ordering`, `allFinalized`), `data`
(`journal`, `accounts`, `vouchers`, `partners?`, `auditLog`). `contentHashes` =
SHA-256 over RFC-8785-canonicalized rows per stream. The audit trail is always
part of the export.

### gdpduExport — the Z3 data carrier an auditor's IDEA import expects

`fiscalYear` (no; absent means the whole ledger), `mediaName` (no; default `"Disk1"`). Output:
`standard`, `dtd`, `indexXml`, `tables[]` (`url`, `name`, `rowCount`, `content`) and `notProvided[]`.

**What this is, next to [`journalExport`](#journalexport--gobd-z3-export).** `journalExport` gives
you the *self-describing data set* — JSON streams plus a field catalogue plus content hashes — which
is what a machine-evaluable handover under GoBD Z3 requires. What a German tax auditor actually
receives on the medium is different in shape: **flat files plus an `index.xml`** written to the
*Beschreibungsstandard für die Datenträgerüberlassung*, which is what the audit software IDEA
imports. `gdpduExport` produces exactly that. It is a **mapping and not a second truth**: every value
in it already exists in the books.

Written against **standard version 1.6 of 1 March 2019**, DTD `gdpdu-01-03-2019.dtd`.

**The tables**, each with its columns typed and described in `index.xml`:

| File | Content | Primary key |
|---|---|---|
| `journal.csv` | one row **per posting line**, entry header repeated | `entryId` + `lineNumber` |
| `accounts.csv` | chart of accounts | `number` |
| `vouchers.csv` | voucher master data (never the images) | `voucherId` |
| `partners.csv` | debtors/creditors — **only when the tenant has any** | `partnerId` |
| `auditLog.csv` | the change history incl. both chain hashes | `recordId` |

The journal is flattened to line level because a CSV cannot nest and because the first thing an
auditor does is sum debit and credit per account, which needs the line. `index.xml` declares foreign
keys from `journal.csv` to `accounts.csv` and `vouchers.csv`, so IDEA can join the five files rather
than treat them as unrelated.

**Three things you must do yourself**, and they are repeated in the response's `notProvided`:

1. **Write the files.** summae is a library and owns no file system. `indexXml` and every table come
   back as content; you write them into one folder, `index.xml` at its root.
2. **Put `gdpdu-01-03-2019.dtd` beside `index.xml`.** The standard requires it on the medium. summae
   names the version it wrote against but does not ship the file — it is the standard publisher's
   normative document, and a library that quietly redistributed it would be making a promise about
   its version that it cannot keep.
3. **Supply the voucher images** from your archive. The carrier holds the bookkeeping data and the
   voucher *reference*, as everywhere else in summae.

Amounts are written the way summae stores them — dot as the decimal symbol, no digit grouping — and
`index.xml` declares that per table, because the standard's own defaults are the German ones and
would read `1234.56` as one million. Dates are ISO (`YYYY-MM-DD`), declared explicitly for the same
reason. Encoding is UTF-8, columns are `;`-delimited, values are quoted only where they have to be.

```json
// params { "fiscalYear": 2026 }
{ "standard": "Beschreibungsstandard für die Datenträgerüberlassung 1.6 (2019-03-01)",
  "dtd": "gdpdu-01-03-2019.dtd",
  "indexXml": "<?xml version=\"1.0\" encoding=\"utf-8\" standalone=\"no\"?>…",
  "tables": [ { "url": "journal.csv", "name": "Journal", "rowCount": 12, "content": "entryId;lineNumber;…" } ],
  "notProvided": [ "gdpdu-01-03-2019.dtd itself — …", "Writing the files. …", "Document images. …" ] }
```

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

### systemDescription — technical system description

No parameters. Output: `formatVersion`, `tenant` (`id`, `name`,
`baseCurrency`), `pack` (the manifest identity the tenant was composed from, or
`null` for an inline rule bundle), `taxProfile` (the profile the engine is actually
running on — taxation method, filing period, the small-business segments),
`journal` (append-only, ordering, lifecycle, correction rule, the three dates),
`invariants[]`, `auditTrail`, `capabilities` and `notProvided[]`.

`taxProfile` is here because a Verfahrensdokumentation has to state which taxation method the
books were kept under — and because until 2026-08-24 nothing published it, so an application could
display only what it had written itself. Those are the same value by construction *in one
embedding*, which is a property of that caller and not a guarantee.

#### `auditTrail.actorAuthentication` — who is behind `actor`

summae is handed an `actor` string and cannot know where it came from. `byLibrary` says so and can
never go stale; `actorIsAuthenticated: false` has always meant the same thing and keeps its value.

What summae cannot know, **you** can, and you are the only one who can:

```json
// summae.json — read on every open, never stored with the books
"actorAuthentication": { "declared": true, "method": "scrypt password login, signed session cookie" }
```

```json
// systemDescription → auditTrail
"actorAuthentication": { "byLibrary": false, "declaredByEmbedding": true,
                         "method": "scrypt password login, signed session cookie" }
```

**Three states, and the third is the point.** `true` and `false` are statements; **`null` is not a
"no"** — it means nothing was declared, and a generator that turns it into "Urheber geprüft: nein"
is making a claim summae did not make. An unanswered question and a denial read differently to an
auditor, and the difference matters in exactly the document this projection exists for.

Note what summae is doing here: **reporting your declaration, not endorsing it.** It cannot verify
that a login exists, and it does not pretend to — `declaredByEmbedding` is your sentence, quoted.
That is still worth more than writing the line by hand into the document, because it is
configuration you version and deploy rather than prose that quietly stops matching the software.

It is deliberately **not** stored with the tenant. This describes the running installation, not the
books: drop your login tomorrow and yesterday's claim must not survive in a record.

This is the **Verfahrensdokumentation** building block a library can supply
(GoBD Rz. 151 ff.). Of its four parts, three describe *your* installation and
processes and no library can write them. The technical one is different: what
the engine enforces, which operations exist, how the journal behaves and what
the stored format looks like are facts about the software — and stating them by
hand means stating them wrong within a release. So this projection reports them
from the same constants the engine runs on.

Each entry in `invariants[]` names the mechanism that makes it true
(`enforcedBy`), so the claim can be checked rather than believed.
`capabilities.operations` / `.projections` are the **published API surface**:
what the dispatcher routes and this list names are held equal by a contract test
in both languages, in both directions — a capability the software has but does
not publish is a description that lies by omission, and a published name that
routes nowhere is a promise it cannot keep. If your application validates its
calls against summae's surface, this is the list to validate against.

`notProvided[]` states the limits in the same breath — most importantly that the
actor in the audit trail is recorded **as supplied by the caller** and never
verified. Binding it to an authenticated identity is your application's job.

```json
// params {}
{ "formatVersion": "0.7", "pack": { "id": "de", "version": "2026.4" },
  "invariants": [ { "id": "append-only-journal", "statement": "The journal is append-only. …",
                    "enforcedBy": "No delete or update path exists on the journal repository." } ],
  "capabilities": { "operations": ["acquireAsset", "…"], "projections": ["accountSheet", "…"] } }
```

### personalDataDescription — where operator-supplied text can sit

No parameters. Output: `formatVersion`, `fields[]` (`holder`, `field`, `freeText`, `required`,
`present`, and `mirrors` where a field copies another), `addressKeys[]`, `counts`
(`partners`, `vouchers`, `distinctActors`) and `classification`.

**The counterpart to `systemDescription`, for people instead of events.** That projection answers
*what does this system record, and about what*; this one answers *where can a name, an address or
an identifier come to rest in these books, and how much of it is actually here*. If you are
assembling a record of processing activities, this is the inventory — and it is generated, so it
cannot quietly stop describing the software the way a hand-written list does.

**It does not classify, and says so in the payload.** `classification` is the literal string
*"none"* plus the reason. Whether a field counts as personal data is answered differently by
different jurisdictions — a company identifier is personal data for a sole trader and not for a
corporation — so summae reports the *mechanism* (this field holds free text summae neither
constrains nor interprets) and leaves the legal reading to you. summae's own reading for the
German/EU case is [GDPR conformance](../gdpr-conformance.md) §1.

**It reports shape, never content.** `present` is how many partners carry an address, not what any
address says. `addressKeys` is which keys occur across the tenant, not their values. A projection
built to help with a privacy obligation must not become the convenient way to read everybody's data
out — for the records themselves you already have `journalExport`.

`present: null` on two rows is deliberate: a posting text and an audit diff exist per record rather
than per holder, and a count there would be a number nobody could act on.

**Three of the rows are not in `journalExport`, and that is the point of them** (since 0.18.0):
`asset.name`, `provision.reason` and `deferral.reason` are operator free text on *stored aggregates*,
which the Z3 export does not carry. An Art. 30 record assembled from the export alone misses them —
and a provision is by its nature often about a named party: a dispute, a warranty claim, a severance.
They were missing from this list too until 2026-08-29; the list and the document that mirrors it now
hold each other, in both languages, so neither can quietly stop describing the software.

⚠ **`addressKeys` is the row worth reading.** The data format declares a recommended address shape
(`line1`, `line2`, `postalCode`, `city`, `region`, `country` as ISO 3166-1 alpha-2) and does **not**
forbid other keys — books written before that shape existed carry whatever they carry, and refusing
them would make an export of lawful data invalid. So the declaration says what to *write*, and
`addressKeys` says what is actually *there*. If it lists something you did not expect, that is an
application putting data into the ledger that nobody planned for — which is exactly the thing a
hand-written inventory never catches.

```json
{ "addressKeys": ["city", "country", "line1", "postalCode"],
  "counts": { "partners": 2, "vouchers": 1, "distinctActors": 2 },
  "fields": [ { "holder": "partner", "field": "address", "freeText": true,
                "required": false, "present": 1 }, … ] }
```

### tenantConfiguration — what this tenant is set up as

No parameters: a tenant has exactly one configuration, so there is nothing to select. Output:
`taxProfile`, `dimensionTypes[]`, `dimensionValues[]`, `dimensionRules[]`,
`accountCombinationRules[]`, `accountUsageRules[]`, `allocationScheme`
(raw, exactly as `setAllocationScheme` accepts it, or `null`), `mappings[]`
(`{id, kind, version}` each), `appropriationTargets[]`, `entityProfile`, `legalForms[]` and
`sizeClasses[]`.

This is the read side of [what summae stores](#what-summae-stores-and-what-you-store). Five
things live in `summae_tenants.config`, six operations change them, and until 0.13.0 exactly
one of them was reported back — the tax profile, through `systemDescription`. The rest could be
written and never read.

**Why that mattered more than an ordinary gap.** Before the configuration was persisted, your
application passed its cost centres in on every open, so your copy was the truth by construction.
Since 0.12.0 the stored record wins and what you pass is a seed that is ignored from the second
open on: summae's copy is the truth and yours is a guess — and nothing let you check it. A screen
with a cost-centre field could learn the accepted values only by posting and reading
`E_DIMENSION_INVALID`.

**It reports what is in force, not what is stored**, and the two places those differ are the
point:

- **`dimensionRules[]` are the pack's** — which accounts may not be posted without which
  dimension. They stand in no record at all (they come back from the pack on every open), and you
  cannot derive them from the pack file without reimplementing the resolver. This is how a form
  knows which field it must not leave empty.
- **`accountCombinationRules[]` and `accountUsageRules[]` are the pack's too**, and are reported
  for the same reason: a booking screen can grey out a forbidden account before the user posts,
  instead of translating an exit code afterwards. A rule carrying `appliesWhen` is listed **even
  when it is currently dormant** — a caller that could not see it would be unable to tell an absent
  rule from one waiting on a fact the tenant has not declared.
- **`mappings[]` lists the pack's mappings and the imported ones together.** The record holds only
  the imports, so a projection mirroring it would answer "none" for a `de` tenant whose
  `balanceSheet`, `incomeStatement` and `cashBasisReport` all work. These are the names those
  three projections accept as `mapping`.

- **`appropriationTargets[]` are the pack's too** — the names `appropriateResult` accepts, sorted.
  `de` answers `["carryForward", "distribution"]`, `us` and `default` answer `["carryForward"]`,
  and a pack that supports no appropriation answers `[]`. Without it a screen offering "carry
  forward / distribute" would have to find out by provoking `E_APPROPRIATION_UNSUPPORTED`, which
  is a poor way to build a menu.
- **`entityProfile` is what you declared, `legalForms[]`/`sizeClasses[]` what you may declare.**
  The first is stored library state (`setEntityProfile`) and `null` until you set it; the other two
  are the pack's catalogue, so a "Rechtsform" field can be built from them instead of carrying its
  own list and hoping the two agree. A pack that ships no catalogue answers with two empty lists,
  which is also the honest answer for a jurisdiction-free one.

**Identity is not repeated here** — id, name, base currency and pack are `systemDescription`'s
`tenant` and `pack` blocks, which report all four. This projection answers the other question.

**Mapping identity only, never the positions.** The definitions are your pack's, and summae keeps
no copy of it on purpose: two answers to "which rules is this tenant on" is one answer too many.

```json
// params {}
{ "taxProfile": { "taxationMethod": "accrual", "vatPeriod": "quarterly", "smallBusiness": [] },
  "dimensionTypes":  [ { "code": "costCenter" } ],
  "dimensionValues": [ { "typeCode": "costCenter", "code": "FERT" },
                       { "typeCode": "costCenter", "code": "VERW" } ],
  "dimensionRules":  [ { "accountRange": { "from": "6000", "to": "6999" },
                         "requiredDimension": "costCenter" } ],
  "allocationScheme": { "method": "step_ladder",
                        "steps": [ { "sender": "VERW", "receivers": [ { "code": "FERT", "share": "1" } ] } ] },
  "mappings": [ { "id": "de-bilanz", "kind": "balance-sheet",   "version": "2026.4" },
                { "id": "de-guv",    "kind": "income-statement", "version": "2026.4" } ] }
```

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
