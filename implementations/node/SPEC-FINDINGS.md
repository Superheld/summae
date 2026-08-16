# SPEC-FINDINGS (Node)

Documented contradictions between spec / fixture / model (root `CLAUDE.md`:
"don't guess, don't bend the fixture, but document and build on with the
next-most-plausible behavior").

## Status at a glance

Re-verified against the code on 2026-08-15. PHP counterparts and the older `F-…` findings:
`implementations/php/SPEC-FINDINGS.md` (that file carries the shared status table).

| Finding | Status |
|---|---|
| NF-001 pack draft fixture `defaults` | ✅ draft completed at the source |
| NF-002 `includeNonCash` missing from the schema | ✅ schema extended |
| NF-003 `cashBasisReport` German VAT passthrough | ✅ resolved |
| NF-004 `EXEMPT` cannot be posted | ✅ `exempt` mechanism (0.5.0) |
| NF-005 cash-basis reversal counts immediately | ✅ fixed 2026-08-15 — **remainder open**: settled-then-reversed |
| NF-006 `cashBasisReport` without `year` crashes | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| NF-007 missing mapping reports `E_MAPPING_OVERLAP` | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| NF-008 reversal leaves open items standing | **OPEN** — needs a data-format decision |
| NF-009 `CalendarDate` years 0000–0099 diverged PHP vs. Node | ✅ fixed 2026-08-15 — host `Date` removed from the substrate |
| NF-010 `Money.of` accepted amounts the data format forbids | ✅ fixed 2026-08-15 — `1.5e+21` was bookable; `+10.00` also diverged |
| NF-011 `post` accepted a fabricated `taxTag` into the VAT return | ✅ fixed 2026-08-15 |
| NF-012 `balanceSheet` silently ignored `fiscalYear` | ✅ fixed 2026-08-15 |
| NF-013 a wrong `direction` booked an incoming invoice inverted | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| NF-016 four declared parameters that no implementation reads | **OPEN** — declared `acceptedWithoutEffect`, needs a decision per parameter |
| **`E_INPUT_INVALID` added to the catalogue** | exit code 45 — ✅ catalogue entry written in the knowledge base |

**No findings are open today.** Four closed on 2026-08-16, all written up on the PHP side —
including **NF-015**, which turned out to matter here too: giving the persistence adapters their own
suites showed that every `byId`, `byOriginEntry` and `save` in **both** `packages/knex` and PHP's
`packages/laravel` ignored `tenant_id`, so a repository built for one tenant handed out and wrote
over another's rows by primary key. Same lines, same fix, mirrored suite in
`packages/knex/test/adapter.test.ts`. The others: **F-004** — `poolYears` is
depreciation-module data now, and `asset-service.ts` refuses a pool range that omits it instead of
falling back to five years. **NF-008** — `reverse` clears the open items of the reversed entry
(`cause: "cancellation"` → status `cancelled`) and refuses outright once one is settled
(`E_ENTRY_HAS_SETTLED_ITEMS`), the line SAP draws with F5308; `vat-return.ts` skips cancellation
settlements, without which reversing an unpaid invoice would have declared cash-basis VAT for money
that never arrived. **The NF-005 remainder** falls out of that: settled-then-reversed can no longer
happen. The "Round 1 backlog" at the end of this file is closed in full as of 2026-08-16.

## NF-001 — Pack draft fixture `tenant-from-de-complete`: `defaults` missing in the manifest — ✅ RESOLVED

**Finding (2026-06-21, Gate-1 pack conformance).** The draft fixture
`testing/testsuite/fixtures/pack/de-composed-equals-de/tenant-from-de-complete-posts-identically.json`
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
`testing/testsuite/schema/format.schema.json` `$defs/mappingPosition` does **not** declare
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

## NF-004 — `EXEMPT` (rate-0 standard) cannot be posted: 0.00 tax line rejected — ✅ RESOLVED

> **Resolved in 0.5.0 (2026-06-24).** The proposal below was built: `exempt` is now a
> registered tax mechanism (`tax-mechanisms.ts`) that tags the base and emits **no** tax
> line, and the us-pack `EXEMPT` code selects it. Exempt sales post cleanly and appear in
> the return. Pinned by the conformance fixtures and by the `us` walkthrough scenario
> (`testing/scenarios/walkthrough/us.json`). Original finding kept for the record:

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

## NF-005 — Cash-basis VAT: the reversal of an *unsettled* open item counts immediately — ✅ FIXED

> **Fixed 2026-08-15 (both languages, `vat-return.ts` / `VatReturnProjection.php`).** The
> direct-contribution loop now also skips an entry that *reverses* an entry carrying open
> items. Its premise is "no open item ⇒ the money moved at posting time"; a reversal has no
> open item of its own but is not a cash movement either, so its tax follows the reversed
> entry's settlements instead. Reversals of genuinely cash-effective entries (target without
> open items) still count directly, at their own posting date — unchanged.
>
> Chosen because it is the part **both** candidate semantics agree on: an invoice that was
> never paid and then reversed contributes nothing either way. Pinned by the `de` walkthrough
> scenario (`testing/scenarios/walkthrough/de.json`, VAT-return step: key `66` must be
> absent); verified to fail without the fix. All 86 fixtures stay green in both languages,
> SF-15 cross-test 45/45 both directions — nothing existing pinned this case.
>
> **Still open (narrower than the original finding):** an invoice that *was* settled and is
> then reversed. The fix leaves the tax declared until a refund is posted ("follow the
> money"); the alternative corrects it at the reversal's own date (the §17 / F-011 reading
> used on the accrual path). No fixture pins either. Needs a normative fixture in the
> knowledge base before the behaviour is relied upon. Original finding:

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

## NF-008 — A reversal leaves the reversed entry's open items standing

**Finding (2026-08-15, while fixing NF-005).** `reverse` posts a full counter-entry but does
**not** touch the open items the reversed entry created. The two views of the same fact then
contradict each other:

```
postVoucher ER-1  VSt19  net 200.00, counterAccount 3000 (ap)  → open item payable 238.00
reverse     ER-1
report trialBalance → account 3000: balance "0.00"          (the liability is gone)
report openItems    → payable 238.00, remaining 238.00, status "open"   (it is not)
```

The ledger says the debt does not exist; the open-item list still offers it for settlement. It
can be settled against a payment that has no economic basis, and it inflates any
receivables/payables ageing built on `openItems`.

**Assessment.** Distinct from [NF-005] and *not* its cause — fixing this alone would not have
changed the VAT return, and fixing NF-005 does not clear the stale item. Identical in both
languages. The plausible behaviour is that a reversal cancels the open items of the entry it
reverses while **keeping their settlement history** (any settlements already made must keep
contributing to past VAT returns — silently dropping them would rewrite filed periods).

Whether a cancelled open item disappears from `openItems` or shows a distinct terminal status
(`cancelled` alongside `settled`) is a **data-format decision**: `status` is part of the
serialized shape, so a new value is an append to the format. Needs a spec decision plus a
normative fixture.

**Resolution.** Documented, not changed.

## NF-009 — `CalendarDate` accepted years 0000–0099 in PHP and rejected them in Node — ✅ FIXED

**Finding (2026-08-15, probing the CLI error boundary).** The same string was valid in one
engine and invalid in the other, in the **substrate** — the layer that is meant to be frozen
and is bound by the top quality policy:

| input | PHP | Node (before) |
|---|---|---|
| `0000-01-01` | accepted | `InvalidValue` |
| `0001-01-01` | accepted | `InvalidValue` |
| `0099-12-31` | accepted | `InvalidValue` |

**Cause.** Node validated by round-tripping through `new Date(Date.UTC(year, month-1, day))`.
`Date.UTC` maps years 0–99 onto **1900+year** (a JavaScript legacy rule), so `0000-01-01`
became `1900-01-01`, the round-trip failed, and the date was rejected. The boundary was not a
design decision — it was a host quirk leaking into a value object. `lastDayOfMonth()` and
`firstDayOfNextMonth()` used the same helper and were wrong in the same band.

How it surfaced: `cashBasisReport` defaults a missing `year` to `0` and builds `0000-01-01`
(NF-006), which crashed in Node and returned an empty report in PHP. The crash was hiding a
divergence.

**Resolution.** Node's `CalendarDate` no longer uses the host `Date` at all — validation and
month arithmetic are done with an explicit days-per-month table and the Gregorian leap rule
(`calendar-date.ts`). This **widens** Node to match PHP (nothing that was valid became
invalid), and it removes the whole class of `Date`-quirk divergences from the substrate.

Verified identical in both languages across the accepted/rejected tables and the month
arithmetic, including `0050-02-29` (year 50 is not a leap year), `0100-02-29`, `1900-02-29`
and the December rollover. Pinned by `calendar-date.test.ts` / `CalendarDateTest.php`, which
carry the **same tables** — 34 cases each. All 86 fixtures green in both languages against
both subjects, SF-15 cross-test 45/45 both directions.

**Note.** This does **not** resolve NF-006: with the divergence gone, both engines now return
an empty cash-basis report for a missing `year` instead of one crashing. Consistent, still a
silent wrong answer — the missing-parameter question stands.

## NF-010 — `Money.of` accepted amounts the data format forbids — ✅ FIXED

**Finding (2026-08-15, probing malformed write input).** `Money.of` validated by handing the
string to the decimal library (`big.js` / `brick/math`) and checking the resulting scale. The
libraries parse considerably more than the **data format** allows
(`format.schema.json` `$defs/money/properties/amount` = `^-?\d+(\.\d{1,4})?$`), so amounts that
can never be exported were accepted into the journal:

| amount | before | data format |
|---|---|---|
| `"1e3"` | booked as `1000.00` | invalid |
| `"1E3"` | booked as `1000.00` | invalid |
| `"1.5e+21"` | **booked as `1500000000000000000000.00`** | invalid |
| `"10."` | booked as `10.00` | invalid |
| `".5"` | booked as `0.50` | invalid |
| `"+10.00"` | **PHP: `10.00` · Node: rejected** | invalid |

Two defects in one. **Silent acceptance:** an app that formats amounts with
`String(number)` emits exponent notation for very large or very small values, and 1.5
sextillion euros went into the journal without complaint. **Cross-language divergence:** a
leading `+` was accepted by PHP and rejected by Node — the same class as [NF-009], again in the
substrate.

**Resolution.** `Money.of` now checks the string against the data-format expression *before*
handing it to the decimal library, in both languages, with the expression written out in each.
`Money.fromCalculation` is untouched — it takes a decimal value, not a user string, and is the
only path on which Money rounds.

The schema was the arbiter here, not taste: it already declared the format, and both engines
were simply not enforcing what the exported data promises. Pinned by the same accepted/rejected
tables in `money.test.ts` and `MoneyTest.php`. All 86 fixtures green in both languages against
both subjects, SF-15 cross 45/45 both directions — no fixture used any of the loose forms.

## NF-011 — `post` accepted a caller-fabricated `taxTag` straight into the VAT return — ✅ FIXED

**Finding (2026-08-15, adversarial probing of the write path).** `postVoucher` builds tax tags
through the `TaxCodeRegistry`, so a wrong code is `E_TAXCODE_UNKNOWN`. The direct `post` path
took whatever the caller supplied (`ledger.ts:577` / `Ledger.php:706`: `isRecord(rawLine.taxTag)
? rawLine.taxTag : null`) — no registry lookup, no check of `reportingKey`, `appliedVersion` or
`baseMoney`. Stored verbatim, and the VAT return is built **from these tags, never from account
numbers**:

```
op post --input '{… "taxTag":{"code":"MADEUP","reportingKey":"4711","baseMoney":{"amount":"999999.00", …}}}'
  → exit 0
report vatReturn --params '{"year":2026,"quarter":0}'
  → {"keys":{"4711":{"base":"-1.00","tax":"0.00"}, …}}
```

An invented reporting key and an unregistered tax code became line items of a statutory return,
at exit 0. The sharpest hole found in this round.

**Resolution.** A caller-supplied tag whose `code` is a non-empty string must resolve in the
`TaxCodeRegistry`; otherwise `E_TAXCODE_UNKNOWN` (the existing catalogue code — no append). Tags
built internally by the tax expansion come from the registry and pass unchanged; `reverse`
copies `EntryLine` objects and never goes through this path. The registry had to be wired into
the ledger in both languages; PHP needed it in **two** places, because
`DatabaseTenantFactory.php` duplicates the ledger construction that `Tenant.php` also does —
Node has a single construction path. That duplication is worth removing separately.

Pinned by `testing/scenarios/walkthrough/regressions.json` (fabricated code rejected with
exit 32; a tag naming a *registered* code still posts, so the guard checks the registry rather
than forbidding tags).

## NF-012 — `balanceSheet` silently ignored `fiscalYear` — ✅ FIXED

**Finding (2026-08-15, probing the read path).** `balance-sheet.ts` read only `asOf` and
`mapping`. `fiscalYear` was accepted and discarded, so **two different years returned
byte-identical balance sheets** over a two-year journal. The handbook cheat sheet lists
`balanceSheet` in the `fiscalYear` row, and — worse — the gated walkthrough scenarios pass
`fiscalYear` to it (`de.json`, `us.json`), so the documentation endorsed a parameter that did
nothing. The gate could not catch it: every scenario had exactly one fiscal year.

**Resolution.** `fiscalYear` now scopes the projection **cumulatively** — everything up to and
including that year, i.e. "as at the end of fiscal year N".

The first attempt mirrored `trialBalance` (income accounts restart each year, G1) and produced
a sheet that **did not balance** in year two: assets 3570.00 against equity+liabilities 2570.00,
a hole exactly the size of the prior year's result. That is correct behaviour for a trial
balance and wrong for a balance sheet, because summae deliberately writes **no closing entries**
(`closeFiscalYear` is a pure status change), so a prior year's result was never carried into
equity. Cumulative scoping keeps assets == liabilities+equity in every year, and for a system
without closing entries the cumulative result *is* the equity delta.

Pinned by `regressions.json`, the first scenario that spans two fiscal years — 2026 → 1190.00,
2027 → 3570.00, both balancing.

## E_INPUT_INVALID — new catalogue code (exit 45)

**Added 2026-08-15.** A supplied parameter or field is **present but not valid input** — a caller
mistake, not an internal failure. Before it, such situations either escaped as an uncaught
`InvalidValue` (a stack trace, and since the CLI error boundary an `E_UNEXPECTED`/exit 1 that an
agent cannot tell apart from a summae bug) or were silently coerced into a plausible default.

Appended to both exit-code tables, so no existing exit code moved. In use at:
`tax-service` (`direction`), `cash-basis` (`year`, unknown `mapping`), `account-sheet`
(`account`, `fiscalYear`), `income-statement` and `balance-sheet` (missing/unknown `mapping`).

**Normative source: done.** The catalogue entry was written in the knowledge base
(`50-spezifikation/fehlerkatalog.md`, section `E_INPUT` with the delimitation rule: where a more
specific code exists it wins — an unknown account stays `E_ACCOUNT_UNKNOWN`, an unknown tax code
stays `E_TAXCODE_UNKNOWN`), and the conformance fixture `core/input-invalid.json` covers all three
forms plus two positive cases, mirrored here via `make sync`. Green in both languages on the first
run (87/87 `--strict`).

## NF-013 — a wrong `direction` booked an incoming invoice fully inverted — ✅ FIXED

**Finding (2026-08-15, adversarial probing).** `tax-service.ts:56` read
`input.direction === 'input' ? 'input' : 'output'`. Anything that was not exactly `"input"` —
`"Input"` with a capital I, `"INPUT"`, a typo, `null` — silently became an **output** voucher:

```
postVoucher … "taxCode":"VSt19","direction":"Input","netLines":[{"account":"6000", "200.00"}],"counterAccount":"3000"
  → exit 0, lines: 3000 debit 238.00 · 6000 credit 200.00 · 1500 credit 38.00
```

The exact mirror of the correct booking: the expense credited, the input VAT credited, the
payable debited. The lines carry a valid `taxTag`, so nothing downstream flags it, and the
posting looks entirely ordinary in every report.

**Resolution.** An absent `direction` still defaults to `"output"` (documented); a **wrong**
value is `E_INPUT_INVALID`. Pinned in `testing/scenarios/regression/regressions.json` together with both
positive cases — the default still works, and lower-case `"input"` still books the right way
round.

## NF-016 — four declared parameters that no implementation reads

**Finding (2026-08-15, while implementing the projection parameter contract).** Building the
contract made visible what a tolerant reader had hidden: four parameters are passed by existing
fixtures and read by nobody. They are declared in
`testing/testsuite/schema/api-parameters.json` with `acceptedWithoutEffect: true`, which is what
keeps those fixtures green now that an undeclared parameter is rejected.

| Projection | Parameter | What a caller would expect |
|---|---|---|
| `balanceSheet` | `incomeMapping` | the mapping used for the result position — today the sheet uses only `mapping` |
| `journalExport` | `format` | a choice of output format — today there is exactly one (GoBD Z3) |
| `costAllocationSheet` | `fiscalYear` | scoping the sheet to a year — today only `runId` selects |
| `costAllocationSheet` | `period` | scoping the sheet to a period — same |

**Assessment.** The flag is a marker, not a feature: it says "declared so it is visible", not
"works". Each is a small trap of its own kind — a caller who passes `format: "csv"` gets Z3 and no
word about it. The honest fix is one of two things per parameter, and both are decisions rather
than code: **implement** it, or **retire** it in a new fixture (fixtures are append-only, so the
existing ones are not bent). Deliberately left as-is here; the flag is what keeps the gap
readable in the meantime.

## Round 1 backlog — probed, confirmed, and closed (2026-08-16)

Found by two adversarial probing agents on 2026-08-15 and reproduced by hand before being
recorded. All twelve are closed: R-5 … R-7 on 2026-08-15, the remaining nine on 2026-08-16. Each
carries a fixture or a CLI test that fails loudly if it comes back.

| # | Defect | Class |
|---|---|---|
| R-1 | `settle` accepts an allocation larger than the settling entry actually books: GL keeps a 690.00 receivable while the subledger is empty, and cash-basis VAT declares 190.00 collected when 500.00 arrived | ✅ fixed 2026-08-16 — `E_SETTLEMENT_EXCEEDS_ENTRY` + fixture `settlement-bound` |
| R-2 | `auditDataExport` carries P&L accounts across fiscal years, `trialBalance` does not — the ADS balance stream and the trial balance disagree per account (identical in both languages, so byte-parity cannot catch it) | ✅ fixed 2026-08-16 — income accounts scoped per fiscal year (`audit-data-export-fiscal-year`) |
| R-3 | `correct` rewrites an entry's lines and leaves the open item it created untouched (same family as NF-008) | ✅ fixed 2026-08-16 — `E_ENTRY_HAS_OPEN_ITEMS` + fixture `correct-open-items` |
| R-4 | `importMapping` reports `imported: true` but the CLI rebuilds the registry from `summae.json` on every invocation and never writes back — the documented import→report flow cannot work | ✅ fixed 2026-08-16 — the import is written back into the workspace (CLI tests) |
| R-5 | `createFiscalYear` coerces a non-numeric `year` to 0 and creates the year anyway; `2027.5` and `-5` are accepted too | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| R-6 | `correct` with a misspelled field is a silent no-op that returns success | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| R-7 | `openItems` ignores an invalid `kind` and returns everything; `datevExport` returns the entries export under a bogus `kind` label | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| R-8 | `init` is not atomic: a failure after the workspace is written leaves a half-built, non-re-initialisable directory. `--first-fiscal-year` is not validated (`""` → year 0000) | ✅ fixed 2026-08-16 — validated year + rollback on failure (CLI tests) |
| R-9 | a corrupted-but-parseable `summae.json` silently yields an empty ledger, because `Workspace.tenant()` defaults every field and regenerates `tenantId` | ✅ fixed 2026-08-16 — `E_WORKSPACE_INVALID` instead of defaulting (CLI tests) |
| R-10 | `init --pack X --rules Y` silently drops `--rules`; the help calls them alternatives | ✅ fixed 2026-08-16 — `--pack` and `--rules` together are rejected (CLI tests) |
| R-11 | a 1–2 cent invoice with 19 % VAT is unbookable: the derived tax line rounds to 0.00 and is then rejected by the "amount > 0" rule | ✅ fixed 2026-08-16 — a zero tax line is dropped, not forced (`cent-invoice`) |
| R-12 | accounts outside the pack's mapping ranges vanish from `incomeStatement` while `balanceSheet`'s result position still counts them — the two reports then disagree | ✅ fixed 2026-08-15 via NF-014 (`_unassigned` + `gapWarnings[]`) |

Most of R-5 … R-7 are the same shape: `typeof x === 'T' ? x : <default>` used as validation, which
cannot tell "absent" from "wrong". With `E_INPUT_INVALID` available they were closed in one sweep
rather than patched individually.

**Resolution of R-5 … R-7 (2026-08-15).** Absent keeps its documented default everywhere — no
`kind` still means "no filter", no `datevExport.kind` still means `entries`. A *present* value that
is not a valid value is now `E_INPUT_INVALID` (exit 45): a `year` that is not a positive whole
number, an `openItems`/`datevExport` `kind` outside its enumeration, and a `correct` call that
carries neither `text` nor `lines` (which is what a misspelled `txt` amounts to). Pinned in
`testing/scenarios/regression/input-validation.json`, each rejection paired with a positive case so the
guards cannot forbid too much.

## NF-017 — an unmapped balance account made the balance sheet stop balancing

> **RESOLVED 2026-08-15** (fixture `balance-sheet-gap`, both languages).

**Finding (2026-08-15, while fixing NF-014).** `balanceSheet` iterates over the mapping's
**positions** and pulls the accounts each one matches. An account no position matches is therefore
never visited: its amount lands in neither total, and the sheet silently fails to balance.

```
accounts 1200 (bank, mapped) · 2600 (securities, NOT mapped) · 3000 (payables, mapped)
post 5000.00: 2600 debit / 3000 credit
report balanceSheet
  → assetsTotal "0.00"   liabilitiesAndEquityTotal "5000.00"     ← off by the whole amount
  → positions: only P.V — the asset is not in the report at all
```

This is worse than NF-014, where the two statements merely disagreed. Here a single statement is
internally inconsistent, and it is the statement whose defining property is that both sides match.

**What `by construction` actually covers.** api.md promises the balance-sheet identity "by
construction", and while fixing NF-014 I took that to mean the whole sheet. It does not. The
*result position* is by construction: it sums income accounts by TYPE, no mapping involved, so no
income account can escape it. The *asset and liability side* is a different mechanism — it is
mapping-driven, and its completeness was assumed rather than enforced.

**Resolution.** The same treatment as NF-014, which is also the one the error catalogue
prescribes: a catch-all position `_unassigned` per section plus `gapWarnings[]`. The section is
chosen by account **type** (asset → assets, everything else balance-carrying →
liabilitiesAndEquity), which is jurisdiction-free and always available; the mapping cannot answer
the question, since the whole problem is that it says nothing about this account.

The catch-all is deliberately not a licence to skip mapping work: `importMapping` still reports
every uncovered account at import time, and the position appears in the report whenever it carries
something, so the gap is visible rather than absorbed.

## NF-014 — accounts outside a mapping's ranges vanish from the income statement

> **RESOLVED 2026-08-15** (fixture `income-statement-gap`, both languages). The income statement
> now routes an unmapped account into the catch-all position `_unassigned` and reports
> `gapWarnings[]` naming the accounts that landed there — the treatment the error catalogue
> already prescribes ("Mapping-Lücken sind kein Fehler: gapWarnings[] + Auffangposition") and
> that `importMapping` already applied. The two statements agree again.
>
> `balanceSheet` was deliberately **not** changed. It sums income accounts by type, and that is
> precisely what makes the balance-sheet identity hold *by construction* (api.md); deriving its
> result position from a mapping instead would make the identity depend on the mapping being
> complete. Which also settles `balanceSheet { incomeMapping }`: it stays declared and without
> effect, because the position it would feed must not be mapping-dependent.
>
> The second case below — a depot at 1250 landing under bank balances — is **not** covered by
> this fix. It is a de-pack range question (`de-bilanz` maps 1200–1399 wholesale), not an engine
> one, and belongs to the pack.

**Finding (2026-08-15, answering "how many accounts can I create?").** There is no limit on the
number of accounts (546 created without complaint, `importChartOfAccounts` took 500 in one call)
and no assumption anywhere of a *single* bank account — several current accounts, call-money and
fixed-term deposits all work as `subtype: "bank"`. But a chart of accounts is only half the
story: **a mapping decides what a statement shows**, and an account outside every range is
silently dropped.

```
createAccount 7100 (expense) · post 300.00 to it
report incomeStatement --params '{"fiscalYear":2026,"mapping":"de-guv"}'
  → {"positions":[],"netIncome":"0.00"}        ← the expense is not there
report balanceSheet   --params '{"fiscalYear":2026,"mapping":"de-bilanz"}'
  → P.A2 Jahresergebnis "-300.00"              ← but it IS in the result
```

`de-guv` covers 4000–4099, 4900–4999, 5000–5999, 6000–6099, 6300–6399, 6500–6599, 6700–6999 —
everything else, including 4100–4899, 6100–6299 and anything ≥ 7000, is invisible to it. The
shipped de chart of accounts fits entirely inside those ranges, so only **custom** accounts are
affected. `balanceSheet` computes its result position from *all* non-balance-carrying accounts
regardless of mapping, which is why the two statements disagree rather than both being wrong.

A second, milder case: a securities depot placed at 1250 lands in "Kassenbestand und Guthaben bei
Kreditinstituten", because `de-bilanz` maps 1200–1399 wholesale — a bank-balance line, not a
financial asset.

**Assessment.** Two separate questions. (a) Should a projection *warn* about accounts no position
claims? `importMapping` already computes `gapWarnings`, so the machinery exists — surfacing it on
`incomeStatement`/`balanceSheet` would make the hole visible without changing any number.
(b) Should `balanceSheet`'s result position use the income-statement mapping instead of all
non-balance-carrying accounts, so the two cannot drift apart? (b) changes numbers and needs a
fixture. Documented, not changed.
