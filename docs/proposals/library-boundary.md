# The library boundary — what belongs in summae, and the criterion that decides it

**State: ⚠️ open — direction confirmed 2026-08-29, three designs proposed, none built.**

This memo came out of a question that started narrow ("should cost accounting move to the
application?") and turned out to be the wrong question asked of a right instinct. It records the
criterion that replaced the one being used, the three decisions it produced, and the work that
follows. The legal gaps it uncovered are a census of their own:
[`../hgb-conformance.md`](../hgb-conformance.md).

---

## 1. The criterion

The boundary was being decided, in practice, by two tests that both fail.

**"Is it bookkeeping?"** is too narrow. Applied honestly it removes the VAT return, the EÜR, the
asset register and the GoBD data carrier — two thirds of the product, and the two thirds a German
embedder actually needs.

**"Is it tenant-dependent?"** is not a test at all. The chart of accounts is tenant-dependent. So
are the mapping, the tax profile, the fiscal year, the dimensions and the legal form. If
tenant-dependence meant *application*, summae would post against nothing.

The criterion that survives contact with the actual surface:

> **summae holds an object when the law requires the books to hold it.**

Worked through, it decides every case in the current surface without appeal to what happens to be
built already — which is what the earlier reasoning kept doing, and what makes the status quo
justify itself:

| Object | Does the law require the books to hold it? | |
|---|---|---|
| Fixed assets | § 240 HGB inventory; depreciation reaches the income statement | ✅ and therefore **their output quantity too** — depreciation by output is a statutory method (§ 7 Abs. 1 Satz 6 EStG). The quantity on `Asset` is not a leak in a money-only core; it is a measurement base. |
| Business partners | § 14 UStG invoice particulars; receivables in the balance sheet | ✅ |
| Vouchers | Belegprinzip; no entry without one | ✅ **the document**, not only its number — see §5 |
| Stock | §§ 240, 253 Abs. 4, 255 Abs. 2 HGB; changes in inventory in the P&L | ✅ — and missing, see §4 |
| Provisions | § 249 HGB, a duty rather than an option | ✅ — and missing |
| Product master, production order, goods movement | nothing requires the *books* to hold them | ❌ application |
| Users, roles, deadlines, approvals | the law requires these of **people**, not of books | ❌ application |

The two failing tests and this one agree on most rows. They disagree on exactly the rows where
summae is incomplete, which is why the disagreement was invisible.

## 2. What the audit found, in both directions

**Application → library: one candidate.** The eleven tables in the app's own store were checked
against the criterion. Seven are clean application obligations with a numbered entry in the app's
`GOBD-APP-OBLIGATIONS.md` (documents, permissions, protocol, Verfahrensdokumentation, internal
control system, reconciliation sign-off, findings). The § 7g Abs. 1 memo item is outside the
balance sheet entirely and correctly placed. The § 15a register is correctly placed too, for a
reason worth keeping: **its trigger is a change of use, which is never posted** — a library that
sees only postings cannot see the day a van starts being driven privately. What is *not* correctly
placed is the arithmetic; see §6.

**Library → application: nothing.** Every one of the 66 declared operations and projections holds
under the criterion. The cases worth arguing were argued and survived: `allocate` writes nothing
but *is* the rounding contract and must be identical across implementations; `duplicateVouchers`
reports on the books rather than on a person; `systemDescription`, `parameters` and
`tenantConfiguration` are the self-describing surface an agent needs and are the original vision,
built. This is stated rather than balanced with an invented counterpart.

**The empirical check agrees.** The application's `FINDINGS.md` records 32 things it needed from
summae and did not get. All are closed, none by a quiet workaround, and every one of them resolved
*into* the library — the allocation scheme moved out of the app's own config file (F-22), the
costing-run table was deleted when `costingRuns` shipped (F-24), the dimension operations were
published rather than reimplemented (F-14). A boundary tested thirty-two times by real use, that
moved in one direction every time, is not a boundary in trouble.

## 3. What was actually wrong

Not scope. **Two unfinished chains and one missing census.**

The cost accounting justifies itself through a chain — cost centres → allocation → overhead rates →
production cost → inventory valuation → balance sheet — and only the last link makes it bookkeeping
rather than controlling. The middle is built and good. **Both ends are missing.** A capability
whose purpose cannot be reached is indistinguishable from an unnecessary one, which is what made it
feel like scope creep.

And nothing asked the balance-sheet question. There is a GoBD census and a GDPR census, both
essentially closed, both machine-checked. Neither asks whether the books can be *valued* correctly,
because that is a different body of law. Every gap in `hgb-conformance.md` was invisible on
2026-08-28 with every gate green.

## 4. Decision — stock valuation belongs in the library

**Not a product master.** The distinction that took the longest to find, and it is the whole design:
summae must be able to *value and post* stock without knowing what is in the warehouse.

The pattern to copy is the asset register. summae does not own the machine; it owns the register and
the postings. The physical facts arrive as input.

1. **One more account subtype: `inventory`.** F-CORE-046 closed the repertoire on 2026-08-28 and
   named the condition for reopening it — *"a pack needing an account role the engine genuinely does
   not have"*. This is that case, and it is the first one.
2. **Pack data.** Stock accounts (raw materials, work in progress, finished goods, merchandise), a
   changes-in-inventory account, the `B. I. Vorräte` position in `de-bilanz`, and § 275 Abs. 2 Nr. 2
   in `de-guv`.
3. **Operation `valuateInventory`**, shaped like `runDepreciation`: it takes a **released** costing
   `runId` (which is where the § 255 components already come from) and the stock categories with
   their **quantities as input**, posts the change in inventory, is idempotent per period, and
   writes its own audit record.
4. **Projection `inventoryValuation`**: what was valued, out of which components, at what rate —
   including the components that were excluded and why, exactly as `productionCost` already reports
   them. A valuation that shows only its own total cannot be checked against § 255.

Deliberately outside: goods movements, bills of material, production orders, product master.
**The quantity is an input, never a stock.** summae never claims to know what is in the warehouse —
only what was valued and booked.

**Left open on purpose:** § 256 consumption sequence (Fifo/Lifo) needs the history of entry values,
which needs a stock record, which this design does not keep. The first cut is weighted average from
the run's production cost. Whether that is enough is row 6 of the census's open list, and it is the
row that decides how far the library goes. It must not be closed by building it quietly.

## 5. Decision — the voucher document belongs behind a library port

This reverses the position taken earlier in the same conversation, and the reversal is the useful
part. The earlier objection was that binary data is a different kind of problem for a library —
size, retention, deletion. True, and it dissolves against this project's own architecture:
**summae stores no SQL either; it owns the repository port.**

The reason this is more than convenience: summae *enforces* the Belegprinzip — no entry without a
`voucherId`, `E_ENTRY_NO_VOUCHER` — and cannot see the voucher. It checks that a **string** exists.
That is the same defect shape as the six findings closed on 2026-08-28: a field the engine reads
and nothing validates.

Proposal: a `DocumentStore` port in the core, adapters outside, exactly as persistence works.
The library owns the *contract* that a voucher has a retrievable document; where the bytes live
is the adapter's answer.

**Honest cost:** a second immutability and retention regime, for binary data, including erasure
under GDPR. That is real work. It is also the same regime the journal already has.

**Consequence if this is *not* done:** it has to be written down where it bites. If the
application's store is lost, the books are complete and **unprovable** — and that belongs in the
application's backup obligation (A-10) explicitly, not as an implication.

## 6. Decision — the § 15a arithmetic belongs in the library

Register and deadline stay with the application (§2). The computation does not, and the argument
that put it there is the argument against: *"a figure produced wrongly would look exactly as
authoritative as one produced rightly."* That is a reason to compute it where figures are
fixture-pinned, deterministic and verified across two languages — not a reason to compute it
nowhere.

The layering is the proof that it belongs here: the **mechanism** (pro-rata adjustment over a
correction period with de-minimis thresholds) is core; the **numbers** (five and ten years, ten
percentage points, €1,000) are `de` pack data. The `us` pack simply has no such module. A rule with
the same mechanics and different numbers per jurisdiction is the definition of a pack.

Consequence: the €1,000 threshold becomes applicable instead of being stated in a reminder's text
because nobody has the figure it applies to.

## 7. Cost accounting, after all this

The 2026-08-23 scope decision holds and this memo does not reopen it. Three *decision-support*
methods stay out (planned cost and variance, activity-based costing, contribution margin). The
balance-sheet part stays in — and `out-of-scope.md` already says so explicitly, including
*"Zuschlagskalkulation Kostenstelle → Kostenträger"*, which an earlier draft of this memo nearly
reversed without noticing it was written down.

What changes is the honesty of the label and the order of the work:

- The capability is a **cost-centre accounting** with a balance-sheet outlet, not a KLR. There is no
  cost-object stage and no activity side (no revenue, no output). `api.md` should stop listing
  `costObjectReport` as an intended target state.
- **F-KLR-002 (Abgrenzungsrechnung) remains in scope and unbuilt** — the intake stage the built
  stages stand on. Today primary costs are expense lines at book value, so *cost equals expense* and
  the anticorruption layer the context map describes does not happen.
- Before it is built, one question has no answer in any document: **single-circle or two-circle
  system?** Whether the costing circle is a computation with configuration, or a second ledger on
  the same substrate where an allocation is literally a posting and "auxiliary centre ends at zero"
  is an account balance. The second is attractive and is the classical German answer (GKR/IKR class
  9), and it doubles period logic, finalization, audit and persistence. It hangs on whether the
  municipal pack is still coming, which is not written anywhere.

## 8. The seed

None of the above can be looked at without data. The current seed is one month of 2026 and about
fifteen vouchers, plus an empty US tenant, so most screens are empty and no annual close can be
reached.

What a seed has to reach to exercise this memo:

- a **full fiscal year**, every period posted, so `closePeriod` ×12 → `finalize` → `closeFiscalYear`
  → `appropriateResult` runs end to end
- an asset acquired and depreciated, and one depreciated **by output**, so the quantity path is real
- cost centres carrying primary costs across several periods, an allocation scheme with an auxiliary
  and a main centre, a costing run per quarter with one released
- quarterly VAT, a consideration reduction (A-13), an intra-community supply for the EC sales list
- deliberate findings: a document entered twice, a reversal, a partly settled item
- on the application side: voucher files, a signed-off period, a § 15a memo item and a § 7g one

It stays in the application (`scripts/seed.ts`) and keeps going through the same setup path the
screen uses. **Realistic data is worth the effort here** — invented round numbers hide exactly the
rounding and allocation behaviour this library exists to get right.

**Separately:** summae's own walkthrough scenarios cover posting and tax and touch **neither assets
nor cost accounting**, so the handbook pages for `setAllocationScheme` and the three cost-accounting
projections are ungated. A scenario for each is cheap and gates what this memo is about.

## 9. Order of work

1. **A gate for `hgb-conformance.md`**, in the shape `GobdConformanceDocTest` already has. Without
   it the census is prose and rots — which is how the VAT row in the GoBD census spent five green
   days describing a product that had moved.
2. **Bewertungsstetigkeit** (census row 1): a costing run records the election it was computed
   under. Small, and it protects the one measurement option that ships.
3. **Stock** (§4 here, census row 2). Closes a balance-sheet hole and the cost-accounting chain with
   one piece of work.
4. **Provisions** (census row 3). The other missing main position; asset-register sized.
5. **Write-up obligation** and the **RAP release schedule** (census rows 4 and 5). Both small, both
   the depreciation pattern.
6. **§ 15a arithmetic** (§6 here).
7. **`DocumentStore` port** (§5 here) — or the written decision not to, with the consequence placed
   in the application's A-10.
8. **Abgrenzungsrechnung**, after the single-circle/two-circle question is answered.

Foreign currency (census row 11) is a decision before it is a task and is deliberately not in this
order.
