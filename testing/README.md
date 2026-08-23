# Where the tests live

Everything test-related that is **not** a unit test lives in this folder:

```
testing/
  README.md      this map
  testsuite/     the conformance contract — MIRRORED, never edit here (§ 2)
  scenarios/     CLI scenarios, language-neutral (§ 3)
    walkthrough/   the handbook in executable form, one per shipped configuration
    regression/    fixed defects, pinned so they cannot come back
```

Unit tests are the one exception, and they stay next to their code (§ 1) — vitest, PHPUnit
and the coverage gates all resolve relative to the package, so a central folder would mean
fighting both toolchains for nothing.

So the short version:

> **Unit tests sit next to the code, per language. Everything cross-language is data, and
> all of that data is under `testing/`.**

The one thing to remember before writing a file here: `testing/testsuite/` is a *mirror* of
the knowledge base and gets overwritten wholesale by `make sync`. New scenarios go in
`testing/scenarios/`; new fixtures are authored in the knowledge base. Details in § 2.

---

## 1. Unit tests — next to the code, one set per language

| | path | run with |
|---|---|---|
| Node | `implementations/node/packages/*/test/**.test.ts`, `implementations/node/runner/test/` | `pnpm test` |
| PHP | `implementations/php/packages/*/tests/**Test.php`, `implementations/php/runner/tests/` | `make test` |

They live beside their code because that is what vitest and PHPUnit expect: the test
suites, the coverage configuration and the IDE tooling all resolve relative to the package.
Moving them into a central folder would mean fighting both toolchains for no gain.

**These are freely editable.** Write them here, in whichever language the change is in — and
per the mirroring rule, write the counterpart in the other language too.

Current inventory (see `git ls-files '*test*'` for the live list):

- **Substrate / value objects** — `MoneyTest` / `money.test.ts` (18 each), `CalendarDateTest` /
  `calendar-date.test.ts`, `CanonicalJsonTest` (11 each), `AccountNumberTest`, `UuidTest`,
  `ValueObjectsTest`, `identity.test.ts`
- **Ledger** — `PostTest` (11), `PeriodsAndAccountsTest` (6), `OpenItemsTest` (5), `LifecycleTest` (4)
- **Projections** — `TrialBalanceTest`, `AuditDataExportTest` / `audit-data-export.test.ts`
- **Contract surface** — `TenantOperationsContractTest` / `tenant-operations-contract.test.ts`:
  every operation and projection named in the API spec must resolve to a handler
- **Parameter contract** — `ProjectionParametersTest` / `projection-parameters.test.ts`: the
  parameter table each core carries as a constant must equal `testsuite/schema/api-parameters.json`
  (the core reads no files, so the copy needs a guard against drift)
- **Architecture guards** — `SubstrateBoundaryTest`, `DeterminismBoundaryTest` (no wall clock or
  RNG in the core), `no-jurisdiction-text.test.ts` (no statute citations in the core)
- **Non-functional** — `NfConcurrencyPerformanceTest` / `nf-concurrency-performance.test.ts`
  (NF-6 sequence integrity, NF-7 10k postings under 10 s)
- **Runner** — comparator, fixture loader/runner, schema validation, placeholder handling
- **CLI** — `CliSmokeTest` / `cli.smoke.test.ts`, plus the scenario runner (§ 3)

## 2. Conformance fixtures — authored in the knowledge base, mirrored here

**The normative cross-language contract.** One canonical expectation per behaviour; every
implementation is checked against it, which is how N languages stay equivalent without N²
comparisons.

- **Authored in:** `../docs/70-testsuite/fixtures/**.json` (the knowledge base, next to the spec)
- **Mirrored to:** `testing/testsuite/` via `make sync` — `rsync --delete`
- **Run with:** `pnpm fixtures --strict` / `make fixtures`

⚠ **Never create or edit anything under `summae/testsuite/`.** The sync deletes whatever is
not in the source, so work done there is lost at the next sync. A new fixture is written in
the knowledge base and then synced.

Also mirrored from the knowledge base: `testing/testsuite/schema/` (from `docs/50-spezifikation/schema/`),
`testing/testsuite/fehlerkatalog.md` (the error catalogue) and `pack-library/` (the shipped packs — these
stay at the knowledge base root, *not* under `docs/`).
Same rule for all of them — the copy here is a mirror, not a source.

The knowledge base is **not under version control**, so there is no undo. Adding a file is
safe; before changing an existing one, drop a copy into `../archiv/`.

`runner/expected-green.txt` (per implementation) lists the fixtures that must stay green —
a regression guard for CI independent of `--strict`. A new fixture goes into **both** lists
(PHP *and* Node), otherwise `ConformanceTest` fails on the language you did not touch.

**Before writing a fixture, read a neighbouring one.** The setup keys are not uniform:
`ruleModule` (singular) and `ruleModules` both work, but `dimensionTypes`/`dimensionValues` sit
directly in `setup` while `dimensionRules` sits in the rule module; the acquisition field is
`acquisitionCost`, not `cost`; `assetRegister` takes `asOf`, not `fiscalYear`. `expect` is a
subset comparison, and a trial balance keeps a row that moved even when its balance is `0.00`.

## 3. CLI scenarios — `testing/scenarios/`, read by both languages

Language-neutral JSON driving the actual CLI. They cover what the fixtures cannot reach: the
binary a user types, argument handling, the workspace files, pack-library loading and the
documented parameter names.

- `testing/scenarios/walkthrough/` — the handbook's CLI walkthrough in executable form, one per
  shipped configuration. **A new pack needs a new scenario**, enforced by a guard test.
- `testing/scenarios/regression/` — fixed defects, pinned so they cannot come back. Adversarial input
  belongs here and nowhere else.

Both are executed by `packages/cli/test/walkthrough.test.ts` (Node) and
`packages/cli/tests/WalkthroughTest.php` (PHP), so they run inside the normal test suites.
Format and conventions: [`scenarios/README.md`](scenarios/README.md).

## 4. Cross-language data test (SF-15) — `make cross`

PHP writes a SQLite dataset, Node reads it and compares `journalExport`, then the same in
reverse. Proves *format* parity, which a shared oracle alone does not: one database, several
engines, one truth.

---

## Which one do I write?

| The change is… | Test it in |
|---|---|
| a value object, a service, an internal rule | unit test, **both** languages (§ 1) |
| a behaviour the data format or API promises | conformance fixture in the knowledge base (§ 2) |
| something only reachable through the CLI | a scenario in `testing/scenarios/` (§ 3) |
| a fixed bug | `testing/scenarios/regression/` (§ 3) — plus a unit test if it has a natural unit |
| a new shipped pack | `testing/scenarios/walkthrough/` (§ 3), or the guard test fails |
| a projection parameter (new, renamed, retyped) | `testsuite/schema/api-parameters.json` in the knowledge base, then both parameter tables (§ 1) |
| persistence or serialisation | the cross test (§ 4) |

## Definition of Green

Per implementation: linter/typecheck, unit tests including the coverage floors (one per
package, just below the measured value, ratcheting upwards), and the conformance suite `--strict` (all fixtures green **and** a byte-identical
double run) against both subjects. Across languages: every capability present in two
implementations passes the cross test. Details per language in
`implementations/<language>/CLAUDE.md`.
