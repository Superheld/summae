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

## IMPL-032 — the `default` pack cannot produce a balance sheet, and does not say so

**Found 2026-08-26** while checking whether IMPL-030 affected all three shipped packs.

It does not, because `default` ships **no mapping module at all** — no balance sheet, no income
statement, no cash-basis categories. It does ship the accounts they would need, including `2300
Ergebnisverwendung`. `balanceSheet` requires `mapping`, so on a `default` tenant every statement
projection fails with `E_INPUT_INVALID` until the embedding imports a mapping of its own.

That may well be the intent — `default` is the account-bearing neutral pack, and a jurisdiction-free
chart has no lawful statement layout to ship. But nothing states it: the pack's README does not, the
manual does not, and the failure a caller meets is a missing-parameter error rather than "this pack
ships no statements". A walkthrough scenario exists for `default` and simply never asks for one.

Two ways out, and they are not exclusive: say it where a reader looks (pack README + the manual's
pack table), and/or let a pack declare which projections it equips, so the answer comes from data
instead of from an error. The second is the same shape as `packPolicy.vatPeriods` (SPEC-016).

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

## SPEC-021 — `accountSheet` lines cannot reach their own entry

**Reported from outside 2026-08-25** by the embedding application (its F-31), against 0.13.0. Not
yet decided.

`accountSheet` returns per line: `sequenceNumber`, `entryDate`, `text`, `side`, `money`,
`runningBalance` and the reversal fields. No `entryId`, and no counter accounts. The projection is
an extract of **one** account and knows nothing about the other lines of the entries it is made of,
which is correct as a definition and means a T-account cannot show its own contra side — the
question every account sheet raises is "6000 in debit, against what?".

The way there today is `journal` with `fromDate` and `toDate` set to the same day, then filtering
the day's entries by `sequenceNumber`: a search where a lookup belongs, for an entry whose identity
the caller had two fields ago.

**Two asks, the first alone would do it.** (1) `entryId` on `accountSheet[].lines[]` — the same
identity `journal` publishes (`journal.ts`) and the trail records; the sheet is built from those
entries and drops it. (2) `contraAccounts` per line — the accounts on the other side of the same
entry. For a simple entry that is one; with a tax code it is two or more, and a screen showing "the"
counter account would be inventing a fact, so a list is the honest shape.

Cheap, and cheaper than it looks: the runner compares **subsets** (`comparator.ts`), so a new field
on an object turns no existing fixture red. What is undecided is the shape of (2), not whether (1)
is right.

## The next one goes here.
