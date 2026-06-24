# Changelog

Notable changes per release. Loosely based on *Keep a Changelog*,
versioning per SemVer (0.x: minor may break).

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
