# Changelog

Notable changes per release. Loosely based on *Keep a Changelog*,
versioning per SemVer (0.x: minor may break).

## 0.4.0 — unreleased

The **us-pack** (United States) — the second complete jurisdiction pack and the first real
paradigm beside Germany. **Pack data only — no substrate/engine change**; the differences are
purely in the domain logic, proven by the conformance suite (PHP + Node `--strict`, core +
database subject, byte-identical double run).

### Added — `us` pack (`createTenant(pack: "us")`)
- **Own US chart** (35 accounts, English) in the **common US small-business numbering**
  (1xxx assets · 2xxx liabilities · 3xxx equity · 4xxx revenue · 5xxx COGS · 6xxx expenses) —
  US-GAAP prescribes no statutory chart, so this follows the conventional layout US users expect
  (distinct from the de-pack's class scheme; the two packs are self-contained and share no accounts).
- **Sales & use tax** (`us-salestax`): `SALETAX` (single-stage retail sales tax, no input-tax
  credit), `USETAX` (self-assessed use tax wired via `reverse_charge` but onto an **expense** leg
  → cost + liability, not net zero), `EXEMPT` (resale/interstate/nontaxable, rate 0).
- **US-GAAP mappings**: Classified Balance Sheet (assets by liquidity), Multi-Step Income
  Statement (by function), and a cash-basis **Schedule C** mapping.
- **MACRS / de-minimis** depreciation (immediate expense ≤ 2,500 USD, no pool; GDS recovery
  periods as useful lives) + asset movement accounts.
- **US policy**: USD, half-up per voucher, scale 2; defaults `accrual` (GAAP) / quarterly.
- **7 conformance fixtures** under `testsuite/fixtures/pack/us-pack/` (resolve, sales tax, use
  tax, exempt sale, balance/income, depreciation, end-to-end fiscal year).

### Notes
- **Schema gap recorded** (NF-002 / F-008): `format.schema.json` `mappingPosition` lacks the
  `includeNonCash` flag that the engine reads and the Schedule-C mapping needs; not breaking
  (pack JSON is loaded content-based, never schema-validated), proposal in both `SPEC-FINDINGS`.
- **Sign-off pending** (does not block the green build): the eight US account numbers, use-tax
  naming, default taxation method, multi-state strategy — see
  `99-pack-docs/us-pack/offene-entscheidungen.md` (internal).

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
