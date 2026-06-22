# summae — Node/TypeScript implementation

Second runtime alongside the PHP reference, against the **same contract**: the same
conformance suite (`testsuite/` in the repo root), identical data format,
byte-identical determinism. The goal is full parity and, ultimately,
cross-compatibility with the PHP data.

> Status: **M3 reached** — all **45/45** conformance fixtures green against the
> in-memory port, double run byte-deterministic. Shared kernel, ledger,
> open items, tax, EÜR/VAT return, mappings (balance sheet/P&L), assets, costing, partner,
> createTenant and export (GoBD-Z3/DATEV) are ported. Open: persistence
> adapter + CLI (M4) and the cross-test against the PHP data.

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
read the shared `testsuite/` in the repo root.

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
