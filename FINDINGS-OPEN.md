# FINDINGS — open

Contradictions between spec / fixture / model, and defects found while building, that are **not
decided yet**. Root `CLAUDE.md`: "don't guess, don't bend the fixture, but document it and build on
with the next-most-plausible behaviour."

**This file holds only what is open, on purpose.** Somebody — or something — told to look at the
open findings should be able to read the whole register and carry nothing else. Everything decided
lives in [`FINDINGS-CLOSED.md`](FINDINGS-CLOSED.md) with its full reasoning and the status table
over both; that is what a code comment saying "see IMPL-025" resolves to, and it is not reading you
need in order to work on what is open.

> **Renamed 2026-08-28** from `SPEC-FINDINGS.md` / `SPEC-FINDINGS-RESOLVED.md`. Same register, same
> numbering, same split by state — the old names said "SPEC" while two of the three series in here
> are not about the spec at all, and a reader looking for open bugs had no reason to open a file
> named after a specification. The per-language pointers moved with them
> (`implementations/*/FINDINGS.md`). The **historical** knowledge-base register at
> `knowledge/80-implementierung/SPEC-FINDINGS.md` keeps its name deliberately: it is closed, its
> numbers are cited in commits, and it uses the same prefixes for *different* findings — renaming it
> would suggest the two are one register.

**Recording a finding:** append it here, in the series it belongs to — `SPEC-nnn` (spec / fixture /
model contradict each other) · `SPEC-Cnn` (cross-implementation) · `IMPL-nnn` (implementation
defect, including a requirement that is not built or not tested — root `CLAUDE.md`: "a requirement
without a test is itself a finding"). Numbers are never reused; the highest one ever issued is the
last row of the status table in the closed file. Which language you found it in is a sentence in the
entry, not a file — most of them turn out to concern both.

**Closing one:** move the whole block to `FINDINGS-CLOSED.md` and add its row to the status table
there. Moving it is the entire bookkeeping — the split is by *state*, and a state changes once. That
is also why this is not the old per-language split: that one duplicated the same text in two places
and drifted (SPEC-014 did, in the open).

**Two open domain questions are not findings and live elsewhere.** `RQ-1` (which VAT period a
reversal belongs to) and `RQ-2` (the euro rounding of the VAT base, which is not sum-preserving) are
in [`knowledge/40-domaenenmodell/offene-fragen.md`](knowledge/40-domaenenmodell/offene-fragen.md).
They are open in the strongest sense — shipped code runs on an unconfirmed reading of the law — but
they are questions for a human with the statute, not defects, and copying them here is exactly the
duplication this register was split to avoid.

## IMPL-043 — F-KLR-005 is covered by three fixtures about the one case it excludes

**Found 2026-08-28** while deciding IMPL-041.

`F-KLR-005` requires kalkulatorische Kosten to be carried as their own entries in the costing
circle, never in the financial-accounting journal. Three fixtures name it in `covers`:
`costing/production-cost`, `pack/de-pack/de-herstellungskosten` and
`pack/us-pack/us-inventory-costing`. All three are about **production cost** — and production cost
per § 255 Abs. 2 HGB is built from *Aufwendungen*, so kalkulatorische Kosten are the one thing that
may **not** be counted into it. The fixtures are correct about their own subject; they simply prove
the opposite case to the one the requirement states.

**What is actually true today:** summae has no kalkulatorische Kosten at all — see IMPL-041, where
the same gap shows from the other side (`Kosten == Aufwand`, because the Abgrenzungsrechnung that
would introduce them is not built). F-KLR-005's "never in the Fibu journal" is therefore satisfied
**vacuously**: nothing is in the journal because nothing exists. A requirement satisfied by the
absence of its own subject is not covered, it is unfalsifiable.

**Why this is its own entry and not part of IMPL-041.** It is a different defect. IMPL-041 is a
capability that was never built; this is a `covers` claim that does not hold, in a fixture that is
otherwise right. And it is the concrete demonstration of what the IMPL-039 guard **cannot** do:
that guard checks an ID is *declared*, which `F-KLR-005` is. Whether the fixture behind an ID proves
the requirement is not mechanically checkable, and pretending otherwise would be the more dangerous
outcome — a green guard reading as "every requirement is proven".

**What would close it.** Either F-KLR-005 loses the citations and joins F-KLR-002 as an
explicitly unbuilt requirement (the honest reading of the evidence), or its "never in the Fibu
journal" half is restated as what it really guards — that `runCosting` writes no journal entry —
and a fixture proves *that*, which is checkable today. **Built in the meantime: nothing**, and
deliberately: it is a decision about what the requirement means, and the same pass has already
decided two of those.

## IMPL-045 — the personal-data inventory stops at the exchange format

**Found 2026-08-29** while reviewing what the HGB build pass left in the three censuses.

§1 of `docs/gdpr-conformance.md` is *"the inventory an Art. 30 record needs"*, and
`personalDataDescription` publishes the same list as a projection. Both enumerate seven fields, and
every one of them is a record the **exchange format** declares (`partner`, `voucher`, `journalEntry`,
`auditRecord`). That boundary was never stated and is not where the personal data stops.

summae persists five aggregate kinds the exchange format does **not** declare — `asset`,
`costingRun`, and since 2026-08-29 `provision`, `deferral`, `inventoryValuation`. Three of them carry
operator-supplied free text: `asset.name`, `provision.reason`, `deferral.reason`. Nothing constrains
what goes in there, and a provision is *by its nature* often about a named party — a dispute, a
warranty claim, a severance. An Art. 30 record assembled from §1 does not mention any of them.

**Why the rows were not simply added.** `GdprConformanceDocTest` / `gdpr-conformance-doc.test.ts`
resolve every inventory row against `$defs` in `format.schema.json`; a row for a record the format
does not declare turns the gate red. That guard is right — it is what keeps the inventory from
describing fields that no longer exist — and the gap is upstream of it: **the format schema does not
declare the aggregates the persistence adapters read back** (that half is IMPL-046). Adding prose to
§1 that the guard cannot check would put the inventory back in the state the guard exists to prevent.

**Built in the meantime:** the boundary is stated in §1 with the three fields named, and an operator
answering an Art. 30 request is told to add them by hand. That is honest and it is not a fix.

**What would close it.** Either the aggregates enter the format (IMPL-046) and the three fields
become ordinary inventory rows with the guard behind them — the clean order — or
`personalDataDescription` grows a second list for *stored* free text with its own guard, and §1
follows it. The first is preferable: two lists of personal-data fields is how the two drift apart.

## IMPL-046 — five persisted record kinds are a shared format that no shared oracle covers

**Found 2026-08-29**, from the other side of IMPL-045.

The root `CLAUDE.md` calls the adapters' JSON *"the shared data format"*, and the contract
obligations say anything the engine reads is validated against `format.schema.json`. Both
persistence adapters store aggregates as JSON and read them back through `restore()` — `asset`,
`costingRun`, `provision`, `deferral`, `inventoryValuation`. **None of the five is in `$defs`.**
Three of them arrived on 2026-08-29, so this went from a two-year-old blind spot to a five-kind one
in a day.

Three things say the same gap out loud:

- The `Unreleased` CHANGELOG section states *"the data format gains four record kinds"* — while
  `FORMAT_VERSION` stayed `0.9`, `format.schema.json` gained four **module kinds** and no `$defs`
  entry, and `datenformat.md` has no section for any of them.
- The cross-test (SF-15, *one DB, multiple engines, one truth*) compares `journalExport` and nothing
  else. A provision written by PHP and read by Node is a real scenario for a shared database, and no
  test in either language crosses that line — each side tests its own adapter against itself.
- The three fixed-asset findings that reached the register through an embedding application
  (IMPL-021 … IMPL-026) all lived in exactly this unschema'd half.

**This is not a claim that the two implementations disagree.** They are mirrored by hand and both
adapter suites pass. It is the claim that *nothing would notice if they stopped agreeing*, which is
the same argument that produced the fixtures in the first place.

**What would close it.** `$defs` entries for the five aggregates plus a `## v0.10` section in
`datenformat.md` and a `FORMAT_VERSION` bump (the version is what makes a reader's validator
correct); the schema validation test extended to a persisted round trip; and the cross-test reading
back at least one aggregate written by the other engine. **Built in the meantime: nothing** — a
format version is a published fact and bumping it inside a doc-review pass would be the kind of
quiet decision this register exists to prevent.

## What is closed, and the pattern in it

Everything else that stood here is in [`FINDINGS-CLOSED.md`](FINDINGS-CLOSED.md) with what was
decided and why.

SPEC-022 was open for a few hours on 2026-08-28 and its premise turned out to be wrong, which is the
useful part: it read the reserved-field rule as blocking *the* hash chain, when the rule reserves
`previousEntryHash` on the **posting** while the obligation it was meant to serve
(`docs/gobd-conformance.md` §14 item 5c) asks for tamper evidence on the **audit trail**, which
carries no such reservation. Two different chains had collapsed into one word. The trail's chain is
built (F-CORE-043); the posting's stays blocked, with the reasoning in the closed register.

Of the seven before it — IMPL-030 to IMPL-034, SPEC-020 and SPEC-021 — **four came from outside**,
reported by an application embedding the library, and the other two were found while building what
those reports asked for. Our own suite was green through all of it, and each fix shipped with the
guard that would have caught it. IMPL-033 sharpens the point: it was found by publishing a number
that until then existed only inside a refusal — a figure nobody can read is a figure nobody can
check. IMPL-034 is the same lesson from the other side, sitting in the half of a folder that the
guard built for IMPL-031 did not reach, because a guard quietly marks where attention stops.

**The six that closed on 2026-08-28 were that pattern one level up**, and they are worth naming
here because the shape recurs. IMPL-037 (the normative format document unguarded while its derived
artefacts are), IMPL-039 (`covers` unguarded while the error catalogue is), IMPL-038 (a
self-description held by nothing but a boolean), IMPL-040 (a declared error code raised by nothing),
IMPL-041 and IMPL-042 (requirements declared and not built, found by counting rather than by
failing) all share one shape: **the thing everything else is defined in terms of is the thing
nothing checks.**

Four of the six turned out to be **larger or different than written**, which is the argument for
picking a finding up rather than trusting its summary: IMPL-037 had already recurred (0.8-vs-0.9)
and hid three more undocumented versions; IMPL-038's blocker did not exist and its gap was four
times the estimate; IMPL-040 was sitting on a live bug that stopped a scale-3 tenant reading its own
books; IMPL-042's blocker was in the shipped pack data all along. A finding is a lead, not a
verdict.

**IMPL-043 is what that pass could not close by building.** It is a claim about *meaning* —
whether a fixture proves what its `covers` says — and that is the exact thing the guard built for
IMPL-039 cannot check. It is here rather than folded into a green check on purpose.

**IMPL-045 and IMPL-046 came out of the doc review on 2026-08-29 and are the same shape one more
time.** Three censuses, a handbook, a CHANGELOG and both gates were green and current; what was
behind was the *normative prose underneath them* — the API spec (26 of 80 operations missing, two
names no implementation ever carried), the module-kind enum in the format spec (four short, for the
second time in three days), and the policy-kind census that claims to make the architecture provable
by enumeration (nineteen operations that fit none of its three buckets, because the fourth bucket was
never written). Those three were fixed and guarded the same day (IMPL-044). What could **not** be
fixed by writing is the pair above: both are about records the format does not declare, and that is
a decision about the format, not a paragraph.

The next one goes here.
