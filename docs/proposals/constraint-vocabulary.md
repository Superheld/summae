# The constraint vocabulary beyond two words — ✅ **decided 2026-08-28**

**A was declined. B was built, with one deviation.** The reasoning below is the memo as it was
written; this section is what happened when someone tried to build it, and the two do not agree.

## What was decided

### Proposal A — a predicate keyed on the account's `subtype`: **declined**

Not on cost, and not on the subtype vocabulary, which was closed anyway (F-CORE-046 — worth doing on
its own merits, exactly as this memo said, and it found two fixtures carrying an inert `subtype:
"vat"` on the way). **A was declined because its motivating rule is not safely expressible by any
per-entry predicate, keyed on subtypes or on numbers.**

The memo's premise about the shipped pack is wrong, and checking it is what settled this. It says
"`de` forbids `4030`/`4040` (tax-free revenue) from meeting `3100`–`3110`". `de` forbids **4040
only**, and 4030 was excluded *deliberately*, with the reason written into the module doc on the day
the rule shipped: a collective invoice may legitimately carry a taxable supply and an
intra-community one at once, and then `4000`, `3100` and `4030` stand in one entry entirely
correctly. A `forbid` rule on 4030 would refuse a right posting.

That objection does not go away when the key changes from a number to a subtype — it **generalises**.
`us` is the clearer case: `4100 Exempt Sales (resale / interstate / nontaxable)` meets
`2100 Sales Tax Payable` on any mixed receipt with a taxable and an exempt line, which is what a
grocery till produces all day. So tagging exempt revenue and shipping "an exempt supply may not
carry output tax" would refuse correct postings in **both** packs, and in a third-party pack it
would do so the moment its author tagged a chart, having never been told the rule existed.

The reason is structural rather than particular: **`4040` is safe only because its prohibition is
really about the tenant, not about the supply.** A small business has no output tax for a whole
calendar year (§ 19 Abs. 1), so no single document mixes the two regimes. Every other candidate the
audit produced fails the same way — an entry may bundle several supplies, and a per-entry predicate
cannot see which line belongs to which. That includes the one that looked best on paper, "a private
withdrawal may not carry input tax": a phone bill split business/private in one entry is the
textbook posting.

The mechanism itself is not unsafe; it has no safe shipped use. Since the memo's own sequencing rule
is that a capability no pack speaks is not a guarantee, building it would have added a word to the
vocabulary that nothing may say. **What would reopen it:** a rule that is genuinely impossible per
entry, holds across jurisdictions, and needs the account's role rather than its number. The audit
did not find one. Note that this is *not* an argument against subtypes carrying meaning — the closed
repertoire (F-CORE-046) is exactly that argument's other half, and it shipped.

### Proposal B — conditional constraints: **built, as `appliesWhen` restricted to `legalForm` and `taxationMethod`**

Option A as recommended, and `smallBusiness` and amount conditions are argued out with their reasons
carried into `format.schema.json`, `AccountCombinationRegistry` and F-CORE-047 so the next reader
finds them where the omission is, not only here.

**The deviation: a second new word, `accountUsageRules`.** The memo's own sketch expressed "a capital
company has no `2400 Privat`" as `whenAccountIn: 2400–2400` plus `forbidAccountIn: 0000–9999`, on
the reasoning that every entry has at least two accounts so the rule always fires. It does fire, and
it is wrong twice. It reads as a *range*, so the next author has to deduce that the range means
"everything"; and account numbers compare by **code point**, so `0000`–`9999` does not cover a chart
whose numbers begin with a letter and covers a six-digit chart only by accident. A prohibition whose
correctness depends on how a foreign chart happens to number its accounts is not a prohibition. The
sentence the packs actually want to say — *this tenant may not touch this account at all* — is not a
combination, so it got its own word and its own code (`E_ACCOUNT_USE_FORBIDDEN`).

Two things the memo did not anticipate and the build had to settle:

- **A missing fact makes a rule dormant, not failing.** A tenant that never called
  `setEntityProfile` has no legal form. Refusing its postings would punish it for not having
  configured something; applying the rule anyway would assume a precondition nobody checked. The
  rule is still reported by `tenantConfiguration`, because otherwise a caller cannot tell "no such
  rule" from "rule waiting for a fact".
- **A mistyped condition is the same silent failure this memo was written about.** `legalForm:
  ["gmhb"]` would leave the rule permanently dormant and the pack looking stricter than it is. The
  resolver therefore checks every named legal form against the pack's own `legalForms` catalogue and
  every taxation method against the engine's two (invariant **I10**, `E_PACK_INCOHERENT`).

Shipped in `de@2026.10` as `de-kapitalgesellschaft`; the mechanism is pinned by
`xx-12-constraint-applies-when` on a pack that fixture brings itself.

### Found on the way, unrelated to either proposal

Bumping `de` from `2026.9` to `2026.10` exposed that "current = the highest version" compared whole
strings by **code point**, so `2026.10` sorted *below* `2026.9`. The tenth release of any pack would
have looked published while every versionless tenant kept resolving the ninth, and `resolvePack`
would have reported a real, existing, wrong version. Fixed to compare segment by segment, numerically
where both segments are numbers (F-CORE-048, `xx-13-pack-version-ordering`). Nothing published
resolves differently — with single-digit segments the two orders agree.

---

*Below: the memo as written, before any of the above was known.*

---

**Written 2026-08-28**, out of an audit of "which postings are impossible, and which of those can
summae see?" Two of that audit's findings were built the same day (`de-kleinunternehmer`, § 19 UStG,
the first shipped use of `forbidAccountIn`; and `duplicateVouchers`, F-CORE-044). This memo is what
the audit found that the *vocabulary itself* cannot express, and what it would cost to change that.

It asks for two decisions, and they are separable — the second is worth having even if the first is
declined.

---

## Where the vocabulary stands

The constraint kind is the one of the three policy kinds that exists to let a jurisdiction say
**no**. It has two words:

| Predicate | Says | Since |
|---|---|---|
| `dimensionRules` | this account may not be posted without that dimension | 2026-08-23 |
| `accountCombinationRules` | these accounts must, or must not, appear in **one entry** together | 2026-08-28 (F-CORE-042) |

Both see exactly one entry — no deadlines, no reach across entries, no rule about a settlement.
That limit is deliberate and this memo does not propose changing it (see *What neither proposal
fixes*).

Shipped uses: `de-entgeltminderung` (§ 17 UStG, `requireAccountIn`) and `de-kleinunternehmer`
(§ 19 UStG, `forbidAccountIn`). Both are keyed on **account numbers**.

---

## What the audit found the vocabulary cannot say

Ordered by how often it would actually bite:

1. **Rules about an account's *role* rather than its number.** The same prohibition exists in two
   shipped packs, written twice: `de` forbids `4030`/`4040` (tax-free revenue) from meeting
   `3100`–`3110`; `us` would forbid `4100 Exempt Sales` from meeting `2100`/`2110 Sales Tax
   Payable`. One rule, two transcriptions, neither checkable against the other.
2. **Rules conditioned on the tenant.** § 19 Abs. 1 Satz 4 UStG denies a small business the input-tax
   deduction outright; a GmbH has no `2400 Privat` (a withdrawal is salary, a loan or a vGA); an
   `einzelunternehmen` has no use for `2300 Ergebnisverwendung`. `de-rechtsformen` already declares
   all eight legal forms and no constraint can read it.
3. **Rules conditioned on an amount.** `6510 Sofortabschreibung GWG` with 5.000 € is impossible
   under § 6 Abs. 2 EStG. The threshold is already in `de-afa`, and only the asset expansion reads
   it.
4. **Cross-entry and temporal.** Negative cash, the same invoice twice, a discount taken after its
   deadline, § 15a. Out of scope for a per-entry predicate by construction.

(4) is answered by projections — `cashJournal.negativeBalances`, `unfinalizedEntries`,
`duplicateVouchers` — and should stay there. (1) is proposal A, (2) and (3) are proposal B.

---

## Proposal A — a predicate keyed on the account's role

### Sketch

```json
{
  "whenSubtype": "exempt_revenue",
  "forbidSubtype": "tax_out",
  "note": "an exempt supply may not carry output tax"
}
```

Same shape as today: `whenSubtype` plus exactly one of `requireSubtype` / `forbidSubtype`, same
one-entry semantics, added beside `accountCombinationRules` rather than replacing it (numbers stay
right for a rule about *specific* accounts, like § 17's `4020`).

### What it buys

- **The rule is written once and reads as mechanism.** A new pack inherits it by tagging its chart,
  which is the whole point of the substrate/pack split — and the litmus test answers cleanly: *would
  another jurisdiction answer "may an exempt sale carry output tax" differently?* No.
- **It survives chart extension.** A number range has to be edited when an account is added next to
  it; a subtype does not. That failure mode is not hypothetical — it is why `de-euer-mapping-gap`
  was retired, and why the `de` chart's own README warns about extending *between* claimed numbers.
- It puts the semantics in the **chart**, which is where the § 17 module already deliberately put
  it: *"naming the obligation in the account is what makes the question answerable at all."*

### What it costs, and the decision it drags in

`subtype` is a **free string** in `format.schema.json` (`"subtype": {"type": "string"}`), while the
core reads about ten specific values (`bank`, `cash`, `transit`, `ar`, `ap`, `tax_in`, `tax_out`,
`fixed_asset`, `opening_balance`, `result_allocation`, `private`). A pack that writes `tax-out`
instead of `tax_out` today loses a `vatReturn` gap warning silently; under this proposal it would
also lose a **prohibition**, silently.

That is exactly the defect shape closed for tax mechanisms in v0.8.0 — a mistyped
`reverse-charge` fell back to `standard` and posted an ordinary tax line under an ordinary reporting
key, with nothing in the output to show the mechanism had gone. The answer there was to close the
repertoire and fail loudly at resolve time.

There is also a missing marker on the other side: nothing in the chart says a revenue account is
tax-exempt. `4030`'s German *name* says it; no field does. So this proposal needs a companion —
either a new subtype value (`exempt_revenue`) or a general tag list on the account.

And it must be said plainly that this adds **no expressive power**. Everything a subtype rule can
say, a number range can say today. The gain is reuse and robustness, not reach.

### Recommendation

**Do it — in two steps, in this order, and not in one.**

1. **Close the subtype vocabulary first**, as its own change: a registry of known subtypes in the
   core (both languages, byte-equal), an unknown one is `E_PACK_INCOHERENT` at resolve time, one
   fixture. This is worth doing on its own merits whatever happens to step 2, and it is the same
   argument v0.8.0 made for mechanisms. It is a **breaking change for any pack carrying a custom
   subtype**, which today means none of the three shipped ones — so the cost is lowest now and rises
   with every pack that ships.
2. Then add the predicate plus the exempt-revenue marker.

**Do not do step 2 without step 1.** A prohibition keyed on a free string is a prohibition that
silently does nothing, and it fails in the direction where nobody looks.

### Alternative considered and rejected

Keep number ranges and copy the rule into each pack. That is what happens today, and with two packs
it is survivable. It stops being survivable somewhere around the fifth, and the cost of the failure
— a rule that silently does not apply to an account somebody added — is a wrong tax return.

---

## Proposal B — conditional constraints

### The three flavours are not one feature

- **Profile** (`smallBusiness`, `taxationMethod`). `TaxService` already skips the tax lines for a
  small business, but a **hand-posted** `1500` line goes through untouched.
- **Legal form.** `de-rechtsformen` declares eight forms with their resolution duties, and the
  module is read by exactly one projection. Nothing stops a `gmbh` posting to `2400 Privat`.
- **Amount.** The GWG threshold, the § 4 Abs. 5 Nr. 1 gift limit, the Kleinbetragsrechnung limit.

They differ in *when* they can be evaluated, and that is what decides the design:

| Flavour | Known when? |
|---|---|
| Legal form | Once, at `setEntityProfile`. Stable. |
| `taxationMethod` | Tenant-level, changeable, not per-date in practice. |
| `smallBusiness` | **Time-segmented** — `TaxProfile` keeps `{validFrom, value}` segments and `setTaxProfile` can add one. A rule conditioned on it has to be evaluated **per posting date**. |
| Amount | Per posting, and needs a comparison against a `Money` with its currency and scale. |

### Options

**Option A — `appliesWhen` on the rule.**

```json
{ "appliesWhen": { "legalForm": ["gmbh", "ug", "ag", "eg"] },
  "whenAccountIn": { "from": "2400", "to": "2400" },
  "forbidAccountIn": { "from": "0000", "to": "9999" },
  "note": "§ … eine Kapitalgesellschaft hat kein Privatkonto" }
```

Data-only, composes with both existing predicates, one concept for profile and legal form.
Conditions are a **closed** set of tenant facts, not an expression language — the moment it becomes
one, a pack carries logic and the whole point of the split is gone.

Cost: the constraint registries are pure today (they see account numbers and dimensions and nothing
else). This gives them a dependency on the tax profile and the entity profile. That is real coupling
and it is the honest price.

**Option B — the manifest selects rule sets per profile.** Coarser, no per-rule condition, no
per-date evaluation. Wrong for `smallBusiness`, which changes mid-life; workable for legal form.
Rejected because it moves the condition from where the rule is (readable) to where the bundle is
(not).

**Option C — leave it to the embedding app.** Cheap and honest, and it is what §5 of the GoBD census
already does for recording deadlines on the same reasoning: German rules with exceptions, reported
rather than enforced.

### Recommendation

**Option A, restricted to `legalForm` and `taxationMethod`. Not `smallBusiness`, not amounts.**

- **Legal form: yes.** Set once, unambiguous, and the rules it unlocks are ones no honest
  bookkeeping wants (`2400` in a GmbH is a vGA waiting to be found by an auditor). The data is
  already in the pack.
- **`smallBusiness`: not yet.** It is time-segmented, so it needs a per-posting-date evaluation
  against a profile the registry does not see — a much larger change for one rule that only catches
  hand-postings. That budget is better spent widening `vatReturn.gapWarnings`, which *already*
  reports movements on tax accounts that carry no tax code — precisely the shape of "a small
  business hand-posted input tax". **What would change this:** an embedding app reporting that the
  case happens in the field. Then the cost calculation flips.
- **Amounts: no, and not later either.** The GWG threshold lives in `de-afa` and the asset expansion
  enforces it. A constraint restating the number would be a second source of truth for it, and the
  root `CLAUDE.md`'s rule about pack data having one owner settles it. If hand-posted `6510` above
  the threshold turns out to matter, the answer is a **projection** naming it, not a duplicated
  number.

Whatever is decided, the restriction has to be **written into the docs with its reasons**, or the
next reader adds `smallBusiness` and amounts as an obvious omission.

---

## Sequencing, if both are wanted

Both touch `constraintData` in `format.schema.json`, and both are format changes. Do them as **one**
bump, not two:

1. Close the subtype vocabulary (a validation change, no format bump).
2. `whenSubtype` / `requireSubtype` / `forbidSubtype` **and** `appliesWhen`, one schema revision, one
   `formatVersion` step.
3. Per new word: one mechanism fixture on a pack the fixture brings itself (the
   `xx-10-constraint-rules-readable` pattern), plus one fixture proving a **shipped** pack actually
   uses it — a capability no pack speaks is not a guarantee, which is the lesson `forbidAccountIn`
   taught by sitting unused for a day.
4. `tenantConfiguration` reports the new rules, so a refusal stays explainable from inside the
   library. Note that it must **not** pin the shipped packs' rule lists in a fixture — that weld is
   what retired `de-entgeltminderung-erzwungen`.

---

## What neither proposal fixes, deliberately

Cross-entry and temporal constraints stay out of the vocabulary: negative cash, a document entered
twice, a discount taken after its deadline, § 15a. The library's answer there is a **projection**,
and there are now three (`cashJournal`, `unfinalizedEntries`, `duplicateVouchers`). Saying so here is
part of the proposal — otherwise the vocabulary gets asked to grow in the one direction it was
explicitly built not to.
