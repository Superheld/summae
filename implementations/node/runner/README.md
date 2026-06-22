# @superheld/summae-runner (Node)

Conformance fixture runner: runs the shared test suite (`testsuite/` in the
repo root) against a **subject** and checks it against the runner contract
(`testsuite/README.md`).

**Status:** all 45 fixtures green against the `CoreSubject` (@superheld/summae-core,
in-memory ports), double run byte-deterministic.

## Commands

```bash
pnpm fixtures                 # whole suite, report (PASS/FAIL/CRASH)
pnpm fixtures -- --strict     # exit ≠ 0 if not everything is green / determinism breaks
pnpm fixtures -- --filter=vat # only fixtures whose name contains the substring
pnpm test                     # vitest — incl. conformance.test.ts (see below)
```

(`pnpm fixtures` runs via `tsx runner/bin/run-fixtures.ts`.)

## Building blocks

- **`Subject`** (`src/subject.ts`) — the object under test: `setup` / `execute(op, input)` /
  `project(name, params)`. Domain errors are thrown as `SubjectError` with the exact
  `E_*` code; anything else counts as a crash.
- **`CoreSubject`** (`src/subject/core-subject.ts`) — subject over `@superheld/summae-core`:
  builds the tenant from the `setup` block, routes via `TenantOperations`,
  translates `DomainError → SubjectError`. A new runtime/binding implements
  only this interface.
- **`FixtureRunner`** — a single fixture: `setup → steps → projections`.
- **`SuiteRunner`** — whole suite + double-run determinism (UUID normalization
  to occurrence index).
- **`Comparator` / `PlaceholderBag`** — subset comparison and `$V1`/`$E1` mechanics.

## Regression guard

`runner/expected-green.txt` lists the fixtures currently expected green;
`runner/test/conformance.test.ts` runs the suite and pins this list
(plus double-run determinism) as part of `pnpm test`.
