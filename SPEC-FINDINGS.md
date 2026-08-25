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

## SPEC-016: the set of VAT filing periods is a jurisdictional claim living in the substrate

**Found 2026-08-24, while closing the `TaxProfile` coercion (F-TAX-003).**

`TaxProfile.fromData` now refuses a `vatPeriod` it does not know instead of silently returning
`quarterly`, which is the right fix for the defect that was reported. Refusing requires a list, and
the list is `['monthly', 'quarterly', 'yearly']` — three constants in the jurisdiction-free core.

**The litmus test fails.** *Would another jurisdiction answer this differently?* Yes, and not
theoretically: Ireland files VAT bi-monthly, several jurisdictions have half-yearly windows, and
some have none of these because they have no VAT. A closed list of filing periods in the substrate
says "these are the filing periods there are", which is exactly the kind of statement the substrate
is defined not to make. The guard test caught the first draft of this — the comment cited
§ 18 Abs. 2 UStG as the reason `yearly` exists, and `no-jurisdiction-text.test.ts` refused it. The
statute came out of the comment; the assumption it justified stayed in the code.

**Why it was still done this way, deliberately:**

- The field is a **label**. `vatPeriod` records which window a tenant files in and selects nothing —
  `vatReturn` takes `year` + optional `quarter`/`month` and computes from those. So a wrong value
  produces a wrong *statement about* the tenant, never a wrong figure. The blast radius of the
  substrate being wrong here is one descriptive field.
- The previous list was **also** a jurisdictional claim, and a worse one: it omitted a period that
  exists and lost the caller's value silently. Replacing a wrong closed list with a less wrong
  closed list is an improvement even if closed lists are the real problem.
- The right shape — the pack declares which filing periods it recognises, e.g. in `packPolicy` —
  touches the pack format, `format.schema.json`, all three shipped packs and every tenant built from
  an inline bundle. That is a change with a decision in it, not a refactor, and it does not belong
  inside a fix for a coercion bug.

**Note the asymmetry with the field beside it.** `taxationMethod` gets the same treatment in the
same function and is *not* this finding: accrual and cash are the two ways this engine can time a
tax liability, and it implements both. That set is substrate mechanism — a jurisdiction picks from
it, it does not extend it. Two fields, one line apart, on opposite sides of the boundary; that is
worth writing down, because the next reader will see one enum and assume the other is like it.

**What would decide it:** the first pack that needs a period this list does not have, or the first
time `vatPeriod` stops being descriptive — if a projection ever selects its window from the profile,
the list starts deciding figures and the argument above expires. Until then the constants stay, with
this note as the reason they are not defended.

## SPEC-017: the parameter contract reaches keys, not element structures

**Found 2026-08-24, while carrying the net lines' dimensions through the tax expansion (F-CORE-006).**

`api-parameters.json` declares every accepted parameter and every accepted operation input with its
type, and the dispatcher validates against it *before* routing — an undeclared key is
`E_INPUT_INVALID`, a declared one of the wrong type is rejected, an absent one keeps its default.
That closed the accepted-and-ignored class for inputs, and it is what F-9 was about.

It reaches one level deep. `netLines` is declared as `array`, and nothing looks inside the
elements. So:

```json
{ "netLines": [ { "account": "6040", "money": {…}, "dimension": [ … ] } ] }
```

is accepted, books correctly, and drops the cost centre — because the key is `dimension` and the
engine reads `dimensions`. No error, no warning, and the posting looks right. That is exactly the
shape of the defect the same fixture just closed, one level further in: the element was dropped
because nothing carried it, and now it is dropped because nothing checks the key that carries it.

The same gap exists on every array-typed input: `lines` on `post`, `allocations` on `settle`,
`steps`/`rates` on `setAllocationScheme`, `weights` on `allocate`, `smallBusiness` segments on
`setTaxProfile`. Some of those have their own parsing that fails loudly on a missing *required*
field — `post` refuses a line without an account — but an *optional* one is silently absent in all
of them.

**Why it is not closed here:** element schemas are a different mechanism from a key table, not a
bigger one. Three shapes are defensible and they are not equivalent:

1. **Element declarations in `api-parameters.json`** — `netLines: { type: 'array', element: {…} }`,
   validated by the same dispatcher pass. Consistent with what exists, and it means writing the
   element shape of every array input in the file plus a second traversal in both languages.
2. **JSON Schema for inputs**, reusing `format.schema.json`'s machinery. Expressive, and it puts a
   second validation language in front of the dispatcher.
3. **Per-parser strictness** — each parser rejects keys it does not know, where it already reads the
   element. Cheapest, and the rule then lives in a dozen places instead of one file, which is
   precisely what the parameter contract was created to end.

(1) is the obvious continuation, and it should be a decision rather than a side effect of the next
fix that trips over it. Nothing here is a regression: this gap has existed since the contract was
written, and the contract made the *outer* layer strict enough that the inner one now stands out.

**What raises the priority:** the fix that found this made `dimensions` meaningful on a net line, so
there is now an optional element key whose silent absence changes what cost accounting reports.
Before it, the elements carried nothing optional worth losing.

## SPEC-018: the audit trail can only be read whole — filters and authorship both scan it

**Found 2026-08-25, while making the audit trail audit-capable and while closing the embedding
app's F-29.** Two pieces of work arrived at the same wall from opposite sides, which is usually the
sign that the wall is real.

`auditLog` gained filters (`objectType`, `objectId`, `actor`, `action`) and paging, because the
question an auditor asks is about **one** thing and the projection could only answer "everything, by
date". `journal` and `unfinalizedEntries` gained `actor`, because the author of a posting lives in
the trail and nowhere else, and an application checking separation of duties was reading the whole
trail per finalization to rebuild it.

Both are **correct** and neither is **cheap**. The port answers `all()`:

```
interface AuditTrail { append(record); all(): list<AuditRecord>; }
```

So `AuditLogProjection` filters a fully materialised list, and `EntryAuthors` builds its map from
one. What the embedding used to do per finalization, the library now does per projection call — one
map serving the whole call instead of one per check, in the place that owns the data. That is a real
improvement and it is not the improvement the cost argument asked for.

**Why it cannot simply be pushed down.** `summae_audit_log` has `id`, `tenant_id`, `seq` and
`payload`; `objectType`, `action`, `actor` and `objectId` all live *inside* the JSON. Filtering in
SQL would mean either dialect-specific JSON functions — SQLite and Postgres differ, and byte parity
across adapters is the one thing this project will not trade — or **columns**, which is where this
meets SPEC-014: the idempotent install creates tables that are absent and does not touch tables that
exist. A new column on a table that already has rows is precisely the case it does not cover.

**Chosen behaviour:** the filters and the author map ship as they are, and the limit is stated where
someone will hit it — in `EntryAuthors` in both languages, and in the manual's `auditLog` section.
Correctness first, cost second, both said out loud.

**Proposal, and it is one decision rather than a refactor:** promote the four fields to columns and
extend the port with a filtered read. That needs a column-adding step in `installSchema`, which is
the additive case SPEC-014 explicitly left out. Doing it for the audit table alone would answer the
narrow question; doing it as "the installer can add nullable columns" answers a class of them and is
the version worth deciding.

**Not in scope for it:** the *contents* of the trail. Nothing here argues for storing less.

## SPEC-019: the documentation gate reaches names, not meanings — and an embedding lost a release to it

**Found 2026-08-25, while closing the embedding app's F-27.** The application reported that a
consideration reduction could not reach the VAT return with the right sign, tried three routes, and
concluded the case was unbuildable — so a legal obligation went unimplemented and a screen shipped
without a discount field.

**The third route works, and has since v0.4.** A plain `post` whose tax line carries a `taxTag` with
a negative `baseMoney` does exactly what was wanted; the fixture `core/settlement-discount` has
pinned it — including the corrected reporting key — the whole time.

They could not know. `taxTag` appeared in the manual as one item in a list:

```
Posting line (`lines[]`): `account` (…), `side` (…), `money` (…), `dimensions` (…), `taxTag` (object, no).
```

No shape. No word that `vatReturn` counts **only** tagged lines, which is the fact that makes the
field load-bearing. Nothing about the sign convention. A field that is named and never explained is
worse than one that is absent: absent, they would have asked.

**Why this is a gate gap and not a typo.** This repository guards contract surfaces on purpose, and
the documentation is one of them: `HandbookCoversTheApiTest` / `handbook-covers-the-api.test.ts`
fails when a published operation or projection has no section, and the walkthrough scenarios fail
when documented *behaviour* stops being true. Both work on **names**. Neither can see a documented
field that means nothing, and that is the shape this defect had. The published API surface is
guarded down to the operation; the published *vocabulary* is not guarded at all.

**Chosen behaviour:** `taxTag` is documented (shape, the only-tagged-lines rule, the sign
convention), and `postVoucher`/`expandTax` gained `reduction: true` so the raw field is not the only
road (F-TAX-014). Both halves, because the second one does not repair the first: the next
under-explained field will be somewhere else.

**Proposal — and the honest part is that none of the three is obviously right:**
1. Declare the documented shape of the fields the contract already knows about. `api-parameters.json`
   reaches keys, not element structures — which is SPEC-017 — so the two findings share a fix: an
   element declaration would be both checkable *and* documentable from one source.
2. A weaker guard with a good ratio: every field named in a parameter table must appear again in
   prose in that section. Mechanical, catches exactly this case, says nothing about quality.
3. Accept it as a review obligation and write it down. Cheapest, and the option this project usually
   argues against — a contract surface without a guard is what the gate-gap list is for.

Left open deliberately: option 1 depends on a decision SPEC-017 already owns, and pre-empting it
here would be the wrong order.
