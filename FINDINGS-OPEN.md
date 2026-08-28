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

**The four open entries above are that pattern one level up.** IMPL-037 (the normative format
document is unguarded while its derived artefacts are), IMPL-039 (`covers` is unguarded while the
error catalogue is), IMPL-040, IMPL-041 and IMPL-042 (requirements that are declared and not built,
found by counting rather than by failing) all share one shape: **the thing everything else is
defined in terms of is the thing nothing checks.** That is worth saying once here rather than five
times below.

The next one goes here.
