# Changelog

Notable changes per release. Loosely based on *Keep a Changelog*,
versioning per SemVer (0.x: minor may break).

## Unreleased

Closing the gate gaps — the requirements that had no test. Two of them turned out to be defects
rather than missing tests, and both were the quiet kind: no crash, no error, just wrong numbers.

### Fixed

- **A pooled asset kept its depreciation when it was disposed of (NF-019).** `runDepreciation`
  skipped every disposed asset. That is right for a single asset and wrong for a pooled one:
  F-AST-006 requires the pool to be written off on its fixed schedule *unaffected by disposals* —
  the jurisdiction behind the rule says the pool is not reduced when an item leaves. The error was
  directional and silent: **too little depreciation and too much profit, for every remaining year
  of the term.** Fixed in both languages; the disposal still books its proceeds. Fixture
  `pool-unaffected-by-disposal`, which also carries the counter-case (a single asset does stop).
- **`supplierTaxationMethod` could never be set (NF-020).** The field is declared in the data
  format (`enum ["accrual","cash"]`), documented against F-TAX-007, and carried by both `Voucher`
  classes — but nothing ever read it out of the input: PHP passed a literal `null`, Node left it
  out. It decides whether input tax is deductible on invoice or only on payment. It is now
  accepted on `createVoucher`/`postVoucher` and validated — an unknown value is `E_INPUT_INVALID`,
  because storing null silently reads as "supplier taxes on accrual", the answer that permits the
  earlier deduction.

- **An asset disposal now writes off the carrying amount (NF-021).** `dispose` booked only
  `bank → proceedsAccount`: the asset account was never relieved, so a disposed asset **stayed in
  the balance sheet at its carrying amount** and the proceeds counted as income in full instead of
  as a gain against book value — profit overstated by exactly the carrying amount. It now books the
  write-off plus the difference to the pack's `disposalProceedsAccount` (gain) or
  `disposalLossAccount` (loss) — two accounts the pack resolver had been *requiring* while nothing
  booked either. Pooled assets stay exempt (see NF-019 above). A fully depreciated asset scrapped
  for nothing books no entry rather than an empty one.

  ⚠ **Known limit (NF-022):** the write-off uses what has been *booked*. The yearly depreciation
  run books on 31 December, so disposing mid-year without running depreciation first writes off a
  stale carrying amount and overstates the loss by the pro-rata share. Run depreciation up to the
  disposal period first. Making `dispose` catch up on its own is a separate decision — it would
  make one operation write two economically different entries.

- **The pool-disposal rule left the core (NF-025).** Fixing NF-019 put § 6 Abs. 2a EStG straight
  into `runDepreciation` as `route !== 'pool'` — „a disposal does not reduce the pool" is not a
  property of pooling but **one jurisdiction's answer**, and the UK and Australia give the
  opposite one. It is now `poolReducedOnDisposal` in the depreciation module, conditionally
  required next to `poolMax` and refused rather than defaulted — the same treatment `poolYears`
  got for the pool period (F-004), one line further down in the same file. Two fixtures drive the
  identical sequence through both answers.
- **The disposal books the depreciation it owes first (NF-022).** Otherwise it wrote off a stale
  carrying amount, and the asset's last months of depreciation never happened at all —
  `runDepreciation` skips disposed assets. The expense landed as an inflated disposal loss instead
  of as depreciation: the income statement total was right, the split was not, and the fixed-asset
  schedule reported too little depreciation. Which months are due follows the schedule's existing
  convention, so no new rule enters the core. Still open by design: whether the month of departure
  counts as a whole month is a pack question.
- **A pooled asset no longer reports a carrying amount of zero (NF-024).** `bookValueAt`
  short-circuited for every route except `capitalize`. Correct for an immediately expensed asset,
  wrong for a pooled one — it sits on the pool account with a real book value, and the fixed-asset
  schedule (F-AST-005) understated the balance sheet it is supposed to explain.
- **An unknown tax mechanism is refused instead of quietly booked as standard.** `mechanismFor`
  fell back to the standard mechanism for any unregistered name. Since the repertoire is closed —
  a pack picks one of the four and carries no code — an unlisted name is a typo or a pack built
  against a newer core, and both booked plain VAT without a word: `reverse-charge` instead of
  `reverse_charge` produced a normal tax line, on the normal account, in the normal VAT return
  box. It is now `E_PACK_INCOHERENT`, and because the resolver calls the same function, a composed
  pack fails at `resolvePack`/`init` rather than at the first posting.

### Added

- **Three fixtures for requirements that were built but unwatched.** `E_POLICY_INVALID` had no
  fixture although the resolver throws it in four places (`resolver-policy-invalid` covers all
  four); the trial balance's `openingBalance`/`debitTotal`/`creditTotal` columns had none although
  both languages emit them (`trial-balance-columns`, over two fiscal years — the only way the
  carry-forward is distinguishable from the period turnover); and F-TAX-007 got
  `supplier-taxation-method`. Plus `resolver-unknown-mechanism` for the hardening above and
  `pool-unaffected-by-disposal` for NF-019/NF-021. 105 fixtures now, cross-test 61/61 each way.

## 0.8.1 — 2026-08-16

Backlog cleanup — no behaviour change, no API change. It ships as a release of its own rather
than waiting, because one of the fixes is only effective once it is on a tag: `branch-alias`
went out wrong with 0.8.0, and the split repos Packagist reads are only updated by the tag
workflow. Nothing about the 0.8.0 *packages* was wrong — Composer takes the version from the
tag — but `dev-main` announced itself as `0.7.x-dev` while 0.8.0 was current.

### Added

- **The Laravel service provider has a test** (`ServiceProviderTest`, on `orchestra/testbench`).
  It was the last file in `packages/laravel` with no coverage, and the only one a Laravel user
  cannot avoid: auto-discovery, `artisan migrate`, `app(DatabaseTenantFactory::class)`. What it
  pins is the part that fails quietly rather than loudly — migrations that never register (a user
  migrates and gets no `summae_*` tables), a factory bound to the default connection while
  `summae.connection` names another, a config that stops being publishable. The package's
  coverage floor rose 95 → 98 with it (measured 99.11).

### Changed

- **The pack version is decoupled from 28 fixtures.** It was expected in 32 fixtures but is the
  actual subject in only four of them, so raising a pack version turned 17 unrelated fixtures red
  — ones about balance sheets, discounts, EÜR. Now only the fixtures that call `resolvePack`
  assert it; everywhere else the expectation is `"pack": { "id": "de" }`. Verified by raising the
  `de` version for real: one fixture red instead of seventeen, and that one is `de-pack-resolves`.
  Nothing about the checked behaviour changed — `expect` is a subset comparison.
- **`branch-alias` is part of releasing.** All three `composer.json` files still read
  `0.7.x-dev` after 0.8.0 shipped; it does not follow the tag and nothing catches it. Corrected
  to `0.8.x-dev`, and `RELEASING.md` now names the step.
- **The testsuite README stopped carrying frozen numbers.** Its status line claimed „58 fixtures,
  38 error codes" long after both had moved; it now points at the tools that know (`make
  fixtures`, `validate.py`) instead of a count that ages.

## 0.8.0 — 2026-08-16

A release about the seams between the pieces rather than about new capability. Nothing here
adds an operation or a report; what changes is that three contracts which had been maintained
by hand are now compared by a test, and that the largest class in the core stopped being one
class.

**Why this is a minor and not a patch:** five error codes that used to exit `1` now exit
49–53. A script that branches on the exit code will see different numbers for the same
failures — a correction, but a visible one. `ExitCodes::all()` / `allExitCodes()` are new;
nothing was removed or renamed.

Both languages stay byte-identical, and every gate (conformance `--strict` against both
subjects, cross-test, PHPStan max, typecheck/lint, coverage floors) is green.

### Fixed

- **Five error codes no longer exit `1` (NF-018).** `E_SETTLEMENT_EXCEEDS_ENTRY`,
  `E_PACK_UNRESOLVED_REF`, `E_PACK_INCOHERENT`, `E_POLICY_INVALID` and
  `E_AMOUNT_SCALE_MISMATCH` were in the error catalogue but not in the CLI's exit-code table, so
  they fell through to `1` — the code that means *unknown error*. A script branching on the exit
  could not tell a bad `summae init --pack …` or an over-claiming settlement from a summae crash;
  the JSON on stderr always named the code correctly. They are **appended** at 49–53, so no
  existing number moves. A new guard test in both languages (`ExitCodesTest`,
  `exit-codes.test.ts`) reads the catalogue and fails when a code in it has no exit code of its
  own — the comparison that was missing.
- **`E_NOT_IMPLEMENTED` reaches the error catalogue.** It was thrown, numbered (44) and
  documented in the handbook, but had no catalogue row, which made it invisible to every
  machine check. The catalogue's line is *everything a caller can rely on* — the reason the
  pure CLI code `E_WORKSPACE_INVALID` is in it — so the row was missing, not withheld. Both
  guards now compare catalogue and exit codes **as sets**, in both directions: 44 codes that
  cover each other exactly. `ExitCodes::all()` / `allExitCodes()` are new (additive) so the
  test can read the mapped list.

### Changed

- **The ledger orchestrator is split.** `Ledger` keeps the operations that write postings
  (`post`, `correct`, `finalize`, `reverse`) plus the line parsing they share, and is now a thin
  facade over `SettlementService`, `ChartAdminService` and `FiscalPeriodService`, with
  `AuditWriter` and `Lookups` carrying what all of them need. The public surface is unchanged —
  `TenantOperations`, the CLI and both persistence adapters see the same object as before.
  879 → 520 lines in Node, 1126 → 671 in PHP.
- **The handbook was brought level with what the code does**, and the status claims in the
  READMEs and CLAUDE files with it — dead job references, stale counts, and two release traps
  are gone. NF-018 was found during exactly that pass.
- **`make sync` refuses to leave a gate file behind.** Three files the gate tests read
  (`api-parameters.json`, `format.schema.json`, `fehlerkatalog.md`) come from the spec folder,
  not from the testsuite folder the mirror is named after. If that source ever fails to
  resolve, the sync used to drop them silently and five tests across both languages would stop
  checking; it now exits non-zero naming the files and where they come from.

### Decided

- **The tax-mechanism repertoire is closed.** New mechanisms are registered inside the core, in
  both languages, with a fixture; a pack selects one per tax code and never carries code. The
  reason is cross-language equivalence: a mechanism plugged in from outside would be different
  code in PHP than in Node, and the shared fixtures could not check it. What would reopen the
  question — the *base computation* becoming its own socket — is recorded in
  `implementations/<language>/packages/core/src/CLAUDE.md`.

## 0.7.0 — 2026-08-16

The release that closes the findings list. Every open item from 0.6.0 — F-004, NF-008,
NF-014, NF-017, NF-015 and the NF-005 remainder — is resolved, together with the twelve-item
backlog two adversarial review passes produced. **100 conformance fixtures green in both
languages** (87 at 0.6.0), byte-identical double run, PHPStan level max, and for the first
time a coverage floor on every package that ships.

Two of the fixes below stop the library from producing quietly wrong output rather than from
crashing, which is the failure shape this project cares about most: the persistence adapters
were handing out other tenants' rows, and a reversal left the invoice it cancelled standing in
the open-item list.

### Fixed — data safety

- **Both persistence adapters ignored `tenant_id` on every by-key path.** `byId`,
  `byOriginEntry` and `save` filtered by primary key alone, so a repository built for tenant A
  returned — and wrote over — tenant B's rows. Identical defect in PHP's `packages/laravel` and
  Node's `packages/knex`, identical fix. Nothing could have caught it before: the conformance
  runner builds one tenant per fixture and the cross test one per database, so an adapter that
  ignores `tenant_id` entirely passes both suites at 100 %. It surfaced the moment the adapters
  got tests of their own (see *Added*), with seven red tests per language on the first run.
- **A reversal left its open items standing** (NF-008). The trial balance showed the receivable
  at `0.00` while `openItems` still reported the same invoice as open — and settleable, so a
  payment could be booked against an invoice that no longer existed. `reverse` now clears them;
  the treatment follows established practice rather than invention, see *Changed*.

### Changed — breaking for callers who relied on lenient behaviour

Four operations that used to accept an instruction and produce an inconsistent result now
refuse it. In each case the ledger and the subledger had drifted apart silently.

- **`reverse` clears the open items of the reversed entry** and **refuses once one of them
  carries a settlement** (`E_ENTRY_HAS_SETTLED_ITEMS`). This is the line SAP draws with message
  F5308: a reversal clears the items it finds, unless one has already been cleared some other
  way. Cancelling a settled item would drop money that actually moved out of the open-item
  history while the ledger kept it; the correction goes through a credit note or refund
  instead. That also answers the NF-005 remainder — "settled, then reversed" can no longer
  occur, so the tax stays declared and the correction is its own cash-effective posting with
  its own date, which is what § 17 Abs. 1 UStG asks for.
- **`correct` refuses to change the *lines* of an entry that produced open items**
  (`E_ENTRY_HAS_OPEN_ITEMS`). The subledger used to keep naming an amount, an account and a due
  date from a posting that no longer existed. Correcting the *text* stays allowed; for amounts
  the GoBD-conform route is reversal and a new posting, which keeps both books together.
- **A settlement cannot claim more than the settling entry actually moves on that account**
  (`E_SETTLEMENT_EXCEEDS_ENTRY`). A partial payment of 500.00 could close an item of 1,190.00
  in full: the ledger then carried a receivable the open-item list no longer knew about, and
  under cash-basis taxation VAT was declared as received that never arrived. The bound is the
  account's *net* movement, so discounts and bad-debt cases stay valid.
- **A pack that opens a pool range without saying how long** is refused
  (`E_PACK_INCOHERENT`) rather than silently inheriting one jurisdiction's period — see *Added*.

### Changed — data format (additive)

- **`openItem.status` gains `cancelled`**, and settlements gain **`cause`** (`payment` |
  `cancellation`, absent means `payment`). `cancelled` and not `settled` on purpose: no money
  arrived. The marker is load-bearing rather than cosmetic — cash-basis VAT follows an item's
  settlements, so without it the reversal of a never-paid 1,190.00 invoice would have declared
  190.00 of VAT out of thin air. `vatReturn` skips cancellation settlements.
- **`$defs/openItem` now declares what the engine has always written** — `remaining`, `status`
  and the settlement `difference` were missing under `additionalProperties: false`. Latent,
  because nothing validated a stored open item against the schema.
- **`$defs/depreciationData`** is a real per-kind schema for `depreciation` modules, and it makes
  `poolYears` conditionally required wherever a `poolMax` is declared.

### Fixed — reports

- **An unmapped account no longer vanishes** (NF-014/NF-017). It used to disappear from
  `incomeStatement` while `balanceSheet` kept counting it, so the two reports disagreed about
  the same money and neither said so; on the balance sheet an unmapped balance account made the
  sheet stop balancing outright. Both now use the `_unassigned` catch-all plus `gapWarnings[]`,
  the treatment the error catalogue already prescribed and `importMapping` already applied.
- **`auditDataExport` starts income accounts at zero** for the fiscal year (R-2). They carried
  their lifetime balance as the opening figure, so a US audit-data export showed revenue
  brought forward into a year it did not belong to.
- **A one-cent invoice is bookable again** (R-11). Tax rounding to `0.00` produced a zero line,
  which the ledger rejects — a valid small invoice failed with a message about invalid amounts.
  Zero tax lines are dropped instead of posted.
- **`journalExport.format` and `costAllocationSheet.fiscalYear`/`period` have an effect.** They
  were declared, accepted and read by nobody.
- **The export manifest states the current format version** (0.6) instead of a hard-coded 0.4,
  guarded against drifting back by a test against the schema `$id`.
- **The pack resolver says which mapping, which position, which selector** when a reference
  goes nowhere, instead of naming only the module.

### Fixed — CLI

- `init` validates before it writes: `--pack` and `--rules` together are refused, the first
  fiscal year must be a plausible year, and a workspace whose creation fails is removed rather
  than left half-built where `init` refuses to run again (R-8/R-10).
- A workspace file with a missing or unusable field says so (`E_WORKSPACE_INVALID`). Every
  field used to fall back to a default and a missing `tenantId` was regenerated, so a damaged
  `summae.json` opened the same database under a different identity and reported empty books —
  indistinguishable from books never written (R-9).
- **An imported mapping outlives the process that imported it** (R-4). `importMapping` only
  touched the in-memory registry, so it answered `imported: true` and the next command behaved
  as though nothing had been imported.

### Added

- **The low-value-asset pool period is pack data** (F-004). `poolYears` sits on the
  depreciation module; `de-afa` declares 5, `us-macrs` `null`. A fixed five years used to be
  compiled into the core — one jurisdiction's rule in the law-free substrate, which every
  other jurisdiction with a pooled regime would have inherited without ever saying so.
- **The persistence adapters have their own test suites** (NF-015), in both languages: a
  round-trip written by one tenant instance and read back by a second one on the same
  connection, so every assertion has genuinely been through a column; the stored JSON checked
  against the aggregate's own serialization; tenant scoping with two tenants on one database;
  the hydrator's defensive branches, where a wrong default drops data instead of crashing.
  `packages/laravel` joins the coverage gate at a 95 % floor, `packages/knex` rises 84 → 88.
- **Four new error codes**, all appended: `E_ENTRY_HAS_OPEN_ITEMS`, `E_ENTRY_HAS_SETTLED_ITEMS`,
  `E_SETTLEMENT_EXCEEDS_ENTRY`, `E_WORKSPACE_INVALID`. No existing code shifted, so exit codes
  stay stable.
- **Thirteen conformance fixtures** (87 → 100), each pinning one of the defects above.
- **`de` and `us` packs move to `2026.2`.** Both had changed content several times while still
  claiming `2026.1` — and a tenant records the pack version it was built from, so an unmoved
  version means the books name a rule set that no longer exists. Modules version independently;
  only the six that actually changed moved.
- **Securities are their own balance-sheet item in the de pack**, per HGB § 266 Abs. 2 (A.III),
  with account 1250; the liquidity position no longer swallows two entire decades of account
  numbers.

### Dependencies

- **`brick/math` 0.13 → 0.18** — the money library was six minors behind, pinned there by
  `illuminate/database ^12`. `illuminate/*` now allows `^11|^12|^13`, which unblocked it.
  `RoundingMode` became an enum in that range, so every call site moved. Both ends of the
  declared range were tested: `--prefer-lowest` (brick 0.14.2 + Laravel 11) and `--latest`
  (0.18 + Laravel 13) produce 100 green fixtures with byte-identical output.
- `Currency` rejects a negative scale, which the stricter `BigDecimal::toScale` signature
  surfaced — it used to travel straight into the decimal library.
- Node: eslint, vitest, tsx, typescript-eslint, knex, `@types/node` current;
  `@types/better-sqlite3` was five majors behind; `better-sqlite3` 12 → 13, `commander` 12 → 15.
- Composer manifests: `branch-alias` still said `0.1.x-dev`, and the sibling constraints were a
  bare `*` that would have accepted any future major of our own core — now `self.version`.

### Findings

**None open.** Two things are deliberately parked rather than found:

- **TypeScript stays on 6.** `tsc`, `vitest` and `tsup` all pass on 7.0, but
  `typescript-eslint` refuses to load against the TS 7 API and lint is part of the green gate.
- **`SummaeServiceProvider` is the one uncovered file** in the Laravel adapter — framework glue
  that needs a booted application to exercise. The coverage floor is set with that hole
  included, so covering it later can only push the floor up.
- Every fixture that creates a tenant from a shipped pack asserts the pack *version*, which
  makes a pack content change a 27-file edit. Pinning it in the two `*-pack-resolves` fixtures
  and asserting only the id elsewhere would make it a two-file edit — a change to the oracle's
  shape, not a fix, so it waits for a decision.

## 0.6.0 — 2026-08-15

A correctness release, and the first one that **rejects input earlier versions accepted**.
Every fix below was found by building tests rather than by a bug report, and every one is
pinned by a fixture or a scenario that fails loudly if it comes back. 88 conformance
fixtures green in both languages, byte-identical double run, PHPStan level max.

### Changed — breaking for callers who relied on lenient behaviour

Until now a parameter that was present but not a valid value was quietly replaced by a
default. That is the worst failure shape a reporting library can have: the answer looks
authoritative and is wrong. All of it now raises `E_INPUT_INVALID` (exit code 45).

- **A numeric parameter must be a whole number.** `{"year": 2026.4}` is a caller mistake,
  not a value to round into shape — whoever meant "year 2026, period 4" has to say which.
  `2026` and `2026.0` remain the same number: JSON draws no int/float distinction that
  survives parsing, so a rule separating them could not be implemented identically in both
  languages, which is the whole point.
- **An undeclared parameter is rejected**, not ignored. A misspelled `fiscalYear` on
  `vatReturn` used to return a plausible **annual** figure where a quarter was asked for;
  `includeZeroBalance` (singular) was a flag that did nothing.
- **A required parameter must be present.** `trialBalance` without `fiscalYear` returned
  `{"rows":[]}` — the same shape empty books produce.
- `createFiscalYear` requires a positive whole year; a quoted `"2027"` used to create year
  0, addressable by nothing, so every later report for 2027 came back empty and plausible.
- `correct` must say what it changes: an unrecognized field (`txt` for `text`) was a silent
  no-op that still returned a **success** payload for a correction that never happened.
- `openItems`/`datevExport` reject an unknown `kind` instead of falling back. The first
  widened a filter instead of narrowing it — a payment run asking for payables got
  receivables mixed in.

Absent still means absent throughout: an optional parameter that is missing keeps its
documented default. Only *present-and-wrong* is an error.

### Fixed — cross-language divergences

The shared oracle compares the error code and, until this release, nothing else. These
slipped through 87 green fixtures in both languages simultaneously.

- **The same JSON produced different reports.** `{"year": 2026.0, "quarter": 2.0}` — what a
  serializer writes once a value has passed through a float type — gave Node a correct VAT
  return and PHP an empty one, because every numeric parameter was read as
  `typeof x === 'number'` there and `is_int()` here. 18 read sites per language, now one
  check at the dispatcher. `throughPeriod` was worse than empty: Node limited the report to
  the period asked for, PHP fell back to no limit, so the two printed different numbers
  under the same heading.
- **A fiscal year of `1e21` was created by Node and rejected by PHP** (`Number.isInteger`
  accepts it, PHP's int does not reach that far). Both now bound at 2^53-1.
- **Error `details` rendered differently per language** — `true` as `"1"` against `"true"`,
  an object as `null` against `"[object Object]"`. Both now echo back only strings and safe
  integers, the same line canonical JSON already draws, and drop the rest rather than
  guessing at it.
- **NF-009** `CalendarDate` disagreed on years 0000–0099 (host `Date` remaps two-digit
  years); the substrate no longer touches the host date type at all.
- **NF-010** `Money.of` enforces the data format instead of accepting whatever the decimal
  library would parse.
- **NF-011** a forged `taxTag` naming an unknown tax code was posted without complaint.
- **NF-012** `balanceSheet` ignored `fiscalYear` and always reported everything.
- **NF-013** any `direction` other than exactly `"input"` fell through to `"output"` and
  posted the mirror image — expense credited, liability debited.

### Fixed — model

- **NF-005** cash-basis VAT: reversing an entry whose open items are still outstanding is not
  a cash movement. An unpaid, cancelled invoice used to yield an input-tax refund for money
  that never moved.
- **NF-006 / NF-007** `cashBasisReport` without `year` raised an uncaught `InvalidValue`
  (breaking the CLI's JSON contract), and a missing mapping reported `E_MAPPING_OVERLAP` — a
  code stating the opposite of what happened. Both are `E_INPUT_INVALID` now.
- Both CLIs have a JSON error boundary: an unexpected exception leaves as
  `{"error":"E_UNEXPECTED",…}` instead of a stack trace on stdout.

### Added

- **`E_INPUT_INVALID`** (exit 45) — the caller-error code the cases above needed. Appended to
  the catalogue; no existing code shifted.
- **The projection parameter contract as data** — `testing/testsuite/schema/api-parameters.json`
  declares 39 parameters over 14 projections with their types. The core reads no files by
  design, so each language carries the table as a constant and a test per language asserts
  the constant equals the file: drift is mechanically impossible rather than reviewed for.
- **Per-package coverage floors** in both languages, replacing a single floor over the domain
  core while four other packages went unmeasured. Floors ratchet upward only.
- **One home for tests**: `testing/{testsuite,scenarios}` plus `testing/README.md`, which
  answers where each kind of test lives and which kind to write for a given change.
- Two conformance fixtures (`input-invalid`, `parameter-contract`) and two regression
  scenarios covering the input-validation and reversal defects.

### Findings (recorded, deliberately not fixed)

- **NF-014** an account outside a mapping's ranges vanishes from `incomeStatement` while
  `balanceSheet` still counts it. `gapWarnings[]` and the `_unassigned` catch-all exist at
  mapping *import* and are missing in the projections themselves. Next in line.
- **NF-015** `packages/laravel` has no tests of its own and is excluded from the coverage
  gate; it is reached only end-to-end. A green `make test` proves nothing about it.
- Three parameters are accepted and read by nobody (`journalExport.format`,
  `balanceSheet.incomeMapping`, `costAllocationSheet.fiscalYear/period`). Declared as
  `acceptedWithoutEffect` so the gap is visible instead of hiding behind a tolerant reader.
- The **NF-005 remainder**: an item settled and *then* reversed still needs a spec decision —
  leave the tax declared until a refund, or correct it at the reversal date.

## 0.5.1 — 2026-08-15

Documentation release: no API change, no behaviour change. The user documentation gained a
task-oriented half and — more importantly — stopped being able to rot: the walkthrough now runs
in both implementations' green gates, one scenario per shipped configuration.

### Added — CLI walkthrough (`docs/handbuch/cli-walkthrough.md`)
- Task-first companion to the handbook reference: empty directory to closed fiscal year —
  workspace and pack choice, outgoing invoice with tax expansion, payment and settlement,
  reversal, every report shape, `finalize`/`closePeriod`/`closeFiscalYear`, the three exports,
  error handling with exit codes, and a parameter cheat sheet. Every output in it is real CLI
  output. Written for developers **and** for AI agents driving the CLI, which is the surface
  with the smallest automation footprint (three commands, JSON in, JSON out).
- A copy-pasteable companion script, `docs/handbuch/examples/cli-walkthrough.sh`.

### Added — the documentation is gated
- **Walkthrough scenarios** (`docs/handbuch/examples/scenarios/*.json`, moved to `testing/scenarios/` after 0.5.1): one complete lifecycle
  per configuration we ship — `de`, `us`, `default`, and a free `rules.json` — including the
  error paths (unbalanced, already reversed, period out of order, locked account, closed period,
  settlement exceeding the item) with their exit codes.
- Both implementations read the **same** scenario files and pin the **same** expectations
  (`walkthrough.test.ts` / `WalkthroughTest.php`) — the shared-oracle mechanism applied to the
  CLI. Covers what the conformance fixtures cannot reach: the CLI surface, the workspace, the
  pack library, the documented parameter names.
- Two guards: every shipped pack must have a scenario, and every operation the `de` scenario
  pins must appear in the example script. **Shipping a pack now means shipping a scenario.**

### Changed
- Handbook caught up to 0.5.0: `auditDataExport` (AICPA ADS, three GL streams, signed amounts),
  the four tax mechanisms as a table with the rationale for `exempt`, the `us` pack, pack-format
  schema validation, and a warning that period parameters are not uniform across projections.
- `createVoucher` documented for the first time (§ 6.2) — the operation a plain `post` needs.
- CI actions bumped off the deprecated Node 20 runtime (checkout v7, setup-node v7,
  pnpm/action-setup v6); workflow step names and comments translated to English.

### Findings (documented, deliberately not fixed)
- **NF-005** — cash-basis VAT: reversing an *unsettled* open item counts immediately while the
  original still waits for a payment that will never come, so an unpaid-then-cancelled invoice
  yields an input-tax refund. Identical in PHP and Node, so a model question, not a parity
  defect; the accrual path has an explicit rule (F-011), the cash path has no counterpart.
- **NF-006** — `cashBasisReport` without `year` raises an uncaught `InvalidValue` instead of a
  `DomainError`, breaking the CLI's own JSON-output contract. The trigger is realistic: every
  other projection except `vatReturn` takes `fiscalYear`.
- **NF-007** — a missing or unknown mapping reports `E_MAPPING_OVERLAP`, a code that says the
  opposite of what happened. Current behaviour pinned in `default.json` so a fix is deliberate.

Each needs a spec decision (or an append to the error catalogue) before either language moves.

## 0.5.0 — 2026-06-24

US reach and a hardened core. A new US export (AICPA Audit Data Standard), an `exempt`
tax mechanism, the tax-mechanism socket realized as a registry, pack-format schema
validation, and a battery of structural guards — all green: PHP + Node `--strict`
(core **and** database subjects), byte-identical double run, SF-15 cross-test both directions.

### Added — US export (`auditDataExport`)
- **AICPA Audit Data Standard (General Ledger)** export — the US counterpart to
  `journalExport` (GoBD-Z3) and `datevExport` (DATEV), both German. The US has no statutory
  GL export format; the ADS is the voluntary standard a US auditor expects. Three streams
  (journals/GLDetail, trialBalance/GLAccountBalance, accounts/chart) with the standard's JSON
  field names; **signed** amounts (debit +, credit −). New requirement F-IO-009, conformance
  fixture, both languages 1:1.

### Added — `exempt` tax mechanism
- A tax-exempt sale is now postable. A plain rate-0 *standard* code expands to a 0.00 tax
  line the ledger rejects; the new `exempt` mechanism emits **no** tax line (tax-free, base
  tagged), so it posts cleanly. The us-pack `EXEMPT` code is wired to it. Resolves NF-004/F-010.

### Changed — tax-mechanism socket → registry (internal, byte-identical)
- The inline tax-mechanism switch in `TaxService` (`reverse_charge` / `intra_community_supply`
  / standard) is now an **addressable registry** of strategy objects in the policy layer — the
  "socket" the architecture calls for. The three projection/resolver sites that hard-coded
  mechanism *names* now query mechanism *metadata*. **No behavior change** (byte-identical,
  conformance + cross-test unchanged). A new mechanism (like `exempt`) is a registered strategy,
  not an edit scattered across sites.

### Added — pack-format schema validation
- Every shipped pack-library module + manifest is validated against `format.schema.json` in
  both languages (Node ajv / PHP opis); the `mapping` and `policy` kinds deeply against their
  `$defs`. The Node runner now also validates journalExport streams (parity with PHP).

### Added — structural guards & contract tests
- Determinism guard (no wall-clock/RNG in the core outside the injected Clock/Id seam),
  no-statute-citation guard, a `TenantOperations` contract test (every API operation/projection
  resolves; unknown → the defined error; identical surface PHP↔Node), and dedicated NF-6
  (sequence integrity) / NF-7 (performance) tests.

### Changed — core comments de-jurisdiction'd
- Statute citations (§ N UStG/EStG/HGB) and German abbreviations removed from the law-free
  core's comments; mechanism identifiers and real feature/format names (DATEV, GoBD-Z3) kept.

### Notes
- **journalExport stays German** (GoBD-Z3 is a German standard; its field descriptions serve a
  German auditor) — the dropped "translate to English" idea became the US export above instead.
- **Deferred** (does not block the green build): the `ledger.ts` orchestrator split (a
  taste/structure decision), per-kind schemas for the remaining pack kinds, and the US
  account-number sign-off.

## 0.4.0 — 2026-06-24

The **us-pack** (United States) — the second complete jurisdiction pack and the first real
paradigm beside Germany — plus a substrate cleanup that pulls the last jurisdiction text out of
the law-free core. Green throughout: PHP + Node `--strict`, core **and** database subject,
byte-identical double run, coverage ~90% both.

### Added — `us` pack (`createTenant(pack: "us")`)
- **Own US chart** (35 accounts, English) in the **common US small-business numbering**
  (1xxx assets · 2xxx liabilities · 3xxx equity · 4xxx revenue · 5xxx COGS · 6xxx expenses) —
  US-GAAP prescribes no statutory chart, so this follows the layout US users expect (distinct from
  the de-pack's class scheme; the two packs are self-contained and share no accounts).
- **Sales & use tax** (`us-salestax`): `SALETAX` (single-stage retail sales tax, no input-tax
  credit), `USETAX` (self-assessed use tax → cost + liability), `EXEMPT` (resale/interstate, rate 0).
- **US-GAAP mappings**: Classified Balance Sheet (by liquidity), Multi-Step Income Statement (by
  function), cash-basis **Schedule C**.
- **MACRS / de-minimis** depreciation (immediate expense ≤ 2,500 USD, no pool) + asset accounts.
- **US policy**: USD, half-up per voucher, scale 2; defaults `accrual` (GAAP) / quarterly.
- **11 conformance fixtures** (resolve, sales tax, use tax, exempt sale, balance/income,
  depreciation, end-to-end fiscal year, **sales-tax return**, **Schedule C cash-basis**,
  **contra-revenue**, **economic nexus / Wayfair**) + a `summae init --pack us` CLI smoke.

### Added — `de` pack
- **EÜR mapping** (`de-euer`, Anlage EÜR §4 Abs. 3 EStG) — the cash-basis profit/loss as a
  projection, the symmetric counterpart to the us-pack's Schedule C (the de manifest gains an
  8th module). Plus a **VSt7** (reduced input tax) conformance fixture.

### Changed — cash-basis tax labels are now pack-driven (core cleanup)
- The cash-basis projection no longer hard-codes German VAT strings (`Vereinnahmte USt` …) or the
  "VAT flows through" treatment in the law-free core. A tax account flows through the cash-basis
  result only where the pack's mapping maps it (label from the mapping leaf); unmapped tax is a
  neutral pass-through. **Behavior note:** running `cashBasisReport` on a de tenant now requires
  passing the `de-euer` mapping to get the VAT lines (previously hard-coded). Resolves NF-003/F-009.

### Quality gate
- **Contract-validation obligation** + **tests-ship-with-the-pack obligation** recorded in
  `CLAUDE.md` / `pack-library/CLAUDE.md`: behavioral fixture coverage isn't enough — contract
  surfaces (data/pack format, the API dispatcher, NF-6/NF-7) each need a guard, and every legally
  expected pack capability ships with its fixture.
- **Structural guard added**: "no hard-coded jurisdiction label text in the core" (PHP
  `SubstrateBoundaryTest` + Node `no-jurisdiction-text` test) — the regression guard for the
  class of bug the cash-basis labels were.

### Schema & docs
- `format.schema.json` `$defs/mappingPosition` now declares `includeNonCash` (NF-002/F-008).
- **Handbook**: documents Node DB persistence (the Knex adapter), parallel to the PHP Laravel
  adapter; stale `Summae\Core\Shared\` namespace fixed.

### Notes
- **Sign-off pending** (does not block the green build): the US account numbers, use-tax naming,
  default taxation method, multi-state strategy — see internal `99-pack-docs/us-pack/`.
- **Open engine items** (documented in both `SPEC-FINDINGS`): `EXEMPT` cannot be posted yet (its
  0.00 tax line is rejected — NF-004/F-010, argues for an `exempt` mechanism).

## 0.3.2 — 2026-06-23

Docs/comments only — **no API/behavior change** (conformance + SF-15 cross-test green, byte parity unchanged).

### Internationalization (English everywhere)
- All **code comments, docblocks, and exception messages** translated to English (PHP + Node, mirrored 1:1).
- All **CLAUDE files**, **package descriptions** (`package.json`/`composer.json`), the **CHANGELOG**,
  **RELEASING**, every **README** (packages, runtimes, pack library), both **SPEC-FINDINGS**, and the
  residual German in the **handbook** are now English. The working language in chat stays German; the
  `EÜR` abbreviation and the German chart-of-accounts data are kept as-is.
- **Self-contained repo:** references to the internal knowledge base (numbered paths) removed from
  tracked docs — the repo now stands on its own; the contract is the fixtures + schema.

### Drift fixed (caught during translation)
- `pack-library/README.md` described a non-existent shared `modules/` layout → corrected to the actual
  self-contained pack structure.
- The handbook documented a stale default reversal text `"Storno <seqNo>"` → corrected to
  `"Reversal <seqNo>"` (the actual code default).
- Package READMEs used the pre-0.3.1 `Summae\Core\Shared\` namespace → updated to `Substrate\`.

## 0.3.1 — 2026-06-23

Internal + docs — **no API/behavior change** (byte parity unchanged, still proven).

### Internal / maintainability
- **`core/src` structured along the architecture**: `substrate/` (substrate) · `ledger/`
  (orchestrator) · `records/` · `policies/{expansion,projection,constraint}/` ·
  `composition/` · `partner/` · ports/adapters. The substrate boundary („imports nothing
  from above") is **mechanically enforced** (Node eslint, PHP arch test).
- **Test coverage** as a metric + floor (core lines ≥ 88 %), **fixed in the test run** of both
  languages. PHP now runs the full conformance suite under PHPUnit too
  (`ConformanceTest`), so it counts toward coverage (pcov in the image).

### Docs
- User **handbook** and **developer docs** of both languages **in English** and brought up to
  date: architecture model **substrate → policy kinds (socket/plug) →
  pack**, dependency inversion (the core never imports a pack), the implemented
  directory structure. Hardcoded fixture counts removed.

## 0.3.0 — 2026-06-22

### Packs (cross-language, byte parity PHP↔Node)
- **New: pack composition.** A `PackResolver` (pure function) resolves a manifest +
  its modules into *one* `ruleModules` bundle that the engine eats. Tenant by
  pack choice, **once at creation, pinned, no override** — `createTenant(pack: "…")`.
- **New: shipped pack library** (`pack-library/`) with a content-based loader.
  Packs are **self-contained** — each holds its own modules (`pack-library/<pack>/`),
  no shared `modules/`, no building on each other.
- **New: `default-pack`** (neutral, account-sparse frame) and **`de-pack`** (Germany):
  own chart of accounts, VAT 19/7 · §13b reverse charge · intra-community supply · deemed
  supply · cash discount, balance sheet (§266) / income statement (§275), depreciation/low-value
  assets, accruals/deferrals, policy. Fully conformance-tested incl. end-to-end yearly cycle and VAT return.
- **`packPolicy`** parametrizes the engine jurisdiction-free: `currencyScale` → `Currency`,
  `taxRoundingGranularity` → `TaxService`.
- **New: `createVoucher` operation** — create a voucher without posting (attachment point e.g. for depreciation).

### CLI
- `summae init --pack <id>` selects a pack from the library (`--pack-library`,
  `--first-fiscal-year`) — pack choice from the frontend.

### Docs
- Language-neutral model **core/substrate → policy kinds → pack** with a clear
  `kind`→policy-kind mapping and a „write a pack by hand" guide; build conventions
  and quality gate in the CLAUDE files; Node `docs/` brought in line.

### CI
- Split-workflow token fix (subtree split runs turnkey via the workflow).

## 0.2.0 — 2026-06-20

### Node (M4)
- **New: `@superheld/summae-knex`** — database adapter (Knex as schema/query builder
  + better-sqlite3 / pg). Matches the shared `summae_*` schema of the PHP reference, so
  PHP and Node packages can **share the same data set**.
- **New: `@superheld/summae-cli`** — terminal tool (`summae init|op|report`),
  JSON input/output, persistent SQLite workspace.
- `@superheld/summae-core`: `Tenant.fromPorts` (tenant from arbitrary ports) +
  `restore` methods for FiscalYear/OpenItem/Asset.

### Cross-language
- **SF-15 cross-test (bidirectional)**: PHP↔Node on shared SQLite; `journalExport`
  **byte-identical in both directions** (`make cross`, enforced in CI).
- **F-CROSS-001 solved**: canonical timestamp format (UTC, RFC 3339, fixed
  milliseconds, `Z`) across all implementations.
- CI now covers **PHP + Node + cross-test** (previously PHP only).

### PHP
- **Breaking** (`superheld/summae-laravel`): adapter classes `Eloquent*` → `Database*`
  (named by role; they never used the Eloquent ORM, only the
  `illuminate/database` query builder). Runner subject `eloquent` → `database`.
- Timestamps in the canonical format (F-CROSS-001).

## 0.1.0 — 2026-06-18

- First public release. PHP reference (`superheld/summae-{core,laravel,cli}`)
  on Packagist + `@superheld/summae-core` (Node) on npm. 45/45 conformance fixtures,
  central handbook (`docs/handbuch`).
