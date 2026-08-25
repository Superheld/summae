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

As of 2026-08-25 there is no undecided finding. That is a state, not an achievement: this register
is empty roughly as often as it is full, and the useful reading of an empty one is *"the last pass
closed what it opened"*, never *"there is nothing to find."* Everything that was open — SPEC-016,
SPEC-017, SPEC-018 and SPEC-019 — is in
[`SPEC-FINDINGS-RESOLVED.md`](SPEC-FINDINGS-RESOLVED.md) with what was decided and why. Two of them
were closed by the same pass that made the third possible, and one of them says plainly that its own
proposal had been wrong.

The next one goes here.

