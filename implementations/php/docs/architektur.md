# Architecture (PHP)

PHP-specific: packages, paths, adapters. The **language-neutral mental model**
(jurisdiction-free substrate → three policy kinds → pack → configuration) lives in
[`/docs/architektur.md`](../../../docs/architektur.md) — it applies to all
implementations and is required reading when building.

## Three packages, one repo

| Package | Composer name | Role |
|---|---|---|
| `packages/core` | `superheld/summae-core` | Framework-free core. All accounting logic. Only dependency: `brick/math`. |
| `packages/laravel` | `superheld/summae-laravel` | Adapter: DB persistence (`illuminate/database` query builder, **no ORM**), ServiceProvider, migrations. **No domain logic.** |
| `packages/cli` | `superheld/summae-cli` | Terminal tool (`summae`), JSON in/out. Uses core + laravel persistence. |

Alongside them `superheld/summae-php` (`implementations/php/composer.json`) — the
PHP developer workbench, **not** a shipped package. `runner/` is the fixture
runner (not published, conformance checking only).

## Why the core is framework-free

Litmus test: *"Would this line make sense in a Symfony or Node project too?"*
→ then it belongs in the core. No `use Illuminate\…` in `packages/core`.

Three reasons:

1. **The conformance suite runs against the core in milliseconds** (in-memory
   port, no Laravel boot, no DB). A red test is then unambiguously a domain
   error, not a persistence error.
2. **Laravel moves, accounting does not.** Major upgrades touch only the thin
   adapter; the audited, GoBD-relevant core stays untouched.
3. **Multi-language.** The same cut is mirrored in Node
   (`core` + `nestjs/express` adapter) and Python.

## Hexagonal: ports & adapters

The core defines **ports** (interfaces in `packages/core/src/Port/`) and knows
no concrete persistence:

```
AccountRepository   FiscalYearRepository   VoucherRepository
JournalRepository   OpenItemRepository     PartnerRepository
AssetRepository     AuditTrail
```

Two adapter sets implement them:

- **In-Memory** (`packages/core/src/InMemory/`) — for tests, conformance runs,
  the CLI logic. Fast, no I/O.
- **Database** (`packages/laravel/src/Repository/`, classes `Database*Repository`) —
  for the real DB. Persists the aggregate internals as JSON documents in
  `summae_*` tables, exactly in published-language form. Uses the
  **`illuminate/database` query builder** (`$connection->table(...)`), **no ORM**
  (no `extends Model`). Named by role (not by the tool) — the Node counterpart
  `@superheld/summae-knex` names its classes `Database*` as well and uses Knex
  as the query builder. See `/docs/architektur.md`.

A tenant is assembled by:

- `Tenant::inMemory(...)` — the core for in-memory operation.
- `DatabaseTenantFactory::build(...)` — the same `Tenant`, only with DB ports.

Both yield the same `Tenant`; everything above is identical.

## Domain layering

The layering model (substrate → policy kinds → pack → configuration) is
language-neutral and lives in [`/docs/architektur.md`](../../../docs/architektur.md).
In concrete PHP terms: the `core` is the substrate; rule-module/pack data are
passed to the factory (`Tenant::inMemory` / `DatabaseTenantFactory::build`) as
data; the app is the user's Laravel project.

The source tree of `core` mirrors the two axes directly (PascalCase folders):

- **`Substrate/`** — frozen, jurisdiction-free (zero-sum posting, account,
  journal, balance, period). Imports nothing from above.
- **`Policies/`** — the three policy kinds, **socket** only (law-free mechanism);
  the **plugs** (data) live in `/pack-library/` and are injected:
  - **`Policies/Expansion/`** — intent → balanced postings (tax · assets ·
    costing · settle difference · reverse)
  - **`Policies/Projection/`** — journal → view (fold engines + `Mapping/`)
  - **`Policies/Constraint/`** — predicate gates (still thin; the third kind is
    unfinished)
- **`Composition/`** — resolver · factory · tenant · dispatcher (dependency
  inversion: the core never imports a pack)
- **`Records/`** — vouchers/records (Voucher · OpenItem · Audit), **not** a
  policy kind; may reference the substrate (data layer)
- **`Partner/`** — supporting subdomain (master data), **not** a policy kind
- **`Ledger/`** — `Ledger.php`, the orchestrator (see below)
- **`Port/` · `InMemory/`** — the hexagon edge / outside

## CLI picks a pack

`summae init --pack de` loads the pack from the shipped
`pack-library/` (`packages/cli/src/PackLibrary.php`: `packToRules` = `PackResolver::resolve` →
`ruleModulesFromResolved` → CLI `rules` structure) and writes the resolved rules into the
workspace. `--pack-library <dir>` overrides the path; alternatively a custom `--rules` file.
Byte-identical to the Node CLI counterpart (`packages/cli/src/pack-library.ts`).

## Iron invariants

- **Journal append-only; balances are projections.** Never store a balance —
  every trial balance / balance sheet / EÜR is recomputed from the journal.
- **Money never as float.** `Money` on `brick/math`, half-up (commercial),
  `allocate` with largest-remainder. See [konformitaet.md](konformitaet.md).
- **Determinism.** Same input → byte-identical result (incl. ordering,
  rounding), across all implementations.

## Single entry point: `TenantOperations`

`packages/core/src/Composition/TenantOperations.php` is the dispatcher for
**all** operations (`post`, `postVoucher`, `settle`, …) and projections
(`trialBalance`, `vatReturn`, `journalExport`, …) of a tenant — names exactly
per API spec. CLI and conformance runner use the same dispatcher; that keeps
the operation list in *one* place.

## Data flow of a posting (example)

```
postVoucher(input)
  → TaxService::expand     (tax expansion, rounding per voucher)
  → Ledger::post           (check order, invariants, journal number)
      → JournalRepository::append   (port → in-memory or database)
      → open-item automation for AR/AP
      → AuditTrail::append
  → PostResult (entry + generated open items)
```

`Ledger.php` (in `Ledger/`) is the orchestrator: it currently fuses `post`
(substrate) + settle/reverse (expansion) + close (constraint) in *one* class.
Splitting those **methods** apart is gated on the open **closed/open** decision
(see `packages/core/src/CLAUDE.md`); the directory split itself is done.

Reading never goes through stored balances, only through the projections in
`packages/core/src/Policies/Projection/`.
