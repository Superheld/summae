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

## SPEC-022 — the audit hash chain cannot be built without amending a normative rule

**Found 2026-08-28** while starting the hash-chain hardening that `docs/gobd-conformance.md` §14
item 5c records as *deferred, not rejected*. It cannot be built as scoped, and the obstacle is not
effort.

`knowledge/50-spezifikation/datenformat.md`, section *Reservierte Felder*, is explicit and normative:

> `previousEntryHash` (Buchung — Hash-Kette) … **Reader MÜSSEN diese Felder ignorieren, Writer
> DÜRFEN sie in v0.x nicht belegen.**

We are on format 0.7 and library 0.15. Populating the field would break that rule in both halves,
and the second half is the one that matters: a conforming reader has been *instructed to ignore*
the field, so a chain written today is tamper evidence **only for us**. An auditor's tool, another
implementation, a future reader — all of them would be conforming precisely by ignoring it. Shipping
it anyway buys the appearance of a guarantee and none of the guarantee.

Bumping the format to 0.8 does not help: the rule says *v0.x*, and 0.8 is v0.x.

**Two ways out, and both are product decisions rather than engineering ones:**

1. **Amend the reserved-field rule** so that writers may populate `previousEntryHash` from format
   0.8 on, and readers must verify it when present rather than ignore it. Cheap to write, and it
   changes what the format promises to anyone already reading it under the old rule.
2. **Take it to format 1.0**, where the reserved fields were always meant to become live. Honest to
   the text as written, and a much larger statement about the format's stability than a hardening
   deserves to force.

**Built in the meantime: nothing, deliberately.** The conservative option here is not a partial
chain — a chain that exists but is ignorable is worse than none, because it reads like protection.
Manifest-level hashing (SHA-256 per stream, RFC 8785) is unaffected and still does what it always
did. The rest of `docs/gdpr-conformance.md` and §14 item 5c stay accurate.

**What would close this:** a decision on 1 or 2. Everything after it is ordinary work — the hash is
over canonical JSON, which both languages already produce identically, and the cross-test already
compares `journalExport` byte for byte.

## Nothing else open

Apart from SPEC-022 above, there is no undecided finding as of 2026-08-28. That is a state, not an
achievement: this register is empty roughly as often as it is full, and the useful reading of an
empty one is *"the last pass closed what it opened"*, never *"there is nothing to find."*

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
