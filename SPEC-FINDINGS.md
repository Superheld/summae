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
