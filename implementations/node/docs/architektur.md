# Architecture (Node/TypeScript)

Node-specific: packages, paths, adapters. The **language-neutral mental model**
(jurisdiction-free substrate → three policy kinds → pack → configuration) is in
[`/docs/architektur.md`](../../../docs/architektur.md) and the root `CLAUDE.md` — it applies
to all implementations and is required reading when building. PHP is the reference; Node
mirrors it **1:1** (byte parity is a contract, see [`konformitaet.md`](konformitaet.md)).

## Packages, one pnpm workspace

| Package | npm name | Role |
|---|---|---|
| `packages/core` | `@superheld/summae-core` | Framework-free domain core. All accounting logic. Sole runtime dependency for the math: `big.js`. |
| `packages/knex` | `@superheld/summae-knex` | Adapter: DB persistence via **Knex** as query builder (better-sqlite3 / pg), **no ORM**. Classes named by role `Database*`. **No domain logic.** |
| `packages/cli` | `@superheld/summae-cli` | Terminal tool (`summae init|op|report`), JSON in/out, persistent SQLite. Uses core + knex. |

Alongside them, `runner/` — the fixture runner (not published, conformance check only).

## Why the core is framework-free

Litmus test: *"Would this line also make sense in a PHP or Python project?"* → it belongs
in the core. **Technically enforced:** eslint `no-restricted-imports` forbids web frameworks,
DB drivers and ORMs (`express`/`knex`/`pg`/`prisma`/`typeorm`/…) inside `packages/core/**`
— a framework import in the core is a lint error. The counterpart to "no `use Illuminate\…`" on the PHP side.

## Hexagonal: ports & adapters

The core defines **ports** (interfaces in `packages/core/src/port.ts`) and knows no concrete
persistence:

```
AccountRepository   FiscalYearRepository   VoucherRepository
JournalRepository   OpenItemRepository     PartnerRepository
AssetRepository     AuditTrail
```

Two adapter sets implement them:

- **In-memory** (`packages/core/src/in-memory.ts`) — for tests, conformance runs, CLI logic. No I/O.
- **Database** (`packages/knex/src/repositories.ts`, classes `Database*Repository`) — real DB.
  Persists the aggregate internals as JSON in `summae_*` tables, bit-for-bit in the schema of
  the PHP side (shared DB). Uses **Knex** (`$db.table(...)`), **no ORM**.

A tenant is assembled by:

- `Tenant.inMemory(...)` (`packages/core/src/composition/tenant.ts`) — core for in-memory operation.
- `DatabaseTenantFactory.build(...)` (`packages/knex/src/database-tenant-factory.ts`) — the same
  `Tenant`, only with DB ports.

Both yield the same `Tenant`; everything above it is identical. (Both call `Tenant.fromPorts(...)`
internally — *the* actual assembler, which wires the services over the supplied ports.)

## Internal structure of `core/src/`

The core's source tree follows the two axes of the domain (identical 1:1 in PHP, there with
PascalCase folders — full picture in [`../packages/core/src/CLAUDE.md`](../packages/core/src/CLAUDE.md)):

- **`substrate/`** — frozen, jurisdiction-free primitives (posting summing to 0, account, journal,
  balance, period, plus enums). Imports nothing from above.
- **`ledger/`** — the orchestrator `ledger.ts`, which threads `post` (substrate) + `settle`/`reverse`
  (expansion) + `close` (constraint) together.
- **`records/`** — vouchers/records (voucher · open-item · audit), data layer, **not** a policy kind;
  may reference the substrate.
- **`policies/`** — the three policy kinds; here only the **socket** (law-free mechanics), the
  **plugs** (data) live in `/pack-library/` and are injected:
  - **`policies/expansion/`** — intent → balanced postings (tax · assets · costing · settle difference · reverse)
  - **`policies/projection/`** — journal → view (fold engines + mappings)
  - **`policies/constraint/`** — predicate gates (still thin; the third kind is unfinished)
- **`composition/`** — resolver · factory · tenant · dispatcher (dependency inversion; the core never imports a pack).
- **`partner/`** — supporting subdomain (master data), **not** a policy kind.
- **`port.ts` · `in-memory.ts`** — the hexagon edge (ports) and the in-memory adapters (fakes).

Real persistence (`knex`/`laravel`) lives in **separate packages** outside `core`; `core` holds
only the in-memory adapters.

## General entry point: `TenantOperations`

`packages/core/src/composition/tenant-operations.ts` is the dispatcher for **all** operations
(`post`, `postVoucher`, `settle`, …) and projections (`trialBalance`, `vatReturn`, `journalExport`, …)
— names exactly per the API spec. CLI and conformance runner use the same dispatcher; this keeps the
operation list in *one* place. (Recipe "add a new operation": [`entwicklung.md`](entwicklung.md).)

## Configuration: rule modules & pack

The engine eats *one* resolved `ruleModules` bundle (chart of accounts, taxCodes, mappings,
assetAccounts, depreciation, packPolicy). Two paths lead there:

- **inline** — the bundle is supplied directly (CLI today via `summae.json`; fixtures via `setup`).
- **composed** — a manifest + modules from the shipped `pack-library/` are resolved by the
  `PackResolver` (`packages/core/src/composition/pack-resolver.ts`); the loader
  `runner/src/pack-library.ts` reads the library from disk. `createTenant(pack:"…")`
  pins the manifest. **`packPolicy` parametrizes** the core (`currencyScale`→`Currency`,
  `taxRoundingGranularity`→`TaxService`). Details: root `CLAUDE.md`, section "Packs konkret".

**The CLI picks a pack.** `summae init --pack de` loads the pack from the shipped
`pack-library/` and writes the resolved rules into the workspace:
`packages/cli/src/pack-library.ts` (`loadPackLibrary` content-based + `packToRules` =
`resolvePack`→`ruleModulesFromResolved`→CLI `rules` structure). `--pack-library <dir>` overrides the
path (default: repo root/`pack-library`). Alternative to `--pack`: a custom `--rules` file.

## Data flow of a posting (example)

```
postVoucher(input)
  → TaxService.expand     (tax expansion, rounding per packPolicy)
  → Ledger.post           (check order, invariants, journal number)
      → JournalRepository.append   (port → in-memory or database)
      → open-item automation for AR/AP
      → AuditTrail.append
  → PostResult (entry + generated open items)
```

Reads never go through stored balances, but through the projections in
`packages/core/src/policies/projection/`.
