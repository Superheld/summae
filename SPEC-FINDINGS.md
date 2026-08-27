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

## Nothing open

As of 2026-08-27 there is no undecided finding. That is a state, not an achievement: this register
is empty roughly as often as it is full, and the useful reading of an empty one is *"the last pass
closed what it opened"*, never *"there is nothing to find."*

The seven that were here — IMPL-030 to IMPL-034, SPEC-020 and SPEC-021 — are in
[`SPEC-FINDINGS-RESOLVED.md`](SPEC-FINDINGS-RESOLVED.md) with what was decided and why. Four of them
came **from outside**, reported by an application embedding the library, and the other two were found
while building what those reports asked for. That is the pattern worth noticing: our own suite was
green through all of it, and each fix came with the guard that would have caught it. IMPL-033 sharpens
the point — it was found by publishing a number that until then existed only inside a refusal, which
is the general shape: a figure nobody can read is a figure nobody can check. IMPL-034 is the same
lesson from the other side — it sat in the half of a folder that the guard built for IMPL-031 did not
reach, and nobody looked there, because a guard quietly marks where attention stops.

The next one goes here.
