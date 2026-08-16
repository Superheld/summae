# summae — Node/TypeScript implementation

Second runtime alongside the PHP reference, against the **same contract**: the same
conformance suite (`testing/testsuite/` in the repo root), identical data format,
byte-identical determinism. The goal is full parity and, ultimately,
cross-compatibility with the PHP data.

> Status: **complete and at parity.** The whole conformance suite is green against both the
> in-memory port and the persistence adapter, with a byte-deterministic double run; the
> SF-15 cross-test confirms byte-identical `journalExport` across the language boundary in
> both directions. Shared kernel, ledger, open items, tax, EÜR/VAT return, mappings (balance
> sheet/P&L), assets, costing, partner, `createTenant`, export (GoBD-Z3/DATEV), pack
> composition, persistence (`@superheld/summae-knex`) and CLI (`@superheld/summae-cli`) all
> ship. (Fixture counts are not pinned here — they drift; `pnpm fixtures` prints the current
> one.)

## Stack

| | Choice | Why |
|---|---|---|
| Language | TypeScript (strict, ESM) | |
| Workspaces | **pnpm** | strict dependency isolation keeps the core framework-free |
| Tests | **vitest** | TS/ESM-native, test-first |
| Money | **big.js** | decimal-exact, `roundHalfUp` = away-from-zero; small surface = few determinism traps |

## Layout

```
implementations/node/
├── packages/core/   framework-free accounting core (@superheld/summae-core)
└── runner/          conformance fixture runner (@superheld/summae-runner)
```

CLI and persistence adapter (NestJS/Express, Prisma/Knex) arrive from **M4** on as
their own packages. The **test suite is not copied here** — runner and tests
read the shared `testing/testsuite/` in the repo root.

## Commands

```bash
pnpm install      # once (link the workspace)
pnpm test         # vitest — unit + conformance fixtures (conformance.test.ts)
pnpm fixtures     # conformance suite against the core (--strict / --filter=name)
pnpm typecheck    # tsc --noEmit, strict
pnpm lint         # eslint (incl. guard: no framework import in the core)
```

## Usage & docs

- **[Handbook](../../docs/handbuch/README.md)** — installation, initialization,
  configuration and usage (cross-language, with Node examples).
- `packages/core/README.md` — public API (`TenantOperations`), runnable
  example.
- `runner/README.md` — runner commands, subject contract, regression guard.
