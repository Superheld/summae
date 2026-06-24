# Spec-vs-fixture audit (#28) — depth options + sample findings

Goal (your methodological finding): do the fixtures pin the **spec's expectation**, or just the
**code's current output**? The cash-basis German labels slipped through because a fixture pinned
code behavior, not spec intent. This proposes the audit **depth** (your call) and shows the method
on a sample.

> Tracking/proposal branch, not merged. The full interpretive audit needs the requirement specs
> (knowledge base) and your depth choice; the objective layer below I ran now.

## Objective findings (ran now, no interpretation needed)

- **Covers-linkage is complete.** 85/85 behavioral fixtures carry a `covers` field linking the
  requirement(s) they prove (92 distinct F-…/SF-…/NF-… requirements). The only 4 JSON files without
  `covers` are the `conformance-xx` **pack test-data** (manifest + modules), not requirement fixtures —
  correctly so.
- **Hotspot spot-check — expectations are spec-derivable, not arbitrary.** e.g. `de-vorsteuer-ermaessigt`:
  net 500 at 7 % → gross 535 (500 × 1.07), and the trial-balance rows follow directly from the postings.
  The value is independently re-derivable from the described intent, i.e. spec-true.
- **The class that slipped through was output *labels*, not *amounts*.** The cash-basis VAT label
  (`Vereinnahmte USt`) was code-pinned text. That class is now caught structurally by the #27 guards
  (no jurisdiction label/text in the core) — so the specific hole is closed regardless of the audit.

## Depth options (your decision)

- **(A) Hotspots only** — re-derive expectations for the cash-basis / VAT / tax fixtures (the area
  where intent vs. output diverged) against the spec, by hand. ~1–2 h. Catches the same class.
- **(B) Systematic** — for every fixture, confirm each `expect` value is derivable from the spec it
  `covers`, not just reproduced from the engine. Needs the requirement specs (WB) open alongside;
  larger, produces a per-fixture audit table. Findings → `SPEC-FINDINGS.md`.
- **(C) Structural-only** — rely on the #27 guards + covers-linkage + "fixtures are authored from
  the spec" discipline going forward, and skip the retro-audit. Cheapest; accepts that old
  amount-fixtures are trusted.

## Recommendation

**(A) now, (B) if you want the assurance.** The dangerous class (jurisdiction label/text) is already
guarded; the remaining risk is a wrong *amount* fixture, which the spot-check suggests is low. Tell me
the depth and (for B) point me at the requirement specs, and I'll produce the audit table + any
findings.
