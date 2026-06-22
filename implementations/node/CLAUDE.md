# CLAUDE.md — Node/TypeScript implementation

Language-specific rules and commands for `implementations/node/`. Project-wide rules
(iron invariants, quality policy, `testsuite/` read-only, Git) are in the
root `CLAUDE.md`.

**Status:** domain core (`packages/core`) complete against the in-memory port. Persistence adapter
`@superheld/summae-knex` (Knex + better-sqlite3), also against `--subject=database`; the
**SF-15 cross-test** (`make cross`) confirms byte-identical `journalExport` across
the language boundary; same-language CLI `@superheld/summae-cli` (`summae init|op|report`,
persistent SQLite, exit codes = error catalog); **pack composition** (resolver + loader)
built. Packages: `core`, `knex`, `cli` + `runner`. (Do not hardcode fixture counts here —
they drift; the current count comes from `pnpm fixtures`.)

## Commands

pnpm workspace, local (no Docker needed):

```bash
pnpm install
pnpm test          # vitest (unit + conformance test)
pnpm test:watch    # vitest in watch mode
pnpm typecheck     # tsc --noEmit
pnpm lint          # eslint
pnpm build         # tsup per package (ESM + CJS + .d.ts)
pnpm fixtures      # conformance runner (tsx); --strict = double run byte-identical
```

## Conventions

- Node ≥ 22, ESM (`"type": "module"`), pnpm workspace.
- TypeScript `strict` incl. `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`,
  `verbatimModuleSyntax` (`tsconfig.base.json`) — **do not soften.**
- **Money never as `number`** → `big.js` (Money), same half-up rule as PHP
  (away from zero, *no* banker's rounding).
- **Core framework-free:** no web/DB frameworks and no
  DB drivers in `packages/core/**` — eslint `no-restricted-imports` enforces it. Structural counterpart to
  „no `use Illuminate\…` in the core". Adapters become their own packages (from M4 on).
- **Persistence (M4):** via **Knex** as schema/query builder (direct counterpart to
  the PHP side's `illuminate/database`) with **better-sqlite3** as the sqlite driver, **pg**
  for Postgres. The goal is a bit-exact match of the PHP side's `summae_*` schema
  (shared DB, see quality policy in the root `CLAUDE.md`).
- Tests with **vitest**; determinism as required project-wide (injectable
  Clock/IdGenerator, runner uses `FixedClock` + `DeterministicIdGenerator`).
- Mark deliberately unused bindings with a `_` prefix.
- **Pack composition:** resolver `packages/core/src/composition/pack-resolver.ts`; loader (reads the
  shipped `pack-library/`) `runner/src/pack-library.ts`. **Reference** modules/manifests,
  do not duplicate them inline.

## Definition of Green (here)

`pnpm typecheck` + `pnpm lint` clean (counterpart to „PHPStan level max") · `pnpm test`
green **incl. coverage thresholds** (core, `vitest.config.ts`: lines 88 / branches 70 /
funcs 90 / stmts 85 — fixed in the run via `coverage.enabled`) · `pnpm fixtures --strict`
(all fixtures green + byte-identical double run).

## Publish

`@superheld/summae-core` is on npm. Dev exports point to the TS source
(vitest/tsx without a build); `publishConfig` switches to `dist/` when packing.
Release process: `RELEASING.md` (repo root).

## Deeper: `docs/`

- `docs/architektur.md` — packages (`core`/`knex`/`cli`), framework-free core, ports & adapters,
  dispatcher `TenantOperations`, configuration/pack, data flow of a posting (Node-specific).
- `docs/entwicklung.md` — setup (pnpm), what CI checks, conventions, branch/commit workflow,
  „adding a new operation/projection", spec retrofit, determinism hooks.
- `docs/konformitaet.md` — compatibility contract, how the runner works, cross-impl pitfalls,
  cross-test (`make cross`), SPEC-FINDINGS escalation path.
- `SPEC-FINDINGS.md` — documented contradictions spec/fixture/model (`NF-…`).

The **language-neutral build principles** (pack = primarily data/plug, 1:1 mirroring, test-driven,
framework-free) are in the root `CLAUDE.md`; patterns list in `docs/architektur.md`, the recipe
„new operation = service + `case` + fixture" in `docs/entwicklung.md` — here only the Node idioms.
