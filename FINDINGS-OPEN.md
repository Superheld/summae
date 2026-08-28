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

## IMPL-038 — the Z3 export's field catalogue describes four of the account's six fields

**Found 2026-08-28** while building the account validity window (F-CORE-045).

`journalExport` publishes a `fieldCatalogue` per stream — the self-description a GoBD Z3 data set
owes an auditor. For the `accounts` stream it names `number`, `name`, `type` and `subtype`. The
stream itself carries `id` and `status` as well, and since F-CORE-045 `validFrom`/`validTo` when
they are set. So an auditor reading the catalogue and the data side by side finds fields in the data
the description does not mention.

**Not caused by this change and not fixed by it.** `id` and `status` have been in the stream and
missing from the catalogue since the export existed; validity only makes the gap one field wider,
and only for accounts that actually carry a window (nulls are stripped before hashing, which is why
no export fixture moved).

**Why it is written down instead of repaired.** Adding rows to the catalogue changes the export
result — the catalogue is not inside the content hashes, but it *is* pinned literally by
`io/journal-export-z3-current` and `io/gdpdu-data-carrier`. Those fixtures pin behaviour, so the
repair is either a supersession or an argument that the catalogue's length is product data rather
than contract. That is the same question the superseded register keeps answering, and it deserves
its own decision rather than being settled in passing by a change about something else.

**What would close it.** Decide what the catalogue is: a *complete* description of the stream (then
it gains three rows, the two export fixtures are superseded, and a test holds the catalogue against
the serialised shape so it cannot drift again) or a *selected* one (then it says so in its own
`meaning` text, and the same test is not wanted). The first reading is the one an auditor takes.

## IMPL-040 — `E_AMOUNT_SCALE_MISMATCH` is a catalogue code with nothing behind it

**Confirmed 2026-08-28** (older than that; it has been carried in the pack-gate backlog).

It is the **only** error code in `fehlerkatalog.md` that is reachable through the API and has no
fixture. `E_WORKSPACE_INVALID` sits at the CLI level and cannot be reached from the suite;
`E_NOT_IMPLEMENTED` and `E_UNEXPECTED` are catch-alls that could only be triggered by building the
bug they report. Those three are covered by a per-language contract test, on purpose. This one is a
real gap: the reader/writer check that an amount carries exactly the tenant's `currencyScale`
decimal places — including mandatory zeros, canonical form — is **not built**, so the code is
declared and never raised.

**Why it matters more than one missing fixture.** The check is a cross-implementation guard by
nature: it is what would catch a store written by one runtime at scale 3 being read by a tenant at
scale 2. `SF-15` is exactly that scenario, and it passes today because both runtimes agree — not
because anything verifies the amounts.

**What would close it.** Build the check where amounts enter and leave (reader and writer), raise
the code, and add one fixture per direction. The pack policy already carries `currencyScale`, and
the `xx-2` / `xx-4` fixtures already run a pack at scale 3, so the fixture has somewhere to stand.

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
