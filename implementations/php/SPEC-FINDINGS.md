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
| F-004 asset posting accounts | ✅ accounts via the `assetAccounts` pack module; **pool period** `poolYears` in the depreciation module since 2026-08-16 (fixture `gwg-pool-period`) |
| F-005 journalExport manifest streams | ✅ `auditLog` always included; `formatVersion` follows the spec version (0.6 since 2026-08-16, guarded against drift) |
| F-006 `E_COSTING_RUN_UNKNOWN` | ✅ code + `costing/costing-run-unknown.json` |
| F-007 balanceSheet side assignment | ✅ explicit `side` in the mapping schema and in both projections |
| F-008 `includeNonCash` missing from the schema | ✅ schema extended |
| F-009 `cashBasisReport` German VAT passthrough | ✅ resolved |
| F-010 `EXEMPT` cannot be posted | ✅ `exempt` mechanism (0.5.0) |
| F-CROSS-001 timestamp serialization | ✅ resolved |
| NF-005 cash-basis reversal | ✅ fixed 2026-08-15; **remainder closed 2026-08-16** by NF-008 — settled-then-reversed cannot occur any more |
| NF-006 `cashBasisReport` without `year` crashes | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| NF-007 missing mapping reports `E_MAPPING_OVERLAP` | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| NF-008 reversal leaves open items standing | **RESOLVED 2026-08-16** — the reversal clears them (`cause: cancellation` → status `cancelled`), a touched item refuses the reversal (`E_ENTRY_HAS_SETTLED_ITEMS`); fixtures `reverse-clears-open-items`, `reverse-settled-item`, `vat-cash-basis-reversal-unpaid` |
| NF-009 `CalendarDate` years 0000–0099 diverged PHP vs. Node | ✅ fixed 2026-08-15 — Node no longer uses the host `Date` |
| NF-010 `Money.of` accepted amounts the data format forbids | ✅ fixed 2026-08-15 — `1.5e+21` was bookable; `+10.00` also diverged |
| NF-011 `post` accepted a fabricated `taxTag` into the VAT return | ✅ fixed 2026-08-15 |
| NF-012 `balanceSheet` silently ignored `fiscalYear` | ✅ fixed 2026-08-15 |
| NF-013 a wrong `direction` booked an incoming invoice inverted | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| NF-017 an unmapped balance account made the balance sheet stop balancing | **RESOLVED 2026-08-15** — `_unassigned` per section + `gapWarnings[]` (fixture `balance-sheet-gap`) |
| NF-014 accounts outside a mapping vanish from the income statement | **RESOLVED 2026-08-15** — `_unassigned` + `gapWarnings[]` (fixture `income-statement-gap`) |
| NF-015 `packages/laravel` has no tests of its own | **OPEN** — excluded from the coverage gate, deliberately |
| NF-016 four declared parameters that no implementation reads | ✅ three fixed 2026-08-16 (`journalExport.format`, `costAllocationSheet.fiscalYear`/`period`); `balanceSheet.incomeMapping` stays without effect **by decision** (NF-014) |
| **`E_INPUT_INVALID` added** | exit code 45 — ✅ catalogue entry written in the knowledge base |

**Genuinely open today: NF-015** — F-004, NF-008 and the NF-005 remainder were all closed on
2026-08-16.

The **entire Round 1 backlog (R-1 … R-12) is closed** as of 2026-08-16 — the twelve defects the
two adversarial probing agents turned up on 2026-08-15. Per-item write-ups:
`implementations/node/SPEC-FINDINGS.md`.

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
> `testing/testsuite/fixtures/core/voucher-unknown.json` pins it. Original finding:

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

## F-004: Account resolution for asset postings not specified — ✅ RESOLVED

> **Accounts: resolved.** The proposed keys became a pack module of their own — `kind:
> assetAccounts` (`pack-library/de-pack/assets/`, `pack-library/us-pack/assets/`) supplies the
> acquisition counter account, the depreciation expense account and the low-value-asset
> account as pack data, not as a name-matching fallback.
>
> **Pool period: resolved 2026-08-16.** The depreciation module gained `poolYears` on each
> `gwgThresholds` row; `AssetService` reads it and spreads the cost over exactly that many years
> (`poolYears × 12` plan months). The number that used to be compiled in — five years, one
> jurisdiction's rule — is now `de-afa`'s data; `us-macrs` carries `null` because the de-minimis
> route there is an outright expense, not a pool. Three guards, so the field cannot rot:
> `$defs/depreciationData` in the schema makes `poolYears` **conditionally required** wherever a
> `poolMax` is declared (validated for every shipped module by the pack-library schema test in
> both languages, with an explicit teeth check that a range without the field is rejected);
> `AssetService` refuses with `E_PACK_INCOHERENT` rather than defaulting, which covers hand-fed
> rule data that never passed a pack; and the conformance fixture `assets/gwg-pool-period`
> declares **three** years instead of the German five, so the old hard-coded behaviour would fail
> it loudly (36 plan months vs. 60, 300.00 depreciation vs. 180.00).
>
> Found while writing it: the **statute-citation guard has teeth**. The first version of the new
> comment named the paragraph and turned `no-jurisdiction-text.test.ts` red — which is exactly the
> mechanism working, and the reason it did not catch the original defect (the old comment named no
> paragraph, only the number 5).
>
> **Deliberately not built:** a pool with a *rate* instead of a *period* — the UK style, where a
> pool is written down at a percentage per year and never fully closes. That needs a method
> discriminator on the threshold, and no shipped pack has the case. Noted here so the next
> jurisdiction knows it is an extension, not a bug.
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

> **Resolved.** The contradiction is gone: `testing/testsuite/fixtures/io/journal-export-z3.json` now
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
> the exit-code table, pinned by `testing/testsuite/fixtures/costing/costing-run-unknown.json`.
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
> exit-code table, pinned by `testing/testsuite/fixtures/core/fiscalyear-close-guard.json`.
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
  `testing/testsuite/schema/format.schema.json` `$defs/mappingPosition` does **not** declare
  `includeNonCash` and carries `additionalProperties: false` — by the schema the field
  is illegal on a mapping position.
- **Where:** `testing/testsuite/schema/format.schema.json` (`$defs/mappingPosition`);
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
> (`testing/scenarios/walkthrough/us.json`). Original finding kept for the record:

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
  directly, at their own date. **Remainder closed 2026-08-16 by NF-008:** the settled-then-reversed
  case cannot arise any more, because a reversal is refused once the open item carries a settlement.
  That answers the question rather than choosing between its two readings — the tax stays declared,
  and the correction is a separate cash-effective posting with its own date, which is what
  § 17 Abs. 1 UStG prescribes ("in the taxation period in which the change occurred").
- **NF-006 — `cashBasisReport` without `year` raises an uncaught `InvalidValue`.**
  `CashBasisProjection.php:63` defaults a missing `year` to `0`, then builds
  `CalendarDate::of('0000-01-01')` in `assertCalendarYearFiscalYears`. Not a `DomainError`, so the
  CLI prints a stack trace instead of its documented JSON error line. Realistic trigger: every
  other projection except `vatReturn` takes `fiscalYear`.
- **NF-007 — a missing or unknown mapping reports `E_MAPPING_OVERLAP`.**
  `IncomeStatementProjection.php:46`, `BalanceSheetProjection.php:49` — a code whose name says the
  opposite of what happened, and the only error a tax-free configuration hits on a normal report.
  Current behaviour pinned in `testing/scenarios/walkthrough/default.json`.
- **NF-008 — RESOLVED 2026-08-16.** Researched rather than guessed, because the data-format
  decision hung on it. GoBD settles the first half: nothing is ever deleted, before or after
  finalization — even a finalized batch is corrected by a counter-entry, so the reversal posting
  itself was never in question. The half that mattered is a different axis, and SAP draws it
  sharply (message F5308, `FB08`): *"a reverse posting clears all line items that are managed as
  open items, but this is not possible if one of the items in question has already been cleared by
  another method."* Both halves are now built. An **untouched** item is cleared by the reversal —
  a settlement pointing at the reversal entry, marked `cause: "cancellation"`, which derives the
  status `cancelled`. A **touched** item refuses the reversal with the new
  `E_ENTRY_HAS_SETTLED_ITEMS`; the correction goes through a credit note or refund instead.
  The data-format change stayed small because status was already derived, not stored: the enum
  gained `cancelled`, settlements gained `cause`. `cancelled` and not `settled` is the whole point
  — `settled` reads as "the money came in".
  **The marker is load-bearing, not cosmetic.** Cash-basis VAT follows an item's settlements, so
  the cancelling settlement would have been read as a receipt: reversing a never-paid 1,190.00
  invoice would have declared 190.00 of VAT out of thin air. `vatReturn` skips cancellation
  settlements, and `vat-cash-basis-reversal-unpaid` pins it with a paid invoice alongside as the
  control case.
  While writing the schema for it, a second gap surfaced: `$defs/openItem` declared neither
  `remaining` nor `status` nor the settlement `difference`, all of which the engine has always
  written, under `additionalProperties: false` — the NF-002/F-008 class again, latent because no
  test validated a stored open item against it. The declaration now matches what is written.
  Original finding:

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

- **NF-011 — `post` accepted a caller-fabricated `taxTag` straight into the VAT return — ✅ FIXED
  2026-08-15.** `Ledger.php:706` stored whatever the caller sent; the VAT return is built from
  those tags, so an invented `reportingKey` became a line of a statutory return at exit 0.
  A tag whose `code` is set must now resolve in the `TaxCodeRegistry` (`E_TAXCODE_UNKNOWN`,
  existing code). **PHP needed the registry wired in twice** — `DatabaseTenantFactory.php:78`
  duplicates the ledger construction from `Tenant.php:96`, where Node has one path; worth
  removing that duplication separately.
- **NF-012 — `balanceSheet` silently ignored `fiscalYear` — ✅ FIXED 2026-08-15.** Two different
  years returned byte-identical sheets, while the cheat sheet and the gated scenarios both
  passed the parameter. It now scopes cumulatively ("as at the end of year N"); mirroring
  trialBalance's G1 rule was tried first and left the sheet unbalanced by exactly the prior
  year's result, because summae writes no closing entries. Full write-up:
  `implementations/node/SPEC-FINDINGS.md`.

- **NF-013 — a wrong `direction` booked an incoming invoice fully inverted — ✅ FIXED 2026-08-15.**
  `TaxService.php:58` treated anything that was not exactly `'input'` as an output voucher, so
  `"Input"` with a capital I credited the expense and debited the payable — the mirror image of
  the correct booking, carrying a valid tax tag so nothing downstream flagged it. An absent
  `direction` still defaults to `'output'`; a wrong value is now `E_INPUT_INVALID`.
- **`E_INPUT_INVALID` (exit 45) added to the catalogue** for "a parameter is present but not valid
  input". In use in `TaxService`, `CashBasisProjection`, `AccountSheetProjection`,
  `IncomeStatementProjection`, `BalanceSheetProjection`. The normative entry
  (`50-spezifikation/fehlerkatalog.md`, section `E_INPUT`) and the conformance fixture
  (`core/input-invalid.json`) were written in the knowledge base and mirrored via `make sync` —
  green in both languages on the first run.
- **NF-014 — RESOLVED 2026-08-15.** An unmapped account no longer vanishes from the income
  statement: it goes into the catch-all `_unassigned` and is named in `gapWarnings[]`, the
  treatment the error catalogue prescribes and `importMapping` already applied. `balanceSheet`
  stays as it was on purpose — its type-based sum is what makes the identity hold by
  construction. Full write-up: `implementations/node/SPEC-FINDINGS.md`.

## NF-015: `packages/laravel` has no tests of its own — gate gap

- **Job:** chore/coverage-all-packages (2026-08-15)
- **What:** the persistence adapter is the one package with no test suite. `packages/laravel/tests/`
  contains a single `.gitkeep`; `phpunit.xml.dist` declares a `laravel` testsuite that therefore
  runs zero tests. Root `CLAUDE.md` calls a contract surface without its own guard a gate-gap
  finding — this is one, and it sits on the surface that writes and reads the shared data format.
- **Where:** `implementations/php/packages/laravel/` (src ~534 statements), `phpunit.xml.dist`,
  `runner/bin/coverage-gate.php`
- **Chosen behavior:** the package is **excluded** from the coverage source set and carries **no
  floor**, with the reason stated at both places. Measuring it would pin a number nobody
  maintains: it does get ~79 % line coverage today, but purely as a side effect of the conformance
  runner's `database` subject and the cross-test driving it — coverage that no test in this
  package asserts anything about, and that would silently move whenever those suites change.
  Excluding it keeps the gate honest; the gap stays visible here instead of hiding inside a
  green percentage.
- **Proposal:** give the adapter its own suite (repository round-trips per aggregate, the JSON
  columns of the `summae_*` tables, tenant scoping) and then add a `laravel` floor to
  `coverage-gate.php` — the floors are a ratchet, so the number can only rise after that.
  Deliberately **not** done here: it is a test-writing job, not a gate-wiring one. Node has no
  counterpart — `packages/knex` has no tests of its own either, but is covered through the CLI
  package's tests and does carry a floor there.
