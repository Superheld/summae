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
