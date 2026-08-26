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

The one thing to remember before writing a file here: `testing/testsuite/` is the normative
cross-language contract, and its fixtures are **append-only** — a behaviour change is a new
fixture, never an edit to an existing one. New scenarios go in `testing/scenarios/`. Details
in § 2.

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

## 2. Conformance fixtures — authored here

**The normative cross-language contract.** One canonical expectation per behaviour; every
implementation is checked against it, which is how N languages stay equivalent without N²
comparisons.

- **Authored in:** `testing/testsuite/fixtures/**.json`
- **Run with:** `pnpm fixtures --strict` / `make fixtures`

Alongside the fixtures live the machine-readable parts of the spec they exercise:
`testing/testsuite/schema/format.schema.json`, `testing/testsuite/schema/api-parameters.json`
and `testing/testsuite/fehlerkatalog.md`. The prose spec is in
[`../knowledge/50-spezifikation/`](../knowledge/50-spezifikation/) and points at these; they
never point back.

> **This used to be a mirror.** Until 2026-08-23 the fixtures were authored in a knowledge
> base outside the repository and copied in by `make sync` (`rsync --delete`), so editing them
> here meant losing the work at the next sync. The knowledge base moved into `knowledge/`, and
> with source and copy in the same repository the copy stopped earning its keep: this tree is
> now the source. Edit fixtures here. `pack-library/` was the last mirror left and followed on
> 2026-08-26; `make sync` and its script are gone.

**The rule that did survive: fixtures are append-only.** A behaviour change is a *new* fixture,
never a quiet edit to an existing one — that discipline was never about the mirror, and losing
`rsync --delete` does not loosen it. Contradiction between spec, fixture and model → do not
guess and do not bend the fixture; record it in the repo-root `SPEC-FINDINGS.md` (the open register;
decided ones move to `SPEC-FINDINGS-RESOLVED.md`) and
build on with the next most plausible behaviour.

**And the one exception, added 2026-08-23: a fixture can be superseded, never edited.** Append-only
protects a fixture from being rewritten; it does not claim a fixture can never have pinned the wrong
thing. `de-pack-resolves` pinned the shipped `de` pack's version *and* its account count — so the
product could neither gain an account nor publish a version without the suite going red. That is not
a contract, that is a weld. A fixture in that position is retired in
`testing/testsuite/superseded.json`: the file stays byte-identical on disk, the register names a
successor and the reason, and both runners skip it. The register is itself gated
(`SupersededFixturesTest` / `superseded-fixtures.test.ts`) — an entry must name a real fixture and a
real successor that is itself expected green, so nothing can quietly disappear.

**The test is whether the expectation was ever a contract**, not whether it is in the way. A fixture
that pins *behaviour* is argued with, never retired. Product data (a pack's version, the size of its
chart) belongs to the product; the mechanism around it belongs in a fixture that brings its own data
and is therefore frozen for good — `xx-6-pack-version-pinning` is the pattern.

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
