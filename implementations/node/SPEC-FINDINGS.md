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
