# SPEC-FINDINGS

Findings from the implementation: places where spec, fixtures and model
contradict each other or where something is missing. Rule from the briefing: **do not
guess, do not change the fixture** — document it here and keep building with the
next most plausible behavior.

> **Finding IDs were re-prefixed on 2026-08-23 — the numbers did not change.**
> The old prefixes sat inside the requirement namespaces: `F-0xx` was one category
> word away from the functional requirements (`F-CORE-…`, `F-IO-…`), and `NF-0xx` was
> separated from the non-functional requirements (`NF-1` … `NF-7`) by nothing but its
> leading zeros.
>
> | old | new | series |
> |---|---|---|
> | `F-001` … `F-011` | `SPEC-001` … `SPEC-011` | spec/fixture/model contradictions |
> | `F-CROSS-001` | `SPEC-C01` | cross-implementation |
> | `NF-001` … `NF-025` | `IMPL-001` … `IMPL-025` | implementation & cross-language |
>
> `CHANGELOG.md` deliberately keeps the old IDs: released notes describe what was
> published, so rewriting them would falsify the record.

> **✅ All findings SPEC-001 to SPEC-007 resolved in spec v0.5** (2026-06-08,
> decision log + `SPEC-UPDATE-v0.5.md`) and implemented in JOB-V05:
> - SPEC-001 → dedicated code `E_VOUCHER_UNKNOWN`
> - SPEC-002 → `E_ENTRY_NOT_FINALIZED` removed, `reverse` status-independent (my workaround was correct)
> - SPEC-003 → dedicated code `E_FISCALYEAR_UNFINALIZED_ENTRIES`
> - SPEC-004 → rule-module block `assetAccounts` (name heuristic removed)
> - SPEC-005 → manifest required fields `streams`/`hashAlgorithm`, `auditLog` always, `formatVersion` current
> - SPEC-006 → dedicated code `E_COSTING_RUN_UNKNOWN` (already matched my choice)
> - SPEC-007 → `side: assets|liabilitiesAndEquity` on the balance-sheet root node
>
> The detail entries below remain as history.

## Status at a glance

Re-verified against the code on 2026-08-15 — the per-finding headings below now carry their
status, so scanning the list no longer suggests open work that is long done. Resolved entries
keep their original text under the resolution note: why a decision was made is worth more than
a short file.

| Finding | Status |
|---|---|
| SPEC-001 unknown `voucherId` | ✅ `E_VOUCHER_UNKNOWN` + `core/voucher-unknown.json` |
| SPEC-002 `E_ENTRY_NOT_FINALIZED` in api.md, not in the catalogue | ✅ code dropped from the spec, `reverse` is status-independent |
| SPEC-003 fiscal-year close with unfinalized postings | ✅ `E_FISCALYEAR_UNFINALIZED_ENTRIES` + `core/fiscalyear-close-guard.json` |
| SPEC-004 asset posting accounts | ✅ accounts via the `assetAccounts` pack module; **pool period** `poolYears` in the depreciation module since 2026-08-16 (fixture `gwg-pool-period`) |
| SPEC-005 journalExport manifest streams | ✅ `auditLog` always included; `formatVersion` follows the spec version (0.6 since 2026-08-16, guarded against drift) |
| SPEC-006 `E_COSTING_RUN_UNKNOWN` | ✅ code + `costing/costing-run-unknown.json` |
| SPEC-007 balanceSheet side assignment | ✅ explicit `side` in the mapping schema and in both projections |
| SPEC-008 `includeNonCash` missing from the schema | ✅ schema extended |
| SPEC-009 `cashBasisReport` German VAT passthrough | ✅ resolved |
| SPEC-010 `EXEMPT` cannot be posted | ✅ `exempt` mechanism (0.5.0) |
| SPEC-C01 timestamp serialization | ✅ resolved |
| IMPL-005 cash-basis reversal | ✅ fixed 2026-08-15; **remainder closed 2026-08-16** by IMPL-008 — settled-then-reversed cannot occur any more |
| IMPL-006 `cashBasisReport` without `year` crashes | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| IMPL-007 missing mapping reports `E_MAPPING_OVERLAP` | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| IMPL-008 reversal leaves open items standing | **RESOLVED 2026-08-16** — the reversal clears them (`cause: cancellation` → status `cancelled`), a touched item refuses the reversal (`E_ENTRY_HAS_SETTLED_ITEMS`); fixtures `reverse-clears-open-items`, `reverse-settled-item`, `vat-cash-basis-reversal-unpaid` |
| IMPL-009 `CalendarDate` years 0000–0099 diverged PHP vs. Node | ✅ fixed 2026-08-15 — Node no longer uses the host `Date` |
| IMPL-010 `Money.of` accepted amounts the data format forbids | ✅ fixed 2026-08-15 — `1.5e+21` was bookable; `+10.00` also diverged |
| IMPL-011 `post` accepted a fabricated `taxTag` into the VAT return | ✅ fixed 2026-08-15 |
| IMPL-012 `balanceSheet` silently ignored `fiscalYear` | ✅ fixed 2026-08-15 |
| IMPL-013 a wrong `direction` booked an incoming invoice inverted | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| IMPL-017 an unmapped balance account made the balance sheet stop balancing | **RESOLVED 2026-08-15** — `_unassigned` per section + `gapWarnings[]` (fixture `balance-sheet-gap`) |
| IMPL-014 accounts outside a mapping vanish from the income statement | **RESOLVED 2026-08-15** — `_unassigned` + `gapWarnings[]` (fixture `income-statement-gap`) |
| IMPL-015 `packages/laravel` has no tests of its own | **RESOLVED 2026-08-16** — own suite (19 tests), coverage floor 95%; found and fixed a tenant-scoping defect in **both** adapters |
| IMPL-016 four declared parameters that no implementation reads | ✅ three fixed 2026-08-16 (`journalExport.format`, `costAllocationSheet.fiscalYear`/`period`); `balanceSheet.incomeMapping` stays without effect **by decision** (IMPL-014) |
| **`E_INPUT_INVALID` added** | exit code 45 — ✅ catalogue entry written in the knowledge base |
| IMPL-018 four error codes have no exit code | **RESOLVED 2026-08-16** — appended at 49–53 in both languages (`E_AMOUNT_SCALE_MISMATCH` with them, so the guard needs no exception list); `ExitCodesTest`/`exit-codes.test.ts` read the catalogue and fail when a code in it has no exit code of its own |
| IMPL-019 pooled assets stopped depreciating on disposal | **RESOLVED 2026-08-16** — `runDepreciation` skipped every disposed asset, pooled ones included; F-AST-006 requires the pool to run its full term regardless. Both languages fixed, fixture `pool-unaffected-by-disposal` |
| IMPL-020 `supplierTaxationMethod` could never be set | **RESOLVED 2026-08-16** — declared in the data format (`enum accrual\|cash`, F-TAX-007) and carried by both record classes, but no code ever read it from the input. Now accepted and validated (`E_INPUT_INVALID` on an unknown value); fixture `supplier-taxation-method` |
| IMPL-021 asset disposal never writes off the carrying amount | **RESOLVED 2026-08-16** — `dispose` now credits the carrying amount off the asset account and books the difference to the pack's `disposalProceedsAccount`/`disposalLossAccount`; pooled assets stay exempt (IMPL-019). Fixture `pool-unaffected-by-disposal` |
| IMPL-022 disposal does not catch up depreciation to the disposal date | **RESOLVED 2026-08-16** — `dispose` books the depreciation that is due before writing off; due follows the schedule's own convention (a plan month falls due on its last day). Fixture `disposal-catches-up-depreciation` |
| IMPL-023 machine entries cannot carry a required dimension | **RESOLVED 2026-08-16** — the asset carries its dimensions (`acquireAsset(dimensions)`), and acquisition, depreciation, catch-up and disposal all book with them; both persistence adapters carry them through the round trip. Fixture `asset-dimensions` |
| IMPL-024 pooled assets reported a carrying amount of zero | **RESOLVED 2026-08-16** — `bookValueAt` returned zero for everything except `capitalize`, so the fixed-asset schedule (F-AST-005) understated the balance sheet it explains. Only `immediate_expense` has no carrying amount |
| IMPL-025 the pool-disposal rule was German law inside the core | **RESOLVED 2026-08-16** — the IMPL-019 fix hard-coded § 6 Abs. 2a EStG (`route !== 'pool'`). It is now the pack's answer (`poolReducedOnDisposal`, conditionally required next to `poolMax`), refused rather than defaulted, with fixtures for both answers |

SPEC-004, IMPL-008, the IMPL-005 remainder, IMPL-015 and IMPL-018 were all closed on 2026-08-16, and IMPL-019 +
IMPL-020 were **found and closed** the same day while closing the gate gaps below. **The findings list
is empty.**

### IMPL-025 — the pool-disposal rule was German law inside the core — RESOLVED

Roland's catch, and the sharpest finding of the day: fixing IMPL-019 I wrote „a pooled asset keeps
depreciating after disposal" straight into `runDepreciation` as `route !== 'pool'`. That is not a
property of pooling — it is **§ 6 Abs. 2a Satz 4 EStG**, one jurisdiction's answer. The UK and
Australia do the opposite: disposals are taken out of their pools. So every future pack with a
pooled de-minimis regime would have inherited the German answer silently — the exact mistake SPEC-004
had already fixed for the pool *period*, one line further down in the same file.

It is now `poolReducedOnDisposal` in the depreciation module, conditionally required next to
`poolMax` in the schema (like `poolYears`), and refused rather than defaulted: a pack that opens a
pool must answer both questions. `de-pack` says `false` (§ 6 Abs. 2a), and the two fixtures
`pool-unaffected-by-disposal` / `pool-reduced-on-disposal` drive the same sequence through both
answers — the same core, two results.

**The lesson generalises:** the litmus test in `CLAUDE.md` ("does your code cite a statute → wrong
layer") catches statutes that are *quoted*. It does not catch a statute that has been silently
translated into a condition. Both my IMPL-019 fix and its comment read as mechanism; only the
question "would another country answer this differently?" exposes it.

### IMPL-024 — pooled assets reported a carrying amount of zero — RESOLVED

`bookValueAt` short-circuited to zero for every route except `capitalize`. True for an immediately
expensed asset, which was never capitalised — false for a pooled one, which sits on the pool
account and is written down over the pack's term. The fixed-asset schedule (F-AST-005) therefore
reported zero book value for assets that are in the balance sheet with a real one. Invisible while
nothing consumed the value for pooled assets; the IMPL-021 write-off consumed it, and the disposal
of a pooled asset under a `poolReducedOnDisposal: true` pack wrote off nothing at all.

### IMPL-023 — a machine entry cannot carry a required dimension — RESOLVED

Found when the disposal catch-up started booking depreciation in `edge-errors`, whose rule module
makes a cost centre mandatory for 4000–4999. `postMachineEntry` builds its lines itself and has no
dimension to give, so any tenant that puts a mandatory dimension on the depreciation account cannot
run depreciation **at all** — neither the regular run nor the catch-up. Pre-existing, not caused by
this work; the fixture dodges it by moving the account out of the range.

**Decided: the asset carries its dimensions.** `acquireAsset` takes `dimensions`, the asset stores
them, and every machine entry about it — acquisition, the regular run, the disposal catch-up, the
disposal itself — books every line with them. Both persistence adapters carry them through the
round trip, so a restart does not silently make depreciation impossible again.

Why not the alternatives:

- *Exempt machine entries from dimension constraints* would have been wrong on the merits.
  Depreciation is exactly the kind of expense cost accounting wants per cost centre — exempting it
  guts the constraint at the one place it matters most.
- *A default dimension in the rule module* answers "which cost centre?" once for every asset in the
  company, which is not an answer anyone would want.
- *Leave it to the pack to keep such accounts out of dimension ranges* would have made the pack work
  around a core limitation.

The chosen way is also plain fixed-asset practice: an asset belongs to a cost centre and its
depreciation belongs there with it — a master-data fact, not a jurisdiction's rule, so it stays in
the core without repeating the IMPL-025 mistake.

### IMPL-022 — the disposal does not catch up depreciation to the disposal date — RESOLVED

> **Resolved 2026-08-16.** `dispose` now books the depreciation that is due before it writes
> anything off. Which months are due follows the schedule's own convention — a plan month falls due
> on its last day, exactly as `monthTarget` reads it for the regular run — so no new rule enters the
> core. **Deliberately left to the pack:** whether the month an asset leaves in counts as a whole
> month is a jurisdiction's answer (Germany grants it, US conventions are half-year or mid-quarter),
> so an asset disposed mid-month gets no depreciation for that month today. That is the honest
> limit, and it is the same shape as IMPL-025 — the moment we answer it in the core, we have written
> law again. Original finding:

Fallout from fixing IMPL-021, and visible only because the write-off exists now. `bookValueAt`
reports what has actually been **booked**, not what would be owed up to that date; the yearly
`runDepreciation` books on 31 December. Dispose an asset on 30 June without running depreciation
first and the carrying amount written off is the one from the start of the year — the loss is
overstated by exactly the pro-rata share, and the expense lands in the disposal account instead of
in depreciation.

Deliberately not fixed along the way, because it is a second decision rather than a missing line:
`dispose` would have to trigger a partial depreciation run of its own, which makes one operation
write two economically different entries and raises its own questions (which voucher? what if the
period is already closed? what if the caller *wants* to book depreciation separately, as the
period-wise `runDepreciation(fiscalYear, period)` path suggests). The honest interim state: run
depreciation up to the disposal period first, then dispose. `pool-unaffected-by-disposal` does
exactly that and says so.

### IMPL-021 — asset disposal never writes off the carrying amount — RESOLVED

> **Resolved 2026-08-16.** `dispose` now books the whole event: the carrying amount is credited
> off the asset account, and the difference between proceeds and book value goes to the pack's
> `disposalProceedsAccount` (gain) or `disposalLossAccount` (loss) — the two accounts the resolver
> had been requiring and nothing had been booking. A scrapping without proceeds is the loss case;
> a fully depreciated asset scrapped for nothing books no entry at all rather than an empty one.
> Pooled assets are exempt, because IMPL-019 established that the pool is not reduced when an item
> leaves. The write-off is a single credit against the asset account because this core depreciates
> *net* — there is no accumulated-depreciation account to reverse. Original finding:

`dispose` sets the asset's status and, if proceeds were passed, books exactly one entry:
`bankAccount → proceedsAccount`. The asset account is never relieved. Two consequences, both
silent:

- A disposed asset **stays in the balance sheet at its carrying amount**, forever. The fixed-asset
  line keeps something the company no longer owns.
- The proceeds land as income **in full**, instead of as a gain or loss against book value. Sell a
  machine with 1500.00 book value for 2000.00 and the books show 2000.00 income rather than 500.00
  gain — profit overstated by exactly the carrying amount.

`disposalProceedsAccount` and `disposalLossAccount` are declared in the pack, and the resolver
*requires* them (I3, `E_PACK_UNRESOLVED_REF` if missing) — but no code path books either of them.
`proceedsAccount` comes from the caller's input instead.

**Not fixed unilaterally**, because the fix is a design decision, not a missing line:
1. **Which entry?** The customary form nets it — `bank + accumulated depreciation → asset +
   gain/loss` — but that presumes gross presentation with an accumulated-depreciation account,
   and this core depreciates by crediting the asset account directly (net presentation). So the
   entry is `bank → asset (carrying amount)` plus the difference to `disposalProceedsAccount` or
   `disposalLossAccount`.
2. **Depreciation up to the disposal month** would have to run first, otherwise the carrying
   amount being written off is stale. Today `runDepreciation` is a separate call.
3. **The pool must be exempt** — IMPL-019 just established that the pool is not reduced when an item
   leaves. So this is `route === 'capitalize'` only, and the two rules must not collide.
4. **Are the pack accounts mandatory or is the input override kept?** Today's `proceedsAccount`
   input parameter is documented and used by fixtures.

**How it escaped the sweep that found IMPL-019/IMPL-020:** F-AST-004 *has* a `covers` link, so it never
showed up in the list of uncovered requirements. The fixture it points at (`edge-errors`) only
exercises the error paths — `E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`, and `status: "disposed"`. It
asserts no booking at all. A `covers` link means "some fixture names this requirement", not "this
requirement is checked" — the sweep needs a second pass over the linked ones, not just the
unlinked.

The fixture `pool-unaffected-by-disposal` written on 2026-08-16 records the wrong behaviour in one
row (the disposed machine still standing at 2400.00) and says so inline, so the fix has to change
it visibly rather than silently agreeing with it.

### IMPL-019 / IMPL-020 — found by asking which requirements have no test

Neither came from a bug report; both came from listing the requirements (`30-anforderungen/`)
against the `covers` fields of the fixtures and then checking, for each requirement without a
link, whether the capability exists in the code. That separates two very different things: not
built yet (no finding — six requirements are in that group and the handbook claims none of them)
versus **built and unwatched**, which is where these two sat.

- **IMPL-019** — `runDepreciation` skipped every disposed asset. Correct for a single asset, wrong
  for a pooled one: F-AST-006 requires the pool to be written off on its fixed schedule
  *unaffected by disposals*, and the jurisdiction behind the rule states it outright — the pool is
  not reduced when an item leaves. The effect was silent and directional: too little depreciation
  and too much profit, for every remaining year of the term. Fixed in both languages by exempting
  `route === pool` from the skip; the disposal still books its proceeds.
- **IMPL-020** — `supplierTaxationMethod` sat in `format.schema.json` as `enum ["accrual","cash"]`,
  in `datenformat.md` with F-TAX-007 next to it, and in both `Voucher` classes — but the PHP
  constructor call passed a literal `null` for it and the Node object literal omitted it, so no
  caller could ever set it. The field decides whether input tax is deductible on invoice or only
  on payment. An unknown value is now rejected rather than dropped: storing null silently would
  read as "supplier taxes on accrual", which is the answer that permits the earlier deduction.

### IMPL-018 — four error codes fall through to exit code 1 — RESOLVED

> **Resolved 2026-08-16.** The four codes were appended to `ExitCodes`/`exit-codes.ts` (49–52),
> together with `E_AMOUNT_SCALE_MISMATCH` (53): it is declared in the catalogue but not yet
> thrown anywhere, and mapping it means the new guard test can demand the *whole* catalogue
> without an exception list — an exception list would be the same hole again. Nothing was
> renumbered. The missing piece was never the four entries but the comparison: `ExitCodesTest`
> (PHP) and `exit-codes.test.ts` (Node) now parse `testing/testsuite/fehlerkatalog.md` and fail
> when a catalogued code maps to `1`, when two codes share a number, or when an insertion shifts
> the anchors (10 / 45 / 53).
>
> The reservation below — that the numeric mapping needs a knowledge-base decision the way
> `E_INPUT_INVALID` did — turned out not to apply: the catalogue carries *names*, not numbers
> (the number is the position in the append-only list), and all five codes were already in it.
> So no knowledge-base change was needed, and the fix is code + guard.
>
> **The gap in the other direction is closed too.** `E_NOT_IMPLEMENTED` had an exit code (44)
> and a handbook entry but no catalogue row — invisible to every machine check, including the
> guard above. The catalogue's line is not "domain errors" but *everything a caller can rely on*
> (which is why the pure CLI code `E_WORKSPACE_INVALID` is in it), so the row was missing, not
> withheld. It was added in the knowledge base on 2026-08-16 and mirrored here; both guards now
> compare the two lists **as sets**, so neither direction can drift. 44 codes, covering each
> other exactly.
>
> Original finding:

Found while checking the handbook's error catalogue against the code. `E_SETTLEMENT_EXCEEDS_ENTRY`
(new with R-1) and the three pack-composition codes `E_PACK_UNRESOLVED_REF`, `E_PACK_INCOHERENT`
and `E_POLICY_INVALID` are thrown by the core but are not in the `CODES` list that
`exitCodeFor`/`ExitCodes` maps, so they return `1` — which the CLI documents as *unknown error*,
i.e. indistinguishable from a summae bug. It hits `summae init --pack …` (all three pack codes)
and every settlement that over-claims its entry.

The JSON on stderr still names the code, so nothing is lost for a human reader; a script that
branches on the exit code cannot tell these four from a crash.

**Not fixed unilaterally**, because it is not a code-only change: the list is **append-only and
identical in both languages**, and the numeric mapping is part of the error catalogue in the
knowledge base (the same route `E_INPUT_INVALID` took, exit 45). Appending four entries in
`exit-codes.ts` + `ExitCodes.php` would be mechanical; the catalogue entry is Roland's call.
Documented in the handbook meanwhile, so the gap is at least visible to users.

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

## SPEC-001: No error code for unknown voucherId — ✅ RESOLVED

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

## SPEC-002: E_ENTRY_NOT_FINALIZED in api.md, but not in the error catalog — ✅ RESOLVED

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

## SPEC-004: Account resolution for asset postings not specified — ✅ RESOLVED

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

## SPEC-005: journal-export-z3 vs. audit-trail — manifest streams contradict each other — ✅ RESOLVED

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

## SPEC-006: E_COSTING_RUN_UNKNOWN missing from the catalog — ✅ RESOLVED

> **Resolved.** The proposed code was added: `E_COSTING_RUN_UNKNOWN` is in the catalogue and
> the exit-code table, pinned by `testing/testsuite/fixtures/costing/costing-run-unknown.json`.
> Original finding:

- **Job:** JOB-010
- **What:** releaseCosting/costAllocationSheet with an unknown runId has
  no defined error code.
- **Chosen behavior:** dedicated code `E_COSTING_RUN_UNKNOWN` (analogous to
  E_OPENITEM_UNKNOWN).
- **Proposal:** add it to the error catalog + fixture.

## SPEC-007: balanceSheet side assignment by root order — ✅ RESOLVED

> **Resolved.** The proposed explicit field was introduced instead of relying on root order:
> `format.schema.json` `$defs/mappingPosition` declares `side: assets|liabilitiesAndEquity`
> at the root node, and `BalanceSheetProjection` / `balance-sheet.ts` read it. Original finding:

- **Job:** JOB-008
- **What:** the spec does not define which mapping root is assets and
  which is liabilities-and-equity; the fixtures consistently use [assets, liabilities].
- **Chosen behavior:** first root position = assets (debit−credit),
  all others = liabilities-and-equity (credit−debit).
- **Proposal:** `side: assets|liabilitiesAndEquity` on the mapping root node.

## SPEC-003: No error code for "fiscal-year close with non-finalized postings" — ✅ RESOLVED

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

## SPEC-C01: Timestamp serialization not canonical across implementations — ✅ RESOLVED

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

## SPEC-008: `format.schema.json` `mappingPosition` omits `includeNonCash` — ✅ schema extended

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

## SPEC-009: `cashBasisReport` hard-codes a German VAT-passthrough treatment — ✅ resolved

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
  `vatReturn`, SPEC-… below), a SALETAX cash sale would count its collected tax as income under a
  German label — wrong for US.
- **Where:** `Policies/Projection/CashBasisProjection.php`; `pack-library/us-pack/accounts/us-accounts.json`.
- **Chosen behavior / workaround:** `us-schedule-c` posts its sample revenue **tax-free** so the
  mechanism (mapping labels + `includeNonCash`) is proven without tripping the DE-centric tax path.
- **Proposal:** make the cash-basis tax treatment pack-appropriate (neutral pass-through unless the
  cash-basis mapping maps the tax accounts; drop the hard-coded German strings). Behavior change with
  DE-fixture ripple → own job, human decision. Applies to Node too.

## SPEC-010: `EXEMPT` (rate-0 standard) cannot be posted — 0.00 tax line rejected — ✅ RESOLVED

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

## Cross-language findings (IMPL-005 … IMPL-007)

The three findings below were found while walking the CLI for the handbook (2026-08-14) and are
**identical in PHP and Node** — same code path, same result, so cross-language equivalence holds
and they are model/spec questions rather than parity defects.

They deliberately keep **one shared number in both files** instead of the older double numbering
(IMPL-002 ↔ SPEC-008, IMPL-003 ↔ SPEC-009, IMPL-004 ↔ SPEC-010), which made the same finding look like two.
`SPEC-011` is *not* reused here: that number belongs to the knowledge base's own finding list.

**Full analysis, assessment and proposed directions: `implementations/node/SPEC-FINDINGS.md`.**
Summary and the PHP sites:

- **IMPL-005 — cash-basis VAT counts the reversal of an *unsettled* open item immediately — ✅ FIXED
  2026-08-15.** The direct-contribution loop in `VatReturnProjection.php` now also skips an entry
  that *reverses* an entry carrying open items: its tax follows the reversed entry's settlements
  instead of counting as a cash movement. Reversals of genuinely cash-effective entries still count
  directly, at their own date. **Remainder closed 2026-08-16 by IMPL-008:** the settled-then-reversed
  case cannot arise any more, because a reversal is refused once the open item carries a settlement.
  That answers the question rather than choosing between its two readings — the tax stays declared,
  and the correction is a separate cash-effective posting with its own date, which is what
  § 17 Abs. 1 UStG prescribes ("in the taxation period in which the change occurred").
- **IMPL-006 — `cashBasisReport` without `year` raises an uncaught `InvalidValue`.**
  `CashBasisProjection.php:63` defaults a missing `year` to `0`, then builds
  `CalendarDate::of('0000-01-01')` in `assertCalendarYearFiscalYears`. Not a `DomainError`, so the
  CLI prints a stack trace instead of its documented JSON error line. Realistic trigger: every
  other projection except `vatReturn` takes `fiscalYear`.
- **IMPL-007 — a missing or unknown mapping reports `E_MAPPING_OVERLAP`.**
  `IncomeStatementProjection.php:46`, `BalanceSheetProjection.php:49` — a code whose name says the
  opposite of what happened, and the only error a tax-free configuration hits on a normal report.
  Current behaviour pinned in `testing/scenarios/walkthrough/default.json`.
- **IMPL-008 — RESOLVED 2026-08-16.** Researched rather than guessed, because the data-format
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
  written, under `additionalProperties: false` — the IMPL-002/SPEC-008 class again, latent because no
  test validated a stored open item against it. The declaration now matches what is written.
  Original finding:

- **IMPL-008 — a reversal leaves the reversed entry's open items standing.** `reverse` posts the
  counter-entry but does not touch the open items the reversed entry created: the trial balance
  shows the payable account at `0.00` while `openItems` still reports it open and settleable.
  Found while fixing IMPL-005; distinct from it and not its cause. A cancelled open item must keep
  its settlement history (dropping it would rewrite filed VAT periods), and whether it disappears
  or gains a terminal `cancelled` status is a **data-format** decision. Documented, not changed.

Each still-open item needs a spec decision (IMPL-007 an append to the error catalogue, IMPL-008 a
data-format decision) before either language moves.

- **IMPL-009 — `CalendarDate` accepted years 0000–0099 in PHP and rejected them in Node — ✅ FIXED
  2026-08-15.** A substrate-level equivalence break: Node validated by round-tripping through
  `Date.UTC(year, …)`, which maps years 0–99 onto 1900+year, so `0000-01-01`…`0099-12-31` were
  rejected there and accepted here. **PHP is unchanged** — Node was widened to match it by
  dropping the host `Date` from the value object entirely (explicit days-per-month table +
  Gregorian leap rule). Pinned by `CalendarDateTest` and Node's `calendar-date.test.ts`, which
  carry the **same** accepted/rejected tables (34 cases each). Full write-up:
  `implementations/node/SPEC-FINDINGS.md`.

- **IMPL-010 — `Money.of` accepted amounts the data format forbids — ✅ FIXED 2026-08-15.**
  Validation went straight to `brick/math`, which parses far more than
  `format.schema.json` `$defs/money/properties/amount` (`^-?\d+(\.\d{1,4})?$`) allows:
  `"1e3"` booked as `1000.00`, `"1.5e+21"` as `1500000000000000000000.00`, `"10."` and
  `".5"` likewise — and `"+10.00"` was accepted here while Node rejected it, a second
  substrate divergence after IMPL-009. `Money::of` now matches the string against the
  data-format expression before parsing; `fromCalculation` is untouched. Full write-up:
  `implementations/node/SPEC-FINDINGS.md`.

- **IMPL-011 — `post` accepted a caller-fabricated `taxTag` straight into the VAT return — ✅ FIXED
  2026-08-15.** `Ledger.php:706` stored whatever the caller sent; the VAT return is built from
  those tags, so an invented `reportingKey` became a line of a statutory return at exit 0.
  A tag whose `code` is set must now resolve in the `TaxCodeRegistry` (`E_TAXCODE_UNKNOWN`,
  existing code). **PHP needed the registry wired in twice** — `DatabaseTenantFactory.php:78`
  duplicates the ledger construction from `Tenant.php:96`, where Node has one path; worth
  removing that duplication separately.
- **IMPL-012 — `balanceSheet` silently ignored `fiscalYear` — ✅ FIXED 2026-08-15.** Two different
  years returned byte-identical sheets, while the cheat sheet and the gated scenarios both
  passed the parameter. It now scopes cumulatively ("as at the end of year N"); mirroring
  trialBalance's G1 rule was tried first and left the sheet unbalanced by exactly the prior
  year's result, because summae writes no closing entries. Full write-up:
  `implementations/node/SPEC-FINDINGS.md`.

- **IMPL-013 — a wrong `direction` booked an incoming invoice fully inverted — ✅ FIXED 2026-08-15.**
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
- **IMPL-014 — RESOLVED 2026-08-15.** An unmapped account no longer vanishes from the income
  statement: it goes into the catch-all `_unassigned` and is named in `gapWarnings[]`, the
  treatment the error catalogue prescribes and `importMapping` already applied. `balanceSheet`
  stays as it was on purpose — its type-based sum is what makes the identity hold by
  construction. Full write-up: `implementations/node/SPEC-FINDINGS.md`.

## IMPL-015: `packages/laravel` has no tests of its own — gate gap

- **Job:** chore/coverage-all-packages (2026-08-15)
- **What:** the persistence adapter is the one package with no test suite. `packages/laravel/tests/`
  contains a single `.gitkeep`; `phpunit.xml.dist` declares a `laravel` testsuite that therefore
  runs zero tests. Root `CLAUDE.md` calls a contract surface without its own guard a gate-gap
  finding — this is one, and it sits on the surface that writes and reads the shared data format.
- **Where:** `implementations/php/packages/laravel/` (src ~534 statements), `phpunit.xml.dist`,
  `runner/bin/coverage-gate.php`
- **Chosen behavior (until 2026-08-16):** the package was **excluded** from the coverage source set
  and carried **no floor**, with the reason stated at both places. Measuring it would have pinned a
  number nobody maintains: it did get ~79 % line coverage, but purely as a side effect of the
  conformance runner's `database` subject and the cross-test driving it — coverage that no test in
  this package asserted anything about. Excluding it kept the gate honest and the gap visible.
- **RESOLVED 2026-08-16.** The adapter has its own suite: `AdapterTestCase` (in-memory SQLite with
  the `summae_*` schema) · `RepositoryRoundTripTest` (books written by one tenant instance and read
  back by a second one on the same connection, so everything asserted has genuinely been through a
  column) · `TenantScopingTest` · `HydratorAndSchemaTest`. 19 tests, line coverage 96.97 %, floor
  95 % in `coverage-gate.php` and the package added to `phpunit.xml.dist`'s source set.
- **And it paid for itself immediately: every `byId`, `byOriginEntry` and `save` in the adapter
  ignored `tenant_id`.** A repository built for tenant A handed out — and wrote over — tenant B's
  rows by primary key. Seven of the new tests were red on the first run. The root `CLAUDE.md` calls
  summae "multi-tenant at the data level", and the `tenant_id` column exists for exactly this, but
  nothing enforced it on the by-key paths. No existing suite could have seen it: the conformance
  runner builds one tenant per fixture and the cross test one per database, so an adapter that
  ignores `tenant_id` entirely passes both. **The Node `packages/knex` adapter had the identical
  defect** — same lines, same fix, and it had no tests of its own either. Both are fixed and both
  now carry the mirrored suite (`packages/knex/test/adapter.test.ts`), verified red-before-green in
  each language.
- **Still uncovered:** `SummaeServiceProvider` (0 %, ~15 statements) — Laravel framework glue that
  needs a booted application (`orchestra/testbench`) to exercise. The floor is set below the
  measured value *with* that hole, so covering it later can only push the floor up.
  Deliberately **not** done here: it is a test-writing job, not a gate-wiring one. Node has no
  counterpart — `packages/knex` has no tests of its own either, but is covered through the CLI
  package's tests and does carry a floor there.

## SPEC-011: F-KLR-001 says evaluations read released runs only — a fixture reads a draft

**Found 2026-08-23, while building `overheadRates` (F-KLR-004).**

F-KLR-001 reads: "Abrechnungsläufe MÜSSEN je Periode versioniert sein (draft → released);
**Auswertungen lesen nur released Läufe.**" Taken literally, `costAllocationSheet` and the new
`overheadRates` should refuse a run that is still `draft`.

The append-only fixture `core/parameter-effect` reads `costAllocationSheet` for a run it never
releases, and expects numbers back. So the contract, as it has always been exercised, says draft
runs *are* readable.

**Not resolved by bending the fixture.** Editing it would rewrite what the contract always said and
retroactively make every implementation that agreed with it wrong — the reason the suite is
append-only in the first place. Nor by enforcing the rule on the new projection only: two costing
projections with different rules about the same run is worse than one consistent rule.

**Built with the next most plausible behaviour:** both projections read a run in any status and
return `status` in the answer, so a caller can see which they got and decide. What is missing is the
*decision*, not the code — either the requirement means "the embedding application must only publish
released runs" (in which case it is an app obligation and F-KLR-001 should say so), or it means a
hard refusal (in which case a new fixture has to establish it and `parameter-effect` stays as the
record of what the contract used to allow). Left open deliberately; it is a question about intent,
and guessing it would be the same mistake in the other direction.

## SPEC-012: the shipped pack's manifest version cannot change — a fixture pins it

**Found 2026-08-23, while adding the `productionCost` module to the `de` and `us` packs.**

`pack/de-pack/de-pack-resolves` calls `resolvePack({ manifest: "de", version: "2026.2" })` and pins
`pack.version` in the tenant it then creates. Raising the manifest's own version therefore makes an
append-only fixture unresolvable — the pack the fixture asks for no longer exists.

The practical consequence is that **only module versions move**. Every pack change so far has bumped
the module (`de-afa` 2026.5 → 2026.6) and the manifest's *reference* to it, while the manifest's own
`version` has stood at 2026.2 through several rounds of real change. A consumer reading `pack.version`
— which `systemDescription` reports, and which is the field the "tzdata for accounting" idea rests on
— cannot tell those rounds apart.

**Not resolved by bending the fixture,** for the usual reason. What the contract needs is a decision:
either the manifest version is deliberately a *format* version and something else carries the content
version (then say so, and the field is fine as it is), or it is the content version (then the fixture
has to stop pinning an exact one — `resolvePack` would take the manifest name alone, and a *new*
fixture would establish that). Left open: guessing would either freeze the version forever or break a
published contract.

**Resolved 2026-08-23** (`fix/pack-version-immutability`). The second reading was the right one: the
manifest version *is* the content version, so the fixture had to stop pinning it. Investigating it
also made the finding worse than written above — a published `(id, version)` was not merely lagging,
it was **not immutable**. `de@2026.2` named at least three different bundles, because the module
files it referenced were overwritten rather than versioned alongside. The fix is in four parts:

1. `de-pack-resolves` is **superseded**, not edited (`testing/testsuite/superseded.json`), by
   `de-pack-resolves-current`, which pins the behaviour and leaves the product's numbers alone. Same
   for `us` and `default`. What the retired fixtures pinned about the mechanism moved to
   `xx-6-pack-version-pinning`, which brings its own pack and is therefore frozen for good.
2. `PackResolver::findManifest` is now the single place that selects a manifest — runner and CLI both
   call it — and a request without a version resolves to the **highest** version, not the first
   match, so several versions of one pack can live in the library side by side.
3. `resolvePack` returns a derived `contentDigest` (SHA-256 over the canonical JSON of the whole
   resolution, byte-identical in both languages), and a tenant carries it. It is what a hand-written
   version cannot be: impossible to forget.
4. `de` and `us` moved to `2026.3`, and `PackVersionIdentityTest` / `pack-version-identity` refuse two
   files claiming the same published identity.

Deliberately **not** done: no fabricated history. `de@2026.2` is gone rather than reconstructed from
today's modules, because a frozen file claiming to be the old bundle would be a second lie on top of
the first. Immutability starts here.

## SPEC-013: a shipped pack's chart of accounts cannot grow — a fixture pins its size

**Found 2026-08-23, while adding the exempt-export tax code to the `de` pack.**

`pack/de-pack/de-pack-resolves` pins `accountCount: 41` on the tenant it creates. Adding an account
to `de-konten` therefore breaks an append-only fixture — the same shape as [SPEC-012], where the
manifest's own version is pinned and so cannot move.

The concrete cost here: `AUSFUHR` (§ 4 Nr. 1 Buchst. a, Kz 43) reports an exempt export, and the
German chart has an account for exempt *intra-community supplies* (4030) and none for exempt
*exports*. A user has to add one with `createAccount`, which the fixture `de-ausfuhr` demonstrates
because it is what actually has to happen today. It works, but a shipped pack that cannot gain an
account as its tax codes gain coverage will keep accumulating that kind of hole.

**Not resolved by bending the fixture.** The decision it needs is the same one SPEC-012 needs, and
probably the same answer: what a fixture may pin about a *shipped* pack. Pinning the resolver's
behaviour is the point of `de-pack-resolves`; pinning the size of a product catalogue that is
expected to grow is a different thing that came along for the ride. Left open — a new fixture could
establish the resolver contract without the count, but deciding that is not a mechanical change.

**Resolved 2026-08-23** together with [SPEC-012] — it was the same defect, and the guess in the last
paragraph was right: the two needed one decision, not two. `de-pack-resolves-current` pins that the
pack resolves, that a tenant is built from it and that a standard VAT sale comes out right; it pins
neither the version nor the account count. The chart can grow again, which unblocks the missing
exempt-export account and the operating-expense accounts the embedding app asked for.

## SPEC-014: summae has no way to evolve a shipped database schema

**Found 2026-08-23, while scoping the costing persistence port.**

Both adapters create their tables exactly once, at workspace initialisation —
`SchemaInstaller::create` in PHP, `installSchema` in Node — and nothing upgrades an existing
database. PHP at least sits behind Laravel migrations, so a second dated migration file could add a
table; Node has no migration concept at all, and an existing SQLite workspace would simply lack the
new table with no path to gain it.

Nothing has needed this yet: the eight tables have been enough since 0.2.0. The costing port is the
first change that adds a table, which is why the question surfaces there and why it is the *actual*
blocker — the port, the adapters and the table itself are mechanical work.

**Three answers are defensible and they are not equivalent:**

1. **Versioned migrations in both languages.** Honest and conventional, and a subsystem of its own —
   Node would need a migration runner it does not have.
2. **Idempotent schema install.** `create` becomes "ensure", guarded per table, run on open rather
   than on init. Cheap, covers additive changes, and covers nothing else — a column that changes
   type still has no path.
3. **Frozen for 0.x.** Say plainly that a schema change means recreating the workspace, and hold the
   line until 1.0. Defensible for a 0.x library and the only one of the three that costs nothing —
   but it means the costing port waits.

Left open deliberately. Whichever is chosen becomes a promise to every existing installation, and
that is not a mechanical decision.
