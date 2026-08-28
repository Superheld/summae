# § 15a, § 14c and OSS in the `de` pack — ⚠️ OPEN, and deliberately not called a GoBD gap

> Written 2026-08-28, when the GoBD census row about the `de` pack's VAT coverage was corrected. The
> row is ⚠️ and stays ⚠️; this says why it is not a compliance defect and what each of the three
> would cost.

## The distinction the census row makes, and why it matters

The GoBD asks that what is booked be **computed correctly at its reference date** — verified, `F-TAX-*`
with pack-versioned codes — and that nothing filed be **silently wrong** — verified, `F-TAX-013`
`vatReturn.gapWarnings`, which names every posting that touches a tax account without a code. Neither
obligation says a pack must carry every code in the UStG. A business with no OSS turnover is not less
GoBD-compliant for a pack that cannot express one.

So this is **product scope**, named here so it is not mistaken for covered. Half of what the row used
to claim was already stale — intra-community acquisition (`IGE19`/`IGE7`) and exempt export
(`AUSFUHR`) were built on 2026-08-23, hours after the row was written, and it went on saying otherwise
for five days. That is fixed and now machine-checked (`docs/gobd-conformance.md` §15).

## What is actually missing, cheapest first

### § 14c — tax shown that was never owed

An invoice showing too much VAT owes the excess anyway. In the UStVA it is a **tax amount with no
base** (Kz 69), and that is exactly what the current code shape cannot express: every tax code
computes tax *from* a base.

**It is not unbookable today** — you post directly to `3100`, and `vatReturn.gapWarnings` names the
posting rather than dropping it silently. What is missing is the code, so the amount reaches the
right reporting key by itself.

**Cheapest of the three, and it has a clean seam.** `taxBase` gained `net` | `inclusive` on
2026-08-28 (F-TAX-010); a third kind — *the amount handed in **is** the tax, the base is zero* —
would express § 14c and is not German: import VAT and tax-only adjustment lines have this shape in
every VAT jurisdiction. That makes it a substrate-legal socket extension rather than a German special
case, which is the test that matters.

**Size:** one enum value, one branch in `splitByBase`, a pack code, a fixture per language.

### § 15a — input-tax adjustment

A correction spread over five or ten years when the use of an asset changes. This is **not a code**:
it needs its own state (the monitoring period per asset, the original deduction ratio, the yearly
comparison) and produces postings on its own schedule. An expansion policy with a lifecycle, closest
in shape to the depreciation engine.

**Size:** a project. Comparable to the asset module, not to a pack module.

### OSS — One-Stop-Shop

Destination-country VAT rates for B2C cross-border supplies, plus a **separate return** that is not
the UStVA. Needs a rate table per member state with validity dates and a second filing projection.

**Size:** a project, and the one that most resembles the US multi-state question the us-pack sign-off
deliberately left to the embedding application. Worth deciding on the same principle: does a
bookkeeping library carry a rate table that changes by jurisdiction and date, or does it take the rate
it is given? The `de` pack answers "carries" today for its own two rates; OSS is where that answer
stops scaling.

## Recommendation

Build **§ 14c** when the tax seam is next open — it is small, it closes a real hole in the return, and
the third base kind is jurisdiction-free. Leave § 15a and OSS as named projects; both are larger than
everything the `de` pack contains today, and neither is a GoBD obligation.
