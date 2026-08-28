# us-pack sign-offs (#31) — ✅ DECIDED 2026-08-28

> Six decisions, open since 2026-06-23. All six are now made; **one of them was already built and
> shipped**, and **one is a decision not to change something I would otherwise have changed**. The
> reasoning is kept in full, because a sign-off whose "why" is lost has to be made again.

The rule these were held under: nothing blocked the build, but each becomes quasi-irreversible once a
tenant posts against the pack, so the pack was not recommended for production until they were signed
off. Background (now corrected, see below):
[`knowledge/99-pack-docs/us-pack/offene-entscheidungen.md`](../../knowledge/99-pack-docs/us-pack/offene-entscheidungen.md).

## The decisions

### 1. Account numbers — ✅ approved as shipped

35 accounts: `1xxx` assets · `2xxx` liabilities · `3xxx` equity · `4xxx` revenue · `5xxx` cost of
goods sold · `6xxx` operating expenses. That is *the* US block convention — the default chart of
every US small-business package and of every US bookkeeping text — so a US accountant reads it
without being taught it. Approved unchanged.

**The background document described numbers the pack never shipped** (`3130` Sales Tax Payable,
`3140` Use Tax Payable, `4020`/`4030`/`4040`, `6020` Use Tax Expense). What ships is `2100` Sales Tax
Payable, `2110` Use Tax Payable, `4100` Exempt Sales, `6200` Use Tax Expense — and `3900` is Owner's
Draw, not Deferred Revenue. Those were pre-build proposals that the build improved on and nobody
reconciled. Corrected in the background document; this is the same rot the GoBD census had, one
folder over.

### 2. `USETAX` name and treatment — ✅ approved as shipped

"Use tax" is the correct and universal US term for the tax a buyer self-assesses on an out-of-state
purchase the seller did not collect on. The wiring is right for a reason worth stating: US use tax is
**not** a VAT and carries **no input credit**, so the tax must land on *expense* (`6200`) plus a
*liability* (`2110`) and net to a cost — not to zero. That is exactly what the pack does.

That the engine calls the mechanism `reverse_charge` is an internal name and stays. A dedicated
`use_tax` mechanism would be the same code under a nicer label, and the mechanism repertoire is
closed deliberately (`core/src/CLAUDE.md`): every mechanism is core code in **two** languages, so
adding one to improve a name costs cross-language surface for nothing. The name a user types is
`USETAX`, and that one is right.

### 3. Default taxation method `accrual` — ✅ approved as shipped

Confirmed, and the reason is **sales tax**, not GAAP. In US practice the sales-tax liability
generally arises on the **date of the sale**, whether or not the customer has paid; a cash default
would compute the return on collections and be wrong for the common case. Where a state permits
cash-basis sales-tax reporting it is an **election** — which is precisely what a per-tenant
`taxationMethod` is for, and it stays overridable.

The income-tax method is a separate question and not this default's business: the cash method is
available under IRC § 448(c) up to average annual gross receipts of **$32 M (2026)**, and a business
electing it still reports sales tax on the state's basis. No second manifest (`us-gaap` / `us-cash`):
one flag, per tenant, beats two packs that differ in one value.

### 4. Multi-state — ✅ approved as scoped, and the scope is now stated loudly

One sales-tax rate per tenant. Nexus determination, rate lookup by ship-to address, and
jurisdiction-by-jurisdiction breakdown are **the embedding application's**, usually via a rate
service. This is the industry norm rather than a shortcut: US sales tax has on the order of thirteen
thousand taxing jurisdictions whose rates and boundaries change continuously, and no accounting
library carries that table — the packages that appear to, call a service.

The risk here is not the limit but the *assumption*, so it is now written where somebody choosing the
pack will hit it (`pack-library/us-pack/README.md`), not only in a decisions memo.

### 5. `EXEMPT` — ✅ closed; it was already decided and built

This item asked for a semantic confirmation and then a wiring change plus a fixture. **Both had
happened**: `EXEMPT` has run on `mechanism: exempt` since 0.5.0, with fixture
`pack/us-pack/us-exempt-sale`. Semantics confirmed as built: no tax line at all (not a `0.00` line),
revenue on `4100`, base tagged `EXEMPT_SALES` for the return. The third clause — "not on the EC sales
list" — does not arise: the EC sales list is an EU instrument and the `us` pack ships none.

### 6. Naming — ✅ `us` and `us-accounts-2026` confirmed; `SALETAX` **stays**, deliberately

Manifest id `us` (not `us-complete`) and module id `us-accounts-2026`: confirmed, consistent with
`de`, and `us-complete` was already the cause of one defect (IMPL-034).

**`SALETAX` is the one I would have changed and am not changing.** US usage is "sales tax" without
exception, so the code reads like a typo, and this very sign-off item exists to catch that. It stays
because **nine conformance fixtures name it** while proving the *shipped* pack — and unlike a version
number or an account count, the code is the **input those fixtures drive**, not incidental product
data. Renaming it would leave nine fixtures describing a product that no longer exists, and they pin
behaviour, so they cannot be retired: root `CLAUDE.md` is explicit that a fixture pinning behaviour is
argued with, never retired. Shipping `SALESTAX` *beside* it was the other option and is worse — a
permanent duplicate in the published vocabulary, and a migration story for every embedder, to fix a
cosmetic wart.

**The lesson is the part worth keeping.** This item was marked *open for sign-off* and the
conformance suite pinned it anyway. The contract closed a decision that a human had not made yet, and
by the time anyone looked, the cheap moment had passed. **An identifier under an open sign-off should
not be pinned by a fixture until it is signed off** — or the sign-off is theatre. Nothing enforces
that today; it is a habit, written down here.

## What changed in the product

Nothing. Five decisions confirm what ships, one records a deliberate non-change. The work was in the
documents that were wrong about the product — corrected in the background document and in the pack
README.
