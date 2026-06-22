# Changelog

Notable changes per release. Loosely based on *Keep a Changelog*,
versioning per SemVer (0.x: minor may break).

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
