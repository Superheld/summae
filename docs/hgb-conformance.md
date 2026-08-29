# HGB conformance — can these books be *valued* correctly?

**Status: 2026-08-29.** Reference: HGB, Drittes Buch (§§ 238–289f), in the version in force on
that date, with the tax provisions that govern the same figures named where they diverge
(EStG §§ 5–7g).

This document exists for a situation the other two censuses do not cover, and the gap was
structural rather than accidental. [`gobd-conformance.md`](gobd-conformance.md) answers *"is this
bookkeeping orderly, traceable and unalterable?"* — process. [`gdpr-conformance.md`](gdpr-conformance.md)
answers *"what personal data is in here?"*. Neither ever asks **"is the balance sheet right?"**,
which is a different body of law, and so nothing in this repository asked it either. Every ⚠️ row
below was invisible on 2026-08-28 with both other censuses essentially closed and every gate green.

Status vocabulary, identical to its two siblings:

| Status | Meaning |
|---|---|
| ✅ **verified** | A named fixture or test fails if this stops being true. |
| 🟡 **partial** | The parts are there and the mechanism that keeps them right is not. Says which half is which. |
| ⚠️ **open** | summae should do this and does not. Named and scoped, never hidden. |
| ➖ **not verifiable here** | Cannot be discharged by a library — an organizational or application obligation. Listed so it is not mistaken for covered. |

> **The rule that governs this file:** a ✅ means a *machine* checks it. A ⚠️ is deleted only when
> it is built, never because it is inconvenient. And a 🟡 is the most useful status here: it marks
> the places where a chart of accounts carries the right account and nothing carries the *rule*,
> which is the exact shape most of the findings below have.

> **Scope of the claim.** summae is a bookkeeping library. It can hold what the books must hold and
> compute what the law fixes. It cannot count a warehouse, sign a balance sheet, or judge whether a
> provision is probable. Those are ➖ and they are not defects.

---

## 1. Bookkeeping and inventory (§§ 238–241)

| Obligation | Status | Where |
|---|---|---|
| § 238 Abs. 1 — the transactions and the state of assets are ascertainable | ✅ | `core/post-and-invariants`, `core/journal-projection`; balances are always recomputed, never stored |
| § 239 Abs. 2 — complete, correct, timely, ordered | ✅ | `core/period-ordering`, `core/unfinalized-entries`; the deadline is reported, never enforced — see `gobd-conformance.md` §5 |
| § 239 Abs. 3 — no change without the original remaining ascertainable | ✅ | `core/finalize-reverse-period`, `core/audit-hash-chain` |
| § 240 Abs. 1 — inventory: **fixed assets** | ✅ | `assetRegister`; `assets/gwg-and-depreciation`, 16 fixtures in `assets/` |
| § 240 Abs. 1 — inventory: **receivables and payables** | ✅ | `openItems`; `core/open-items-settlement`, `core/open-items-partner-and-due` |
| § 240 Abs. 1 — inventory: **cash and bank** | ✅ | `cashJournal`, `trialBalance`; `core/money-transit` |
| **§ 240 Abs. 1 — inventory: stock (Vorräte)** | ✅ | `valuateInventory`, `inventoryValuation`; `inventory/stock-valuation`, `pack/de-pack/de-vorraete`. The `inventory` subtype is the twelfth in the closed repertoire and the first value added on the condition F-CORE-046 named for reopening it. **What is deliberately absent stays absent:** summae values and books stock, it does not keep a stock ledger — quantities are input to one act and are not carried forward. Permanent inventory therefore still needs a system that counts (§ 241 below). |
| § 240 Abs. 3 — Festwert (fixed quantity carried at a fixed value) | 🟡 | Expressible, not supported: a fixed quantity at a fixed value is `valuateInventory` with the same `quantity` and `unitCost` every period, which books nothing after the first — arithmetically right, and nothing in summae *knows* it is a Festwert or enforces the three-year re-count. A simplification, not a duty. |
| § 240 Abs. 4 — Gruppenbewertung (weighted average for like items) | 🟡 | The mechanism is there — a category *is* a group, valued at one `unitCost` — and nothing computes the weighted average for you. That computation needs the entry-value history summae deliberately does not keep, which is the same wall as § 256 (row 6). |
| § 241 — stocktaking simplifications (sampling, permanent, shifted) | ➖ | These are procedures for *counting*, and counting happens in the warehouse. **Permanente Inventur is the exception that is not purely ➖**: it requires a continuous stock record, and summae deliberately keeps none (see `proposals/library-boundary.md`). Whoever runs it needs a system that does. |

## 2. The annual accounts (§§ 242–245)

| Obligation | Status | Where |
|---|---|---|
| § 242 Abs. 1 — balance sheet at the start and end of the business | ✅ | `balanceSheet`; `projections/balance-sheet-mapping`, `core/opening-balance-takeover` |
| § 242 Abs. 2 — income statement | ✅ | `incomeStatement`; `projections/monthly-income-statement` |
| § 243 Abs. 2 — clear and well arranged | ➖ | The arrangement comes from the pack's mapping. summae guarantees no account is dropped (`projections/balance-sheet-gap`, IMPL-017) but cannot judge whether a layout is *klar und übersichtlich*. |
| § 244 — German language, Euro | 🟡 | Statement labels come from the pack and the shipped `de` mappings are German. The currency is the tenant's and nothing ties it to the jurisdiction of its pack — a `de` tenant can be opened in USD and no rule objects. |
| § 245 — signature by the merchant | ➖ | An act by a person. The application's. |

## 3. Recognition (§§ 246–251)

| Obligation | Status | Where |
|---|---|---|
| § 246 Abs. 1 — completeness: **all** assets, debts, prepayments, expenses and income | ✅ | `projections/balance-sheet-mapping`, `pack/de-pack/de-bilanz-guv`, `inventory/stock-valuation`, `provisions/provisions`. Both main positions that were missing on 2026-08-28 — stock and provisions — are in, with an operation behind each. What remains open elsewhere in this document is measurement detail, not a class of thing the balance sheet cannot hold. |
| § 246 Abs. 2 — **prohibition of offsetting** (assets against liabilities, expenses against income) | 🟡 | `projections/mapping-offsetting`. A balance-sheet position may no longer draw from both sides — resolver invariant I11 in a pack, `E_MAPPING_SIDE_MIXED` at `importMapping`. Checked on the account *type*, not the balance: an overdrawn bank account is still an asset account and offsets nothing, and a rule over balances would refuse ordinary books. **Still 🟡 and not ✅** because the provision has a second half this does not reach — expenses against income. An income statement has no sides to check, so the same guard has nothing to bite on; a caller who nets a revenue against an expense before posting is beyond what a mapping can see. |
| § 247 Abs. 1 — fixed assets, current assets, equity, debts, prepayments shown separately | ✅ | `projections/balance-sheet-mapping`, `pack/de-pack/de-bilanz-guv`. The current-asset side now carries stock (`A.Ia`) beside receivables, securities and cash. |
| § 248 Abs. 1/2 — capitalisation prohibitions; the option for internally generated intangibles | ⚠️ | Not expressible. The `productionCost` treatment table is the right mechanism and covers only production cost. |
| **§ 249 — provisions (Rückstellungen)** | ✅ | `recognizeProvision`, `useProvision`, `releaseProvision`, `remeasureProvision`, `provisionRegister`; `provisions/provisions`, `pack/de-pack/de-rueckstellungen`. Four operations rather than one "adjust", because § 249 Abs. 2 Satz 2 distinguishes *use* from *release* and the accounts do too: a release is income the business never had to pay. The register keeps the movement list, which is what a netted account balance cannot show. |
| § 250 — prepaid and deferred items (RAP) | ✅ | `recognizeDeferral`, `runDeferralRelease`, `deferralRegister`; `core/deferral-release`, `pack/de-pack/de-rechnungsabgrenzung`. The accounts were never the gap — the plan was. The release run has the depreciation run's shape on purpose, down to `alreadyRun`, and is idempotent because each deferral records which periods it has released rather than inferring it from a balance. |
| § 251 — contingent liabilities below the balance sheet | ⚠️ | Not expressible. Nothing in the books, by construction — but the balance sheet must show it, and the projection has no place for it. |

## 4. Measurement (§§ 252–256a)

| Obligation | Status | Where |
|---|---|---|
| § 252 Abs. 1 Nr. 1 — opening balance equals prior closing balance | ✅ | `core/opening-balance-takeover`, `core/two-year-carryover` |
| § 252 Abs. 1 Nr. 3 — individual measurement at the reporting date | ✅ for what exists | Assets are measured individually (`assets/*`). Untestable for stock while stock does not exist. |
| § 252 Abs. 1 Nr. 4 — prudence, realisation, **imparity** | 🟡 | Two of its three carriers are built: provisions (§ 249) and the lower-of-cost-or-market for stock (§ 253 Abs. 4). Foreign currency (§ 256a) is still unreachable, and receivables still have no allowance — so the principle now holds where the mechanisms exist and is stated nowhere as a principle. |
| § 252 Abs. 1 Nr. 5 — accrual (expense and income in the period they belong to) | ✅ for what the books can carry | `core/deferral-release`. Both directions are mechanised: a prepaid expense and a deferred income, each with a plan the machine remembers. What summae still cannot do is *notice* that something ought to be accrued — that judgement is the preparer's, and no library makes it. |
| § 252 Abs. 1 Nr. 6 — **consistency of measurement methods** (Bewertungsstetigkeit) | ✅ | `measurementConsistency`; `costing/measurement-consistency`. It walks the released costing runs, states the basis each was computed under (`included` / `elected`) and reports every change with `acrossFiscalYears`. **Half of this was already built and the earlier ⚠️ overstated the gap:** a released run has always *frozen* its components with the pack's treatment, so the record existed — what did not exist was anybody comparing two records. It **reports** rather than refuses, and that is Abs. 2 rather than leniency: the same provision that demands consistency permits a justified departure, so a refusal would enforce half a rule. Electing a component with no base configured is now `E_INPUT_INVALID` instead of silently inert. |
| § 253 Abs. 1 — recognition at acquisition or production cost | ✅ | `acquireAsset` (`assets/gwg-and-depreciation`); production cost components `costing/production-cost` |
| § 253 Abs. 2 — discounting of provisions with more than a year to run | ✅ | `provisions/provisions`. The split is *not* the one this row predicted, and the correction is worth recording: the **rule** is pack data (from what remaining term, with its citation), the **rate** is an input per act. It is published monthly, so a number in a pack file would be stale before anybody upgraded — and a stale legal rate that looks authoritative is worse than an absent one. A provision that must be discounted and carries no rate is refused by name (`E_PROVISION_DISCOUNT_RATE_REQUIRED`), never booked undiscounted. |
| § 253 Abs. 3 — scheduled and unscheduled depreciation of fixed assets | ✅ | `runDepreciation`, `writeDownAsset`; `assets/asset-write-down`, `assets/declining-balance-depreciation`, `assets/special-depreciation` |
| § 253 Abs. 4 — **strict lower-of-cost-or-market for current assets** | 🟡 | **For stock it holds:** `valuateInventory` takes a `marketValue` per unit, carries the lower of it and the cost, and says which it took (`unitValue`, `writtenDownToMarket`) — `inventory/stock-valuation`. **For receivables it does not:** `6700 Forderungsverluste` writes an item *off*, which is a different act from valuing it down at the reporting date, and there is no allowance, neither specific nor general. |
| § 253 Abs. 5 — **write-up when the reason for a write-down ceases** | ✅ | `writeUpAsset`; `assets/asset-write-up`. Two caps: nothing may be written back that was not written down, and the book value may not exceed the **amortised acquisition cost**. The second needed a shadow plan on the asset, which the census did not foresee and which is the interesting part — a write-down also lowers every remaining instalment, so the book value drifts *above* the untouched plan and a full reversal years later would carry the asset over its cost. Whether the reason has ceased stays an appraisal and therefore an input; the ceiling is arithmetic and is enforced. |
| § 254 — hedging units (Bewertungseinheiten) | ⚠️ | Not expressible. Rare, and named rather than omitted. |
| § 255 Abs. 1 — acquisition cost, including incidental costs and reductions | ✅ | `acquireAsset`; `core/settlement-discount` for the reduction side |
| § 255 Abs. 2/3 — **production cost**: mandatory parts, options, prohibitions | ✅ **for the figure** | `costing/production-cost`, `pack/de-pack/de-herstellungskosten`, `pack/us-pack/us-inventory-costing`. The treatment table is the model case of this project's layering — the core adds up, the pack says what may enter. |
| § 255 Abs. 2/3 — **and the figure reaches the balance sheet** | ✅ | `inventory/stock-valuation`. `valuateInventory` takes a **released** run's production cost and a produced quantity, derives the unit value, books the change to the stock account and the account the pack names — and the figure lands in `A.Ia` of `de-bilanz` and in position `1a` of `de-guv`. The handbook's claim about *"the one cost-accounting figure that reaches the balance sheet"* is true now; until 2026-08-29 it was true only if the embedding application posted it to an account it invented. |
| § 255 Abs. 2a — development costs separated from research | ⚠️ | Follows § 248 Abs. 2. |
| § 256 — **consumption sequence (Fifo, Lifo)** | ⚠️ | Follows the stock work, and is the hard part of it: a consumption sequence needs the history of entry values, which needs a stock record. See §7 for the first cut and what it defers. |
| § 256a — **foreign currency translation at the reporting date** | ⚠️ | summae holds **one currency per tenant**; `Money` refuses arithmetic across currencies (`CurrencyMismatch`), which is right for a total and leaves no room for a receivable in USD. A German business with one foreign-currency invoice cannot represent its books here. **This one is a decision before it is a task** — see §7. |

## 5. Presentation for corporations (§§ 264–278)

Applies to Kapitalgesellschaften. A sole trader or partnership below the § 241a thresholds
owes none of it, which is why these rows are separated rather than mixed into §§ 3 and 4.

| Obligation | Status | Where |
|---|---|---|
| § 264 Abs. 1 — notes (Anhang) and management report | ➖ | Documents about the business, not derivable from the journal. The application's, and they need figures this library can supply. |
| § 266 Abs. 2/3 — **the prescribed balance-sheet layout** | ✅ for the main positions | `pack/de-pack/de-bilanz-guv`, `provisions/provisions`. Stock arrived as `A.Ia` (2026.4) and provisions as `P.B` (2026.5). Two things are worth recording about *how*. Stock was inserted with a letter suffix rather than by renumbering what follows it, because a mapping key is an identifier somebody may have stored while the *order* of the statement comes from the order of the array — renumbering would have made the statement right and every stored reference silently wrong. Provisions needed no such trick: `P.B` had been left free from the beginning, because the liabilities side already followed § 266's own letters. Whoever left that gap was working for this day. What is still not § 266 in full is the *depth* — equity is one position where Abs. 3 A. has five (§ 272, row 10). |
| § 268 Abs. 2 — **the fixed-asset movement schedule (Anlagengitter)** | ⚠️ | `assetRegister` reports the *stock*: acquisition cost, accumulated depreciation, book value, at a cutoff date. § 268 Abs. 2 wants the *movement*: opening cost, additions, disposals, transfers, write-ups, depreciation of the year and cumulative, closing value. The data is all in the journal; the projection that shapes it is not written. |
| § 272 — equity, shown by its components | 🟡 | The `de-bilanz` mapping has one equity position plus the result. Subscribed capital, reserves and loss carried forward are not separated — adequate for a GbR, not for a GmbH. |
| § 275 Abs. 2 — **income statement, Gesamtkostenverfahren** | 🟡 | **Nr. 2 changes in inventory arrived** (`de-guv` 2026.2, key `1a`) and is where the stock valuation books. Still missing: Nr. 3 own work capitalised, Nr. 12/13 interest, and taxes on income — row 9 of §7. |
| § 275 Abs. 3 — Umsatzkostenverfahren | ⚠️ | Not offered. It is a second mapping, not a second mechanism — cheap once a business asks. |
| § 277 Abs. 3 — unscheduled depreciation shown separately | 🟡 | `writeDownAsset` still falls back to the ordinary depreciation account when the pack names none, so the two remain indistinguishable for a pack that says nothing — the shipped `de` pack does name one. Note the deliberate asymmetry with the **write-up**, whose income account is *required*: a write-down without its own account lands on depreciation, which is merely less informative, while the only nearby income account is *gain on disposal* and a write-up is not one. |

## 6. What the tax accounts add (EStG)

Named because they govern the same figures and diverge, not to claim tax scope — summae does no
tax determination beyond VAT.

| Provision | Status | Where |
|---|---|---|
| § 5 Abs. 1 — authoritativeness of the commercial balance sheet | ➖ | A relationship between two balance sheets. summae keeps one. |
| § 6 Abs. 1 Nr. 1b — the § 255 Abs. 2 Satz 4 election must match in both | ➖ | A relationship between two balance sheets, like § 5 Abs. 1 above; summae keeps one. What it *can* now do is state the election a given run used (`measurementConsistency`), which is the input somebody comparing the two needs — the comparison itself is not reachable here and is not claimed. |
| § 6 Abs. 1 Nr. 2a — Lifo permitted for tax | ⚠️ | Follows § 256. |
| § 7 — depreciation, straight-line and declining balance | ✅ | `assets/declining-balance-asset-class`, `assets/useful-life-override` |
| § 7 Abs. 1 Satz 6 — depreciation by output | ✅ | `assets/units-of-production` — and the reason the quantity on an asset is bookkeeping data rather than an exception |
| § 7g Abs. 5 — special depreciation | ✅ | `assets/special-depreciation`, `assets/asset-register-special-depreciation` |
| § 7g Abs. 1/2 — Investitionsabzugsbetrag | ➖ | Outside the balance sheet entirely; it never touches these books. Carried by the application (`GOBD-APP-OBLIGATIONS.md` A-15). |
| § 15a UStG — input-tax adjustment | ⚠️ | The register and the deadline are the application's — the trigger is a change of use, which is never posted. **The arithmetic is not**, and it is open here: a mechanism with pack-supplied periods and thresholds. Also named in `gobd-conformance.md` §4 and `proposals/de-pack-vat-completeness.md`. |

---

## 7. The open list, in one place

Ordered by what unblocks what, not by severity.

| # | Item | Size | Why it is where it is |
|---|---|---|---|
| ~~**1**~~ | ~~**Bewertungsstetigkeit (§ 252 Abs. 1 Nr. 6)**~~ — **built 2026-08-29** | — | `measurementConsistency` + `costing/measurement-consistency` (F-CORE-049). The row stays rather than being deleted, for what it got wrong: it said a run does not record its election. A run has recorded it since runs were persisted; the missing half was the *comparison*. The size estimate was right for the wrong reason, which is an argument for reading a census row against the code before believing it. |
| ~~**2**~~ | ~~**Stock: recognition, measurement, posting**~~ — **built 2026-08-29** | — | `valuateInventory` + `inventoryValuation` + the `inventory` subtype + `de-vorraete`/`us-inventory-accounts` + the two mapping positions (F-CORE-050). Built as sketched, with one thing the sketch did not foresee: the valuation had to become a **persisted aggregate**, not just a posting. An engine that books a change in stock and keeps no record of how it reached the number does exactly what this project refuses to let an embedder do, one level down. § 256 Fifo/Lifo stays deferred and stays row 6. |
| ~~**3**~~ | ~~**Provisions (§ 249, § 253 Abs. 2)**~~ — **built 2026-08-29** | — | Four operations, a register, an aggregate with its movement list, the `provision` subtype and `de-rueckstellungen` (F-CORE-051). The row's guess about the discount rate was wrong in an instructive way — see § 253 Abs. 2 above: the rule is pack data, the rate cannot be, because it is republished monthly. |
| ~~**4**~~ | ~~**Write-up obligation (§ 253 Abs. 5)**~~ — **built 2026-08-29** | — | `writeUpAsset` (F-CORE-052). Called "small" here and it was not: the amount and the reversal are trivial, the *ceiling* is not. It needed a shadow depreciation plan stored on the asset, because after a write-down rebases the live plan the original instalments are recoverable from nothing else. Third row in a row whose size estimate was wrong for an interesting reason. |
| ~~**5**~~ | ~~**Release schedule for prepaid/deferred items (§ 250, § 252 Abs. 1 Nr. 5)**~~ — **built 2026-08-29** | — | `recognizeDeferral` + `runDeferralRelease` + `deferralRegister` (F-CORE-053). The size estimate was right this time, and the reason is worth noting after three rows where it was not: the pattern really did transfer whole, because the *shape* of the problem — an amount spread over known periods, booked one period at a time — was identical. Where the estimates went wrong before, it was because the row described the missing feature and not the state it had to be reconciled with. |
| **6** | **Consumption sequence (§ 256, § 6 Abs. 1 Nr. 2a EStG)** | open question | Needs entry-value history, which needs a stock record, which the boundary says summae does not keep. Either the first cut (weighted average from the run's production cost) is enough, or the boundary moves. **Do not close this by building it silently — it is the row that decides how far the library goes.** |
| ~~**7**~~ | ~~**Offsetting prohibition (§ 246 Abs. 2)**~~ — **half built 2026-08-29** | small | The balance-sheet half is done (F-CORE-054, resolver invariant I11 plus `E_MAPPING_SIDE_MIXED`). The row stays, reduced, for the half that is not: **expenses against income** has no equivalent surface — an income statement has no sides, and netting before a posting is invisible to a mapping. Whether that half is reachable at all is worth deciding rather than leaving as an unremarked gap. |
| **8** | **Anlagengitter (§ 268 Abs. 2)** | medium | A projection over data that is all present. |
| **9** | **§ 275 Abs. 2 completeness** in the shipped `de-guv` | small | Four missing lines, three of which need accounts the chart does not have yet. Partly falls out of row 2. |
| **10** | **§ 272 equity components**, **§ 275 Abs. 3 Umsatzkostenverfahren**, **§ 251 contingent liabilities**, **§ 248 Abs. 2 / § 255 Abs. 2a intangibles**, **§ 240 Abs. 3/4 Festwert and group measurement**, **§ 254 hedging units**, **§ 277 Abs. 3 separate disclosure** | mixed | Real, none of them blocking. Listed so the census is a census and not a to-do list of what was convenient to find. |
| **11** | **Foreign currency (§ 256a)** | decision first | A single-currency bookkeeping library is a defensible product. What is not defensible is leaving this as an unremarked hole. Either it becomes ➖ *deliberately not*, with the consequence stated, or it is the largest change in this list — it reaches `Money` and therefore everything. |
| **12** | **§ 15a UStG arithmetic** | medium | Decided 2026-08-29 to belong here: mechanism in the core, periods and thresholds as `de` pack data, register and deadline staying with the application. |

## 8. The facts this document asserts, held against the product

Everything above is prose, and prose is where a census rots. Its two siblings each learned that the
hard way — §4 of the GoBD census named two tax codes as missing that had been built hours earlier and
went on saying so through five green builds. The rows are argued in prose deliberately; the **facts
inside them** do not have to be.

`HgbConformanceDocTest` / `hgb-conformance-doc.test.ts` parses this table and compares every row
against its real source. Values are separated by spaces and the order must match the source.

**This table is inverted relative to its siblings, and that is the whole point of it.** The GoBD and
GDPR censuses are mostly ✅, so their facts table guards claims of *presence*. This one is mostly ⚠️,
so most of what it asserts is **absence** — that the engine has no `valuateInventory`, that the German
balance sheet has five asset positions and none of them is stock. An absence nothing checks is how a
census becomes a wish list: somebody builds the thing, nobody opens this file, and the row goes on
describing a hole that was filled. Here the opposite happens. **Building any row below turns this gate
red**, and the only way back to green is to open this document and move the row to ✅ with its
evidence named. The gate does not merely notice progress; it refuses to let progress go unrecorded.

| Claim | Source | Value |
|---|---|---|
| engine account subtypes | `AccountSubtype::all()` / `allAccountSubtypes()` | `bank` `cash` `transit` `ar` `ap` `tax_in` `tax_out` `result_allocation` `inventory` `provision` `fixed_asset` `opening_balance` `private` |
| operations the engine does not have | `testing/testsuite/schema/api-parameters.json` → `operations` | `adjustInputTax` |
| projections the engine does not have | `testing/testsuite/schema/api-parameters.json` → `projections` | `assetSchedule` |
| `de` balance sheet, asset positions | `pack-library/de-pack/mappings/de-bilanz.json` | `A.I` `A.Ia` `A.II` `A.III` `A.IV` `A.V` |
| `de` balance sheet, liability positions | `pack-library/de-pack/mappings/de-bilanz.json` | `P.A1` `P.A2` `P.B` `P.C` `P.D` |
| `de` income statement positions | `pack-library/de-pack/mappings/de-guv.json` | `1` `1a` `2` `3` `4` `5` `6` |
| `de` chart, subtypes actually used | `pack-library/de-pack/accounts/de-konten.json` | `ap` `ar` `bank` `cash` `fixed_asset` `inventory` `opening_balance` `private` `provision` `result_allocation` `tax_in` `tax_out` `transit` |
| `de` pack, module kinds | `pack-library/de-pack/de.json` → `modules` | `accounts` `assetAccounts` `constraint` `deferrals` `depreciation` `inventory` `legalForms` `mapping` `policy` `productionCost` `provisions` `resultAppropriation` `tax` |

This paragraph has now been rewritten twice in one day, both times because the gate refused to go
green until it was — which is the mechanism doing exactly what it exists for. On the morning of
2026-08-29 it read *eleven subtypes with no `inventory`, five asset positions with no stock, ten
module kinds with no provision*. All three sentences are obsolete. What the rows say now is: thirteen module
kinds with **no input-tax adjustment** among them, one operation still missing (the § 15a
correction), and one projection (the fixed-asset movement schedule, § 268 Abs. 2).

---

## 9. What this document is not

It is **not a claim that summae is unsuitable for bookkeeping.** Every ⚠️ above concerns a business
that holds stock, forms provisions, invoices in foreign currency, or files under § 266. A service
business on a cash basis meets none of them, and for that business the ✅ rows are the whole story.

It is **gated** since 2026-08-29, in the shape its two siblings already had:
`HgbConformanceDocTest` / `hgb-conformance-doc.test.ts` check that every fixture named above exists
and still runs, that no fifth status symbol appears, and that every fact in §8 matches its source.
What that gate cannot say is whether the *law* is stated correctly — only that the document is not
wrong about the product it describes.
