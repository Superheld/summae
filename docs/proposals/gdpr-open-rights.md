# The four GDPR rows that are still ⚠️ — ⚠️ OPEN

> Written 2026-08-28, immediately after the erasure work, so the remainder does not evaporate with
> the session that found it. `docs/gdpr-conformance.md` carries these as rows; this says what each
> would actually take.

The 0.15.x GDPR work built the two things that were **missing** — `erasePartner` (Art. 17 for what
the retention duty does not cover) and `personalDataDescription` (the Art. 30 building block) — and
declared the address shape. What it did **not** do is close four rows that were open before and
still are. None of them is a defect; each is a capability the census names and the library lacks.

## 1. Art. 15 — access: the parts exist, the answer does not

**Where it stands.** Everything a data subject may ask for is readable: `partner` for the master
data, `auditLog` filtered by `objectType`/`objectId` for the history, `openItems` and `journal` for
what the books say. What does not exist is **one call that assembles them**. There is not even a
`partners` projection — a partner is reachable by id, not enumerable.

**Why that matters more than it sounds.** An answer assembled by the embedding application is an
answer summae cannot make complete: a field added here (as `partner.status` was in 0.7) silently
falls out of every hand-written assembly, and nobody finds out until a supervisory authority does.
This is the same argument that moved the audited-event list from a hand-kept literal to a guard.

**Shape of the work.** A `personalData` projection taking a subject reference and returning every
record that names them, built from the *inventory* `personalDataDescription` already publishes
rather than from a second hand-written list — so the two cannot disagree. Plus a `partners`
projection, which is missing for its own reasons anyway.

**Size:** comparable to A-13. One projection, its parameter contract, a fixture per language, a
handbook section, and the census row.

## 2. Art. 18 — restriction: a label, not a stop

**Where it stands.** `partner.status = inactive` exists and is pinned (`partner-status`). It is a
flag that nothing enforces: nothing refuses to post against an inactive partner, and nothing marks
the records as restricted.

**The honest difficulty, and why this is not simply "add a check".** Restriction under Art. 18 means
*stop processing but keep the data* — and the books must keep being the books. A restricted partner's
existing postings cannot be hidden: the retention duty (§ 147 AO) and the append-only journal both
forbid it, and `docs/gdpr-conformance.md` already says the books are not erasable. So restriction can
only reach **new** processing, and the question is which: refusing new postings? refusing new
vouchers? marking exports? Each is defensible and they are not the same rule.

**Recommendation: do not build it until the question above has an answer.** A half-restriction that
blocks one path and not the others would be worse than the honest ⚠️, because it would read as
compliance.

## 3 & 4. Art. 5(1)(c) minimisation and Art. 25 by design — visible, not narrowed

**Where it stands.** `personalDataDescription` reports the free-text surface — which fields can hold
personal data, which are required, which are actually populated. Both rows stayed ⚠️ on purpose after
that work, with a sentence worth repeating: **detecting is not preventing.** A `text` field that can
hold a name still can.

**What would close them, and what would not.** Not a validator that guesses at names — that would be
wrong constantly and is not what either article asks. What is closable:

- **The declared address shape is open (`additionalProperties: true`) on purpose**, because closing
  it would invalidate lawful pre-existing data. A *warning* projection ("this tenant's partners carry
  address keys the format does not declare") narrows without invalidating.
- **A per-tenant switch that refuses free text on the fields that do not need it** would be real
  minimisation by default — and it is a pack- or tenant-level policy, not substrate behaviour.

**Size:** smaller than #1, and worth doing after it, because both build on the same inventory.

## Order, if these are taken

1 → 3/4 → 2. The access answer is the one an authority actually asks for, minimisation reuses its
inventory, and restriction is blocked on a product question rather than on effort.
