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

## NF-002 — `format.schema.json` `mappingPosition` omits `includeNonCash`

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
