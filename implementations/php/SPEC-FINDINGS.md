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

## F-001: No error code for unknown voucherId

- **Job:** JOB-003
- **What:** `E_ENTRY_NO_VOUCHER` is defined as "voucherId missing". For a
  *set but unknown* voucherId no code exists; no fixture covers the case.
- **Where:** fehlerkatalog.md (E_ENTRY), api.md (post)
- **Chosen behavior:** an unknown voucherId is also reported as
  `E_ENTRY_NO_VOUCHER` (reference check step 2, before accounts).
- **Proposal:** either pin it down explicitly that way or introduce a dedicated code
  `E_VOUCHER_UNKNOWN` + fixture.

## F-002: E_ENTRY_NOT_FINALIZED in api.md, but not in the error catalog

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

## F-004: Account resolution for asset postings not specified

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

## F-005: journal-export-z3 vs. audit-trail — manifest streams contradict each other

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

## F-006: E_COSTING_RUN_UNKNOWN missing from the catalog

- **Job:** JOB-010
- **What:** releaseCosting/costAllocationSheet with an unknown runId has
  no defined error code.
- **Chosen behavior:** dedicated code `E_COSTING_RUN_UNKNOWN` (analogous to
  E_OPENITEM_UNKNOWN).
- **Proposal:** add it to the error catalog + fixture.

## F-007: balanceSheet side assignment by root order

- **Job:** JOB-008
- **What:** the spec does not define which mapping root is assets and
  which is liabilities-and-equity; the fixtures consistently use [assets, liabilities].
- **Chosen behavior:** first root position = assets (debit−credit),
  all others = liabilities-and-equity (credit−debit).
- **Proposal:** `side: assets|liabilitiesAndEquity` on the mapping root node.

## F-003: No error code for "fiscal-year close with non-finalized postings"

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

## F-008: `format.schema.json` `mappingPosition` omits `includeNonCash`

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
