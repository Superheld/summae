# SPEC-FINDINGS (Node)

Documented contradictions between spec / fixture / model (root `CLAUDE.md`:
"don't guess, don't bend the fixture, but document and build on with the
next-most-plausible behavior").

## NF-001 — Pack draft fixture `tenant-from-de-complete`: `defaults` missing in the manifest

**Finding (2026-06-21, Gate-1 pack conformance).** The draft fixture
`testsuite/fixtures/pack/de-composed-equals-de/tenant-from-de-complete-posts-identically.json`
expects `createTenant.result.taxationMethod = "cash"`, but its manifest `de-mini-regression`
carried **no** `defaults` object — neither the manifest nor the modules encode
`taxationMethod` anywhere. By design the resolver derives `defaults`
(`module-manifest-resolver.md` § 2/§ 4.1) exclusively from the manifest; without
`defaults` the engine default `accrual` ≠ the expected `cash` kicks in.

**Assessment.** Authoring gap in an **explicitly non-normative draft fixture**
("DRAFT, not normative; only proceeds after human approval"). The fixture mirrors
`core/create-tenant-profile`, whose profile carries
`defaults: {taxationMethod: cash, smallBusiness: false, vatPeriod: quarterly}` —
the manifest had simply left out this adoption. The resolver is correct.

**Resolution.** Since the correct value is unambiguous (mirrored `create-tenant-profile`
+ design § 2) and it is a pre-freeze draft, the draft was completed rather than
bent: `defaults: {taxationMethod: "cash", smallBusiness: false, vatPeriod: "quarterly"}`
added — in both manifest copies (`tenant-from-…` **and** `resolve-de-complete-…`,
pinning consistency `de-mini-regression@2026.1`), at the source (internal source) and the mirror.
Also applies to the PHP side (shared fixture).

## NF-002 — `format.schema.json` `mappingPosition` omits `includeNonCash` — ✅ schema extended

> **Resolved (2026-06-23):** `$defs/mappingPosition` now declares
> `includeNonCash` (`{ "type": "boolean" }`) — the schema matches the engine.
> **Still open (separate question):** pack-library JSON is not validated against
> the schema at all (only journalExport streams + manifest are). Whether to add
> schema validation for the pack-library (the third-party extension surface) is its
> own decision — this drift slipping through unnoticed is the argument for it.

**Finding (2026-06-23, us-pack build).** The cash-basis projection reads a
position-level flag `includeNonCash` off the mapping leaf
(`policies/projection/mapping/mapping.ts:82` → `cash-basis.ts` R7: non-cash
categories like depreciation count without a cash flow). The us-pack module 5
(`us-schedule-c-2026`, kind `cash-basis-categories`) sets `includeNonCash: true`
on its depreciation line (L13) per the module spec. But the normative
`testsuite/schema/format.schema.json` `$defs/mappingPosition` does **not** declare
`includeNonCash` and carries `additionalProperties: false` — so by the schema the
field is illegal on a mapping position.

**Assessment.** A schema-vs-engine gap, not currently breaking: pack-library JSON
is loaded content-based (`JSON.parse` → resolver), never validated against
`format.schema.json`. `validate.py` explicitly skips module/pack files; the schema
test validates only the journalExport streams + manifest. So `us-schedule-c.json`
resolves and runs green in both languages. The gap would only bite if schema
validation is ever extended to pack modules. The de-pack never shipped an
EÜR/cash-basis mapping, so this is the first shipped `cash-basis-categories` module
and the first time the gap surfaces.

**Resolution.** Shipped `us-schedule-c-2026` with `includeNonCash: true` per the
module spec and the engine that consumes it (do not bend the data to a schema the
loader does not enforce). **Proposal:** extend `$defs/mappingPosition` with
`"includeNonCash": { "type": "boolean" }` (meaningful only for
`cash-basis-categories`) so the normative schema matches the engine before any move
to schema-validate pack modules. Shared schema artifact — applies to PHP too.

## NF-003 — `cashBasisReport` hard-codes a German VAT-passthrough treatment — ✅ resolved

> **Resolved (2026-06-24):** the hard-coded German strings are gone from the core.
> Tax accounts now flow through the cash-basis result **only where the pack's mapping
> maps them**, taking the label from the mapping leaf; an unmapped tax account is a
> neutral pass-through. DE: the `de-euer` mapping maps its VAT accounts (E3 "Vereinnahmte
> USt", A6 "Gezahlte Vorsteuer") → German labels from the **pack**. US: `us-schedule-c`
> leaves sales tax unmapped → neutral (now a realistic SALETAX sale in the fixture). A
> regression guard (`SubstrateBoundaryTest` / `no-jurisdiction-text.test.ts`) fails if such
> German label text reappears in the core. (The remaining mechanism-name branching —
> `reverse_charge` etc. — is the separate, documented closed/open matter.)

**Finding (2026-06-24, us-pack conformance audit).** The cash-basis projection
(`policies/projection/cash-basis.ts`) routes tax accounts by subtype with **hard-coded
German labels**: `tax_out` → income `"Vereinnahmte USt"` / expense `"USt-Zahlung an FA"`,
`tax_in` → `"Gezahlte Vorsteuer"`. This is the German EÜR rule (VAT flows through the
profit calculation). For the US, sales tax is a **pure pass-through** (held in trust for the
state, never income). With `2100 Sales Tax Payable` correctly marked `tax_out` (needed by
`vatReturn`, NF-… below), a SALETAX cash sale would have its collected tax counted as
income under the German label — wrong for US.

**Assessment.** The tax treatment is **pack/jurisdiction-specific**, but the engine hard-codes
the DE variant. Same family as the journalExport German-output finding. Not fixable from pack
data (it is engine behavior).

**Resolution / workaround.** `us-schedule-c` posts its sample revenue **tax-free** so the
projection's mechanism (mapping labels + `includeNonCash`) is proven without tripping the
DE-centric tax path. **Proposal:** make the cash-basis tax treatment pack-appropriate — e.g.
neutral pass-through unless the pack's cash-basis mapping explicitly maps the tax accounts
(would also drop the hard-coded German strings). Behavior change with DE-fixture ripple →
own job, human decision. Applies to PHP too.

## NF-004 — `EXEMPT` (rate-0 standard) cannot be posted: 0.00 tax line rejected

**Finding (2026-06-24).** The us-pack `EXEMPT` code (mechanism `standard`, rate `0.00`)
emits a 0.00 tax line on the tax account. `expandTax` returns it fine (proven by
`us-exempt-sale`), but **`postVoucher`/`post` reject it** with `E_ENTRY_INVALID_AMOUNT`
(a zero-amount entry line). So exempt sales **cannot be recorded in the journal** with the
EXEMPT code today — only previewed via `expandTax`. Consequently they also cannot appear in
the sales-tax return (`us-sales-tax-return` covers the taxable line only).

**Assessment.** Confirms open-decision E has teeth — the 0.00 line is not merely cosmetic,
it blocks real bookkeeping of exempt sales. The de-pack avoids this for the analogous
intra-community supply via a dedicated `intra_community_supply` mechanism (base tag only, no
0.00 line). **Proposal:** add an `exempt` mechanism (tag the base, emit no tax line), then
exempt sales post cleanly and show up in the return. Engine addition → own job. Applies to PHP too.

## NF-005 — Cash-basis VAT: the reversal of an *unsettled* open item counts immediately

**Finding (2026-08-14, CLI walkthrough for the handbook).** With
`taxProfile.taxationMethod = "cash"` the VAT return has two paths
(`vat-return.ts:60-80`, PHP `VatReturnProjection.php:72-112`): entries **with**
an open item contribute per settlement, entries **without** one contribute
directly at their posting date (`if (this.openItems.byOriginEntry(entry.id).length > 0) continue;`).

A reversal creates no open item of its own. So reversing an unpaid incoming
invoice puts the *full* negative input tax into the return, while the positive
original still waits for a payment that will now never come:

```
postVoucher ER-001  VSt19  net 200.00  →  open item payable 238.00, unsettled → contributes 0
reverse     ER-001                     →  no open item                        → contributes −200.00 / −38.00
report vatReturn {"year":2026,"quarter":1}
  → keys.66 = { base: "-200.00", tax: "-38.00" }
```

The tenant claims a 38.00 input-tax refund for an invoice that was never paid
and then cancelled. Netting to zero would be the expected result.

**Assessment.** Not a language defect — **PHP and Node are byte-identical here**
(same two-path structure, same `byOriginEntry` skip), so cross-language
equivalence holds; this is a **model** question. The accrual path has an explicit
rule for exactly this case (`F-011`: "a tax correction counts by its own posting
date"); the cash path has no counterpart. Plausible fix directions: (a) a
reversal inherits the settlement state of the entry it reverses, (b) reversing
an entry that carries an unsettled open item also cancels that open item
proportionally, (c) the second loop skips entries whose `reverses` target has an
open item. Which one is correct is a **spec decision**, not an implementation
detail — no fixture pins the case today.

**Resolution.** Documented, not changed. Needs a normative fixture in the
knowledge base before any implementation moves. Applies to PHP too.

## NF-006 — `cashBasisReport` without `year` raises an uncaught `InvalidValue`

**Finding (2026-08-14, CLI walkthrough for the handbook).** `CashBasisProjection.compute`
defaults a missing/mistyped `year` to `0` (`cash-basis.ts:43`, PHP `CashBasisProjection.php:63`)
and then builds `CalendarDate.of("0000-01-01")` in `assertCalendarYearFiscalYears`.
`CalendarDate` rejects year 0, so the caller gets a raw `InvalidValue` — **not** a
`DomainError`. Via the CLI that means a stack trace on stderr instead of the
documented `{"error", "message", "details"}` line, and exit code 1 instead of a
catalogued code:

```
summae report cashBasisReport --params '{"fiscalYear":2026}'   # note: wrong param name
  → InvalidValue: Not a valid calendar date: "0000-01-01"      (stack trace, non-JSON stdout)
```

The trigger is realistic: every other projection except `vatReturn` takes
`fiscalYear`, so passing `fiscalYear` here is the natural mistake.

**Assessment.** Contract violation of the CLI's own output guarantee ("stdout is
always one line of JSON, errors carry an `E_*` code"), and the failure mode is
worst for automated callers — an agent parsing stdout gets garbage rather than a
branchable error. The error catalogue has no code for "required projection
parameter missing"; the closest existing behaviour is that projections silently
return empty results for a wrong `fiscalYear`, which is its own problem. Same
code path in both languages.

**Resolution.** Documented, not changed. Needs a decision on how missing
projection parameters report at all (new `E_*` code vs. reusing an existing one)
before either language moves. Applies to PHP too.

## NF-007 — A missing mapping reports `E_MAPPING_OVERLAP`

**Finding (2026-08-14, walkthrough scenarios).** `incomeStatement` and `balanceSheet`
require a `mapping` parameter. When it is absent or names a mapping the tenant has
not loaded, both raise `E_MAPPING_OVERLAP` — a code whose name says the opposite of
what happened (`income-statement.ts:30`, `balance-sheet.ts:32`; PHP
`IncomeStatementProjection.php:46`, `BalanceSheetProjection.php:49`):

```
summae report incomeStatement --params '{"fiscalYear":2026}'      # default pack, no mapping module
  → {"error":"E_MAPPING_OVERLAP","message":"Mapping \"\" is not loaded"}
```

The empty `""` in the message shows the second half: a *missing* parameter is not
distinguished from an *unknown* one.

**Assessment.** The error catalogue has no code for "required projection parameter
missing" and none for "mapping unknown"; `E_MAPPING_OVERLAP` is documented as the
overlap error of `importMapping`. Reusing it here makes an operator debug the wrong
thing — and it is the *only* error a tax-free configuration (default pack) hits on a
normal report. Identical in both languages, so no parity issue.

**Resolution.** Documented, not changed; the walkthrough scenario `default.json`
pins the current behaviour so a future fix is a visible, deliberate change. A new
code (`E_MAPPING_UNKNOWN`) would be an append to the error catalogue and therefore
to the exit-code table — catalogue changes are append-only, so this needs a spec
decision first. Applies to PHP too.
