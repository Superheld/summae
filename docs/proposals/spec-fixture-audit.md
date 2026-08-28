# Spec-vs-fixture audit (#28) — ✅ DONE (depth A executed, clean bill)

> **Nothing open here.** Depth A was chosen, run and came back clean; B and C below are the
> options that were *not* taken and are kept for the reasoning. The only live question is whether
> to ever run depth B, and the recommendation at the bottom already answers it: only if you want
> the assurance, not because something is suspected.

Goal (your methodological finding): do the fixtures pin the **spec's expectation**, or just the
**code's current output**? The cash-basis German labels slipped through because a fixture pinned
code behavior, not spec intent.

**Decision (Roland): depth A — hotspot audit.** Executed below; result: **no amount-level findings,
clean bill.** The objective layer + original options are kept beneath for the record.

## Depth-A result — hotspot audit (executed)

Hand-verified the expected values of the cash-basis/VAT hotspot fixtures against correct accounting
from first principles (not against the engine output). Every one is **spec-derived** — several fixtures
even document their own derivation:

| Fixture | Check (re-derived by hand) | Verdict |
|---|---|---|
| `de-euer` | Sale 19 %: Umsatzerlöse 1000 + USt 190 (19 % × 1000). Input 19 %: Sonstige 500 + Vorsteuer 95. AfA 500 (asset + depreciation, non-cash via includeNonCash). | ✓ all 5 lines |
| `de-vorsteuer-ermaessigt` | net 500 at 7 % → gross 535 (500 × 1.07); trial-balance rows follow the postings. | ✓ |
| `vat-return-cash-basis` | Invoice alone: nothing (Ist-Versteuerung). 595/1190 = 50 % → base 500, tax 95; final payment gets remainder, Σ = exactly 1000/190. | ✓ |
| `vat-return-cash-basis-rounding` | 400/1190 → tax 63.8655…→63.87 (half-up), base 336.13→336 (floored); final 62.26 so Σ tax = exactly 190.00; Σ shown bases 336+336+327 = 999 (euro-floor not sum-preserving — correctly noted in the fixture). | ✓✓ exemplary |
| `cash-basis-ten-day-rule` | Payments count in the economic year iff in the 22.12–10.01 window; three variants → 190 / 180 / 190; recompute identical (F-CORE-016). Label `USt-Zahlung an FA` from the pack mapping. | ✓ |

**Finding:** the hotspot **amounts** are rigorously spec-derived (the derivation is in the fixture
comments). The class that actually slipped through was code-pinned **label text** (`Vereinnahmte USt`) —
*output strings*, not amounts — now (a) fixed (labels come from the pack mapping) and (b) guarded by #27
(no jurisdiction label/text in the core). Hole closed, audited amounts sound. No `SPEC-FINDINGS` entries
from depth A.

**Out of scope for A (would be depth B):** non-hotspot fixtures (assets, costing, dimensions, DATEV) were
not hand-re-derived — trusted via covers-linkage + the conformance oracle.

---

## Appendix — objective findings (ran during scoping)

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
  `covers`, not just reproduced from the engine. Needs the requirement specs (`knowledge/30-anforderungen/`) open alongside;
  larger, produces a per-fixture audit table. Findings → `SPEC-FINDINGS.md`.
- **(C) Structural-only** — rely on the #27 guards + covers-linkage + "fixtures are authored from
  the spec" discipline going forward, and skip the retro-audit. Cheapest; accepts that old
  amount-fixtures are trusted.

## Recommendation

**(A) now, (B) if you want the assurance.** The dangerous class (jurisdiction label/text) is already
guarded; the remaining risk is a wrong *amount* fixture, which the spot-check suggests is low. Tell me
the depth and (for B) point me at the requirement specs, and I'll produce the audit table + any
findings.
