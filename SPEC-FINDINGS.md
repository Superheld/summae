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

## SPEC-020 — `actorIsAuthenticated` can only ever be `false`, and it reads as a claim about the installation

**Reported from outside 2026-08-25** by the embedding application (its F-30), against 0.13.0. Not
yet decided.

`systemDescription.auditTrail.actorIsAuthenticated` is a constant `false`
(`system-description.ts`, `SystemDescriptionProjection.php`). No constructor argument, no operation
and no configuration sets it, so no embedding can make it say anything else.

Read as *"this library does not authenticate anybody"* that is exactly right — summae is handed an
`actor` string and cannot know where it came from. The trouble is what the field is **used for**:
the reporting app puts it into the generated Verfahrensdokumentation under obligation A-1, as
"Urheber geprüft: **nein**". Since that app grew a login (scrypt, signed session cookie, a gate
nothing passes but the login screen), the document tells an auditor that the identity behind every
entry is unverified about an installation where it is verified. An understatement in a compliance
document is cheaper than an overstatement, and it is not free.

**Two options, from the report, and they are not exclusive.** (1) A way to tell the library — a
declaration alongside the actor, so `systemDescription` reports what the embedding actually does;
summae would still authenticate nobody, it would be reporting a fact only the embedding can state.
(2) A field that cannot go stale — `libraryAuthenticatesActor: false` never becomes wrong, and a
generator reading it knows not to turn it into a statement about the installation.

Explicitly **not** wanted by the reporter: the app asserting "ja" on its own. The technical part of
that document is generated for exactly one reason — a hand-written technical description is the part
that quietly stops matching the software.

Note a home already exists for (1): since SPEC-015 a tenant's configuration is stored and reported
(`tenantConfiguration`), and a declaration by the embedding is the same shape as the four things it
already carries. The open question is whether a declaration summae cannot verify belongs in a
document whose value is that it is *read* rather than written, and if so, how it must be worded so it
reads as a declaration and not as a finding.

## The next one goes here.
