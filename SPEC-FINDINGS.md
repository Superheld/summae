# SPEC-FINDINGS — open

Contradictions between spec / fixture / model, and defects found while building, that are **not
decided yet**. Root `CLAUDE.md`: "don't guess, don't bend the fixture, but document it and build on
with the next-most-plausible behaviour."

**This file holds only what is open, on purpose.** Somebody — or something — told to look at the
open findings should be able to read the whole register and carry nothing else. Twenty-one resolved
entries with their full reasoning live in
[`SPEC-FINDINGS-RESOLVED.md`](SPEC-FINDINGS-RESOLVED.md), together with the status table over
everything; that is where a code comment saying "see IMPL-025" resolves to, and it is not reading
you need in order to work on what is open.

**Recording a finding:** append it here, in the series it belongs to — `SPEC-nnn` (spec / fixture /
model contradict each other) · `SPEC-Cnn` (cross-implementation) · `IMPL-nnn` (implementation
defect). Numbers are never reused; the highest one ever issued is the last row of the status table
in the resolved file. Which language you found it in is a sentence in the entry, not a file — most
of them turn out to concern both.

**Closing one:** move the whole block to `SPEC-FINDINGS-RESOLVED.md` and add its row to the status
table there. Moving it is the entire bookkeeping — the split is by *state*, and a state changes
once. That is also why this is not the old per-language split: that one duplicated the same text in
two places and drifted (SPEC-014 did, in the open).

## IMPL-037 — the normative data-format document lags the format it defines

**Found 2026-08-28** while writing the 0.8 section of `knowledge/50-spezifikation/datenformat.md`.
The document's title said **v0.6**. The schema had been at **0.7** since the partner record gained a
status, both engines shipped it, fixtures exercised it — and the document that calls itself normative
described a format the product had left behind, for weeks, with the whole gate green.

**Why this is a finding and not a typo I already fixed.** The instance is repaired (0.7 and 0.8 are
both written up now). The *gap* is that nothing would have caught it and nothing would catch the
next one. `format.schema.json`'s `$id` is held against `FORMAT_VERSION` by
`format-version.test.ts` and its PHP twin, in both languages — so **code and schema cannot drift**.
The prose that both of them are supposed to derive from is checked by nobody, which inverts the
authority: the derived artefacts are guarded and the normative one is not.

This is the same shape as the GoBD census row that described a `de` pack which had already moved
(closed 2026-08-28 by making §15 a machine-checked table of the facts that document asserts). One
folder over, the same defect class, no guard yet.

**What would close it.** Not a full prose check — that is not achievable and not wanted. The
narrow, checkable claims are enough:

- the version in `datenformat.md`'s title and its `$id` line equal `FORMAT_VERSION`;
- every version between the oldest documented and the current one has a `## v0.x` section, so a
  release cannot skip its own write-up the way 0.7 did;
- optionally, that every `$defs` key the schema declares is named somewhere in the document.

A guard beside `GobdConformanceDocTest` / `gobd-conformance-doc.test.ts`, in both languages, because
the rule about mirrored tests applies to guards too.

**Built in the meantime: nothing**, deliberately — writing the guard is the fix, and it is small
enough that starting it half-way would only hide the gap behind a test that checks the easy half.

## Nothing else open

Apart from IMPL-037 above, there is no undecided finding as of 2026-08-28. That is a state, not an
achievement: this register is empty roughly as often as it is full, and the useful reading of an
empty one is *"the last pass closed what it opened"*, never *"there is nothing to find."*

SPEC-022 was here for a few hours on 2026-08-28 and is resolved; the short version is that its
premise was wrong, and that is the useful part. It read the reserved-field rule as blocking *the*
hash chain, when the rule reserves `previousEntryHash` on the **posting** and the obligation it was
meant to serve (`docs/gobd-conformance.md` §14 item 5c) asks for tamper evidence on the **audit
trail** — which carries no such reservation. Two different chains had collapsed into one word. The
trail's chain is built (F-CORE-043); the posting's stays blocked, and the decision to leave it that
way is in the resolved register with its reasoning.

The seven others that were here — IMPL-030 to IMPL-034, SPEC-020 and SPEC-021 — are in
[`SPEC-FINDINGS-RESOLVED.md`](SPEC-FINDINGS-RESOLVED.md) with what was decided and why. Four of them
came **from outside**, reported by an application embedding the library, and the other two were found
while building what those reports asked for. That is the pattern worth noticing: our own suite was
green through all of it, and each fix came with the guard that would have caught it. IMPL-033 sharpens
the point — it was found by publishing a number that until then existed only inside a refusal, which
is the general shape: a figure nobody can read is a figure nobody can check. IMPL-034 is the same
lesson from the other side — it sat in the half of a folder that the guard built for IMPL-031 did not
reach, and nobody looked there, because a guard quietly marks where attention stops.

The next one goes here.
