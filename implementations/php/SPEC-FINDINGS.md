# SPEC-FINDINGS

Findings from the implementation: places where spec, fixtures and model
contradict each other or where something is missing. Rule from the briefing: **do not
guess, do not change the fixture** — document it here and keep building with the
next most plausible behavior.

> **✅ All findings F-001 to F-007 resolved in spec v0.5** (2026-06-08,
> decision log + `SPEC-UPDATE-v0.5.md`) and implemented in JOB-V05:
> - F-001 → dedicated code `E_VOUCHER_UNKNOWN`
> - F-002 → `E_ENTRY_NOT_FINALIZED` removed, `reverse` status-independent (my workaround was correct)
> - F-003 → dedicated code `E_FISCALYEAR_UNFINALIZED_ENTRIES`
> - F-004 → rule-module block `assetAccounts` (name heuristic removed)
> - F-005 → manifest required fields `streams`/`hashAlgorithm`, `auditLog` always, `formatVersion` current
> - F-006 → dedicated code `E_COSTING_RUN_UNKNOWN` (already matched my choice)
> - F-007 → `side: assets|liabilitiesAndEquity` on the balance-sheet root node
>
> The detail entries below remain as history.

## Status at a glance

Re-verified against the code on 2026-08-15 — the per-finding headings below now carry their
status, so scanning the list no longer suggests open work that is long done. Resolved entries
keep their original text under the resolution note: why a decision was made is worth more than
a short file.

| Finding | Status |
|---|---|
| F-001 unknown `voucherId` | ✅ `E_VOUCHER_UNKNOWN` + `core/voucher-unknown.json` |
| F-002 `E_ENTRY_NOT_FINALIZED` in api.md, not in the catalogue | ✅ code dropped from the spec, `reverse` is status-independent |
| F-003 fiscal-year close with unfinalized postings | ✅ `E_FISCALYEAR_UNFINALIZED_ENTRIES` + `core/fiscalyear-close-guard.json` |
| F-004 asset posting accounts | ⚠ **partly** — accounts came via the `assetAccounts` pack module; the **pool period is still hard-coded** |
| F-005 journalExport manifest streams | ✅ `formatVersion 0.4`, `auditLog` always included |
| F-006 `E_COSTING_RUN_UNKNOWN` | ✅ code + `costing/costing-run-unknown.json` |
| F-007 balanceSheet side assignment | ✅ explicit `side` in the mapping schema and in both projections |
| F-008 `includeNonCash` missing from the schema | ✅ schema extended |
| F-009 `cashBasisReport` German VAT passthrough | ✅ resolved |
| F-010 `EXEMPT` cannot be posted | ✅ `exempt` mechanism (0.5.0) |
| F-CROSS-001 timestamp serialization | ✅ resolved |
| NF-005 cash-basis reversal | ✅ fixed 2026-08-15 — **remainder open**: settled-then-reversed |
| NF-006 `cashBasisReport` without `year` crashes | **OPEN** — needs an error code for a missing parameter |
| NF-007 missing mapping reports `E_MAPPING_OVERLAP` | **OPEN** — needs a catalogue append |
| NF-008 reversal leaves open items standing | **OPEN** — needs a data-format decision |
| NF-009 `CalendarDate` years 0000–0099 diverged PHP vs. Node | ✅ fixed 2026-08-15 — Node no longer uses the host `Date` |
| NF-010 `Money.of` accepted amounts the data format forbids | ✅ fixed 2026-08-15 — `1.5e+21` was bookable; `+10.00` also diverged |

**Genuinely open today: F-004 (pool period), NF-006, NF-007, NF-008, and the NF-005 remainder.**

Format per finding:

```
## F-XXX: short title
- **Job:** JOB-NNN
- **What:** description of the contradiction / gap
- **Where:** file(s) + section in spec/fixture/model
- **Chosen behavior:** what the implementation does now
- **Proposal:** recommendation for spec v0.3
```

---

## F-001: No error code for unknown voucherId — ✅ RESOLVED

> **Resolved.** The proposed dedicated code was introduced: `E_VOUCHER_UNKNOWN` is in the
> error catalogue and in the exit-code table (`ExitCodes.php` / `exit-codes.ts`), and
> `testsuite/fixtures/core/voucher-unknown.json` pins it. Original finding:

- **Job:** JOB-003
- **What:** `E_ENTRY_NO_VOUCHER` is defined as "voucherId missing". For a
  *set but unknown* voucherId no code exists; no fixture covers the case.
- **Where:** fehlerkatalog.md (E_ENTRY), api.md (post)
- **Chosen behavior:** an unknown voucherId is also reported as
  `E_ENTRY_NO_VOUCHER` (reference check step 2, before accounts).
- **Proposal:** either pin it down explicitly that way or introduce a dedicated code
  `E_VOUCHER_UNKNOWN` + fixture.

## F-002: E_ENTRY_NOT_FINALIZED in api.md, but not in the error catalog — ✅ RESOLVED

> **Resolved in spec v0.5.** The code was dropped from the spec rather than added to the
> catalogue: `reverse` is status-independent, which is what the implementation already did.
> Original finding:

- **Job:** JOB-003
- **What:** api.md lists `E_ENTRY_NOT_FINALIZED`* for `reverse` (with footnote
  "decision open question 5"); the error catalog (29 codes, all with
  fixtures) does not know it. Fixture finalize-reverse-period reverses a
  *non*-finalized reversal posting successfully.
- **Where:** api.md (ledger table) vs. fehlerkatalog.md vs. finalize-reverse-period.json (Step 9)
- **Chosen behavior:** `reverse` is permitted independent of status (follows
  fixture + catalog).
- **Proposal:** resolve the footnote in api.md — remove the line from the error
  column or define the behavior for `entered` explicitly.

## F-004: Account resolution for asset postings not specified — ⚠ PARTLY RESOLVED

> **Accounts: resolved.** The proposed keys became a pack module of their own — `kind:
> assetAccounts` (`pack-library/de-pack/assets/`, `pack-library/us-pack/assets/`) supplies the
> acquisition counter account, the depreciation expense account and the low-value-asset
> account as pack data, not as a name-matching fallback.
>
> **Still open — the low-value-asset *pool period* is hard-coded.** `asset-service.ts:70-72`
> (`AssetService.php` likewise) writes off a pooled asset over a **fixed 5 years at 1/5 each**,
> tagged `FINDING:` in the source. Five years is German law (§ 6 Abs. 2a EStG,
> GWG-Sammelposten) sitting in the expansion code — the litmus test from the root `CLAUDE.md`
> says a rule that cites a statute belongs in the pack as data. The statute-citation guard did
> not catch it because the source names no paragraph. Needs a field on the depreciation module
> (knowledge base: schema + fixture) before the code can read it from the pack.
>
> No pack is *currently* mis-served: the pool route only fires when a threshold declares both
> `poolMin` and `poolMax`, and only `de-pack` does (`250.01`–`1000.00`); `us-pack` has both
> `null`, so it never takes the route. The gap is expressive, not yet a wrong number — the pack
> can switch the pool on and off but cannot say **over how long**, so any future jurisdiction
> with a pooled de-minimis rule would inherit Germany's five years.
>
> Original finding:

- **Job:** JOB-009
- **What:** acquireAsset/runDepreciation generate postings, but neither spec
  nor rule-module data name the counter account (cash account), depreciation
  expense account or low-value-asset immediate-write-off account. The fixtures
  expect 1200/4830/4855.
- **Where:** assets-modell.md, api.md (Assets), gwg-and-depreciation.json
- **Chosen behavior:** rule-module keys `acquisitionCounterAccount`/
  `depreciationExpenseAccount`/`gwgExpenseAccount`; fallback convention:
  the single bank account, expense account by name part ("AfA"/"GWG").
- **Proposal:** add the keys to the rule-module spec; add fixtures.

## F-005: journal-export-z3 vs. audit-trail — manifest streams contradict each other — ✅ RESOLVED

> **Resolved.** The contradiction is gone: `testsuite/fixtures/io/journal-export-z3.json` now
> expects `formatVersion "0.4"` and `streams: [journal, accounts, vouchers, auditLog]` — the
> audit trail is always part of the export — and the schema declares `streams`/`hashAlgorithm`.
> Original finding:

- **Job:** JOB-011
- **What:** journal-export-z3 expects exactly [journal, accounts, vouchers]
  (even though post/finalize generate audit entries), audit-trail (v0.3) exactly
  [..., auditLog]. In addition journal-export-z3 expects formatVersion "0.2"
  (spec is v0.4), and the schema manifest does not know `streams`/`hashAlgorithm`,
  which the fixture requires.
- **Where:** journal-export-z3.json, audit-trail.json, schema/format.schema.json
- **Chosen behavior:** auditLog stream only on a real change history
  (actions beyond created/finalized); formatVersion fixed at "0.2";
  manifest validation limited to schema-known fields.
- **Proposal:** re-cut journal-export-z3 as a v0.4 fixture
  (auditLog always, formatVersion current), extend the schema manifest with
  streams/hashAlgorithm.

## F-006: E_COSTING_RUN_UNKNOWN missing from the catalog — ✅ RESOLVED

> **Resolved.** The proposed code was added: `E_COSTING_RUN_UNKNOWN` is in the catalogue and
> the exit-code table, pinned by `testsuite/fixtures/costing/costing-run-unknown.json`.
> Original finding:

- **Job:** JOB-010
- **What:** releaseCosting/costAllocationSheet with an unknown runId has
  no defined error code.
- **Chosen behavior:** dedicated code `E_COSTING_RUN_UNKNOWN` (analogous to
  E_OPENITEM_UNKNOWN).
- **Proposal:** add it to the error catalog + fixture.

## F-007: balanceSheet side assignment by root order — ✅ RESOLVED

> **Resolved.** The proposed explicit field was introduced instead of relying on root order:
> `format.schema.json` `$defs/mappingPosition` declares `side: assets|liabilitiesAndEquity`
> at the root node, and `BalanceSheetProjection` / `balance-sheet.ts` read it. Original finding:

- **Job:** JOB-008
- **What:** the spec does not define which mapping root is assets and
  which is liabilities-and-equity; the fixtures consistently use [assets, liabilities].
- **Chosen behavior:** first root position = assets (debit−credit),
  all others = liabilities-and-equity (credit−debit).
- **Proposal:** `side: assets|liabilitiesAndEquity` on the mapping root node.

## F-003: No error code for "fiscal-year close with non-finalized postings" — ✅ RESOLVED

> **Resolved.** The proposed dedicated code was introduced rather than reusing
> `E_PERIOD_OUT_OF_ORDER`: `E_FISCALYEAR_UNFINALIZED_ENTRIES` is in the catalogue and the
> exit-code table, pinned by `testsuite/fixtures/core/fiscalyear-close-guard.json`.
> Original finding:

- **Job:** JOB-003
- **What:** api.md requires for `closeFiscalYear` that "all postings are
  finalized", but defines no code for the violation; no fixture.
- **Where:** api.md (period semantics, closeFiscalYear)
- **Chosen behavior:** `E_PERIOD_OUT_OF_ORDER` (the same code as for
  open periods — "close precondition violated").
- **Proposal:** consider a dedicated code `E_FISCALYEAR_UNFINALIZED_ENTRIES`
  or document the reuse.

## F-CROSS-001: Timestamp serialization not canonical across implementations — ✅ RESOLVED

> **Resolved (2026-06-20):** canonical format introduced — UTC, RFC 3339 with
> a fixed millisecond place and `Z` (byte-identical to JS `toISOString`). PHP:
> new helper `Summae\Core\Substrate\Timestamp::canonical()`, used for `recordedAt`
> (JournalEntry + DB column `recorded_at`), `at` (AuditRecord) and `exportedAt`
> (journalExport). Node already produced the format. The bidirectional cross-test
> has since compared the **full** journalExport **byte-exactly** (incl.
> contentHashes + exportedAt), without any exception — 44/44 in both directions.
> No fixture pinned the timestamps, hence no conformance change. Spec note
> for `determinismus.md`: pin down the canonical timestamp format.

- **Job:** Node-M4 (SF-15 cross-test, both directions)
- **What:** PHP and Node serialize the timestamps `recordedAt` (posting) and
  `at` (audit) **differently**: PHP as ATOM with the offset preserved and without
  milliseconds (`2026-06-07T12:00:00+02:00`), Node via `toISOString` as UTC with
  milliseconds (`2026-06-07T10:00:00.000Z`). **Same moment, different
  notation.** Only noticeable in the bidirectional cross-test: in PHP→Node Node
  passes PHP's string through verbatim (fits), in Node→PHP PHP reformats on read
  via `DateTimeImmutable` → the inline fields *and* the derived
  `manifest.contentHashes` (sha256 over the raw stream bytes) diverge. The
  conformance suite tolerates it (normalized comparison); strict cross-impl
  byte equality does not.
- **Where:** `determinismus.md` (timestamp format not pinned); PHP
  `JournalEntry`/`AuditRecord` (ATOM via `DateTimeImmutable`), Node
  `recordedAt`/`at` as a raw string.
- **Chosen behavior:** the cross-test (`cross-read.ts`) compares `at`/
  `recordedAt` as an **instant** (normalized to UTC/ms) and leaves the format-
  dependent `contentHashes` + the volatile `exportedAt` out; all remaining
  fields byte-exact. Proves data parity, not notation equality.
- **Proposal:** pin a **canonical timestamp format** in `determinismus.md`
  (e.g. RFC 3339, UTC `Z`, fixed milliseconds) and pull both
  implementations onto it — then the `contentHashes` also match
  byte-exactly in both directions.

## F-008: `format.schema.json` `mappingPosition` omits `includeNonCash` — ✅ schema extended

> **Resolved (2026-06-23):** `$defs/mappingPosition` now declares
> `includeNonCash` (`{ "type": "boolean" }`) — the schema matches the engine.
> **Still open (separate question):** pack-library JSON is not validated against
> the schema at all (only journalExport streams + manifest are). Whether to add
> schema validation for the pack-library (the third-party extension surface) is its
> own decision — this drift slipping through unnoticed is the argument for it.

- **Job:** us-pack build (2026-06-23)
- **What:** the cash-basis projection reads a position-level flag `includeNonCash`
  off the mapping leaf (`Policies/Projection/Mapping/Mapping.php` → `CashBasisProjection`
  R7: non-cash categories such as depreciation count without a cash flow). The us-pack
  module 5 (`us-schedule-c-2026`, kind `cash-basis-categories`) sets `includeNonCash: true`
  on its depreciation line (L13) per the module spec. But the normative
  `testsuite/schema/format.schema.json` `$defs/mappingPosition` does **not** declare
  `includeNonCash` and carries `additionalProperties: false` — by the schema the field
  is illegal on a mapping position.
- **Where:** `testsuite/schema/format.schema.json` (`$defs/mappingPosition`);
  `pack-library/us-pack/mappings/us-schedule-c.json`; core Mapping importer +
  `CashBasisProjection`.
- **Chosen behavior:** shipped `us-schedule-c-2026` with `includeNonCash: true` per the
  module spec and the engine that consumes it. Not currently breaking — pack-library JSON
  is loaded content-based (never validated against `format.schema.json`: `validate.py`
  skips module/pack files; `SchemaValidationTest` validates only journalExport streams +
  manifest), so the module resolves and runs green in both languages. First shipped
  `cash-basis-categories` module (the de-pack never shipped an EÜR mapping), hence the
  first time the gap surfaces.
- **Proposal:** extend `$defs/mappingPosition` with `"includeNonCash": { "type": "boolean" }`
  (meaningful only for `cash-basis-categories`) so the normative schema matches the engine
  before any move to schema-validate pack modules. Shared schema artifact — applies to Node too.

## F-009: `cashBasisReport` hard-codes a German VAT-passthrough treatment — ✅ resolved

> **Resolved (2026-06-24):** the hard-coded German strings are gone from the core. Tax
> accounts flow through the cash-basis result **only where the pack's mapping maps them**
> (label from the mapping leaf); unmapped tax accounts are a neutral pass-through. DE: the
> `de-euer` mapping maps its VAT accounts → German labels from the pack. US: `us-schedule-c`
> leaves sales tax unmapped → neutral. Regression guard: `SubstrateBoundaryTest`
> (`testCoreEmitsNoHardcodedJurisdictionLabels`) + the Node `no-jurisdiction-text` test.

- **Job:** us-pack conformance audit (2026-06-24)
- **What:** the cash-basis projection routes tax accounts by subtype with **hard-coded German
  labels** (`tax_out` → `"Vereinnahmte USt"`/`"USt-Zahlung an FA"`, `tax_in` → `"Gezahlte
  Vorsteuer"`) — the German EÜR rule (VAT flows through profit). For the US, sales tax is a
  pure pass-through (never income). With `2100 Sales Tax Payable` marked `tax_out` (required by
  `vatReturn`, F-… below), a SALETAX cash sale would count its collected tax as income under a
  German label — wrong for US.
- **Where:** `Policies/Projection/CashBasisProjection.php`; `pack-library/us-pack/accounts/us-accounts.json`.
- **Chosen behavior / workaround:** `us-schedule-c` posts its sample revenue **tax-free** so the
  mechanism (mapping labels + `includeNonCash`) is proven without tripping the DE-centric tax path.
- **Proposal:** make the cash-basis tax treatment pack-appropriate (neutral pass-through unless the
  cash-basis mapping maps the tax accounts; drop the hard-coded German strings). Behavior change with
  DE-fixture ripple → own job, human decision. Applies to Node too.

## F-010: `EXEMPT` (rate-0 standard) cannot be posted — 0.00 tax line rejected — ✅ RESOLVED

> **Resolved in 0.5.0 (2026-06-24).** The proposal below was built: `exempt` is now a
> registered tax mechanism (`TaxMechanisms`) that tags the base and emits **no** tax line,
> and the us-pack `EXEMPT` code selects it. Exempt sales post cleanly and appear in the
> return. Pinned by the conformance fixtures and by the `us` walkthrough scenario
> (`docs/handbuch/examples/scenarios/us.json`). Original finding kept for the record:

- **Job:** us-pack conformance audit (2026-06-24)
- **What:** the us-pack `EXEMPT` code (mechanism `standard`, rate `0.00`) emits a 0.00 tax line.
  `expandTax` returns it fine (proven by `us-exempt-sale`), but `postVoucher`/`post` reject it with
  `E_ENTRY_INVALID_AMOUNT` (zero-amount line). Exempt sales **cannot be recorded in the journal** with
  the EXEMPT code today — only previewed via `expandTax`; they also cannot appear in the sales-tax return.
- **Where:** tax expansion (`standard` at rate 0) + ledger amount validation; `us-exempt-sale`, `us-sales-tax-return`.
- **Chosen behavior:** documented; `us-sales-tax-return` covers the taxable line only.
- **Proposal:** add an `exempt` mechanism (base tag only, no tax line) — analogous to
  `intra_community_supply` — so exempt sales post cleanly and show in the return (open decision E).
  Engine addition → own job. Applies to Node too.

---

## Cross-language findings (NF-005 … NF-007)

The three findings below were found while walking the CLI for the handbook (2026-08-14) and are
**identical in PHP and Node** — same code path, same result, so cross-language equivalence holds
and they are model/spec questions rather than parity defects.

They deliberately keep **one shared number in both files** instead of the older double numbering
(NF-002 ↔ F-008, NF-003 ↔ F-009, NF-004 ↔ F-010), which made the same finding look like two.
`F-011` is *not* reused here: that number belongs to the knowledge base's own finding list.

**Full analysis, assessment and proposed directions: `implementations/node/SPEC-FINDINGS.md`.**
Summary and the PHP sites:

- **NF-005 — cash-basis VAT counts the reversal of an *unsettled* open item immediately — ✅ FIXED
  2026-08-15.** The direct-contribution loop in `VatReturnProjection.php` now also skips an entry
  that *reverses* an entry carrying open items: its tax follows the reversed entry's settlements
  instead of counting as a cash movement. Reversals of genuinely cash-effective entries still count
  directly, at their own date. **Still open:** the settled-then-reversed case (tax stays declared
  until a refund is posted vs. corrected at the reversal's date, the `F-011` reading) — no fixture
  pins either.
- **NF-006 — `cashBasisReport` without `year` raises an uncaught `InvalidValue`.**
  `CashBasisProjection.php:63` defaults a missing `year` to `0`, then builds
  `CalendarDate::of('0000-01-01')` in `assertCalendarYearFiscalYears`. Not a `DomainError`, so the
  CLI prints a stack trace instead of its documented JSON error line. Realistic trigger: every
  other projection except `vatReturn` takes `fiscalYear`.
- **NF-007 — a missing or unknown mapping reports `E_MAPPING_OVERLAP`.**
  `IncomeStatementProjection.php:46`, `BalanceSheetProjection.php:49` — a code whose name says the
  opposite of what happened, and the only error a tax-free configuration hits on a normal report.
  Current behaviour pinned in `docs/handbuch/examples/scenarios/default.json`.
- **NF-008 — a reversal leaves the reversed entry's open items standing.** `reverse` posts the
  counter-entry but does not touch the open items the reversed entry created: the trial balance
  shows the payable account at `0.00` while `openItems` still reports it open and settleable.
  Found while fixing NF-005; distinct from it and not its cause. A cancelled open item must keep
  its settlement history (dropping it would rewrite filed VAT periods), and whether it disappears
  or gains a terminal `cancelled` status is a **data-format** decision. Documented, not changed.

Each still-open item needs a spec decision (NF-007 an append to the error catalogue, NF-008 a
data-format decision) before either language moves.

- **NF-009 — `CalendarDate` accepted years 0000–0099 in PHP and rejected them in Node — ✅ FIXED
  2026-08-15.** A substrate-level equivalence break: Node validated by round-tripping through
  `Date.UTC(year, …)`, which maps years 0–99 onto 1900+year, so `0000-01-01`…`0099-12-31` were
  rejected there and accepted here. **PHP is unchanged** — Node was widened to match it by
  dropping the host `Date` from the value object entirely (explicit days-per-month table +
  Gregorian leap rule). Pinned by `CalendarDateTest` and Node's `calendar-date.test.ts`, which
  carry the **same** accepted/rejected tables (34 cases each). Full write-up:
  `implementations/node/SPEC-FINDINGS.md`.

- **NF-010 — `Money.of` accepted amounts the data format forbids — ✅ FIXED 2026-08-15.**
  Validation went straight to `brick/math`, which parses far more than
  `format.schema.json` `$defs/money/properties/amount` (`^-?\d+(\.\d{1,4})?$`) allows:
  `"1e3"` booked as `1000.00`, `"1.5e+21"` as `1500000000000000000000.00`, `"10."` and
  `".5"` likewise — and `"+10.00"` was accepted here while Node rejected it, a second
  substrate divergence after NF-009. `Money::of` now matches the string against the
  data-format expression before parsing; `fromCalculation` is untouched. Full write-up:
  `implementations/node/SPEC-FINDINGS.md`.
