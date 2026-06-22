# Development (Node)

## Setup

pnpm workspace, local — **no Docker needed** (Node ≥ 22).

```bash
pnpm install
pnpm typecheck     # tsc --noEmit
pnpm lint          # eslint
pnpm test          # vitest (unit + conformance), measures core coverage + enforces thresholds
pnpm fixtures      # conformance runner (tsx); --strict = double run, byte-identical
pnpm build         # tsup per package (ESM + CJS + .d.ts)
```

`make sync` (repo root) updates `testsuite/` + the `pack-library/` from the knowledge base.

## What must be green (= CI)

- **`pnpm typecheck`** clean (counterpart to "PHPStan level max").
- **`pnpm lint`** clean.
- **`pnpm test`** green (vitest) **including core coverage thresholds** — the run measures core
  coverage (vitest v8 via `coverage.enabled`) and enforces the thresholds in `vitest.config.ts`:
  lines 88 / branches 70 / funcs 90 / stmts 85. Falling below a threshold fails the run.
- **Conformance strict** against both subjects — all fixtures green **and** double run byte-identical:

  ```bash
  pnpm fixtures --strict                     # in-memory core
  pnpm fixtures --strict --subject=database  # Knex adapter (better-sqlite3, local)
  ```

`runner/expected-green.txt` is the regression guard: without `--strict`, nothing listed there may
turn red. **Do not hardcode fixture counts here** — they drift; the current state comes from
`pnpm fixtures`.

## Conventions

- **TypeScript `strict`** incl. `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`,
  `verbatimModuleSyntax` (`tsconfig.base.json`) — **do not soften.**
- **ESM** (`"type": "module"`), Node ≥ 22, pnpm workspace.
- **Money never as `number`** → `big.js` (`Money`), half-up away from zero (commercial, *not*
  banker's). See [konformitaet.md](konformitaet.md).
- **Framework-free core:** no web/DB import in `packages/core/**` — eslint `no-restricted-imports`
  enforces it. Adapters are their own packages (`knex`, `cli`).
- **Booking date zoneless** (`CalendarDate`, no time/UTC shift).
- Deliberately unused bindings prefixed with `_`.
- German-language comments/docs, English API/class names.

## Branch & commit workflow

- **Never directly on `main`/`develop`.** One branch per task (`job/…`, `chore/…`, `fix/…`).
- One commit per completed, green unit; the message names the job/topic ID and what happened
  functionally (not "WIP"). Merge with `--no-ff` when green.

## Adding a new operation / projection

The **recipe** is language-neutral (root `CLAUDE.md`, "Bau-Konventionen"). Concretely in Node:

1. **Understand the target behavior from the fixtures (the executable spec) and the handbook** — the spec is alive.
2. Build the domain logic in `packages/core` (service/aggregate/projection), against the in-memory port + vitest unit tests.
3. Add a `case` to `execute`/`project` in the dispatcher `composition/tenant-operations.ts`
   (one place for CLI + runner).
4. For new persistence needs: port (`port.ts`) + in-memory **and** Knex adapter, extend `schema-installer.ts`.
5. **Mirror in PHP** (same `case`, same check order) — byte parity is a contract.
6. Green: `pnpm typecheck`/`lint`/`test` (incl. coverage thresholds) + `pnpm fixtures --strict`
   (both subjects) **and** `make cross` (SF-15, PHP↔Node byte-identical).

## A spec change comes in (retrofit)

1. `make sync` — fetch new/changed fixtures + schema + `pack-library/`.
2. `pnpm fixtures` — see what turns red (controlled failure, no crash).
3. Re-read the spec files in the knowledge base fresh (not from memory).
4. Adjust until green; on a spec/fixture contradiction → [`../SPEC-FINDINGS.md`](../SPEC-FINDINGS.md),
   **do not bend the fixture**.

## Determinism hooks (important for testing)

`Clock` and `IdGenerator` are injectable (`packages/core/src/substrate/`). The conformance runner
uses `FixedClock` + `DeterministicIdGenerator` (counter instead of random) so the double run is
byte-identical. Production uses `SystemClock` + `UuidV7IdGenerator`. **Never write tests against
`new Date()`/`Math.random()`.**
