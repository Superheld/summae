# The four questions the HGB work left open — three decided, one not

**State: three decided 2026-08-29, one awaiting a product answer.**

Ten of the twelve rows of [`../hgb-conformance.md`](../hgb-conformance.md) §7 were built on
2026-08-29. What remains is not work that was skipped: it is four questions where *building* would
have been the wrong first move, because the answer decides how far the library goes. This memo puts
each one down with its consequence, so none of them survives as an unremarked gap.

> **Why one file rather than four census rows.** A census row says what is missing. A decision needs
> the options, the cost of each and a recommendation — that is a different document, and mixing the
> two is how a census turns into a to-do list of what was convenient to find.

---

## 1. Consumption sequence — Fifo and Lifo (§ 256 HGB, § 6 Abs. 1 Nr. 2a EStG)

**Decided: not built, and the boundary holds.** Census row 6, which said in as many words: *do not
close this by building it silently — it is the row that decides how far the library goes.* It is
closed by deciding, not by building.

**Why the boundary holds.** A consumption sequence needs the history of entry values: what each
delivery cost, in what order, so that a withdrawal can be charged at the oldest or the newest. That
is a **stock record** — a running ledger of quantities and their costs — and `valuateInventory` was
designed around not keeping one. The criterion decides it cleanly: *summae holds an object when the
law requires the books to hold it.* § 256 does not require a consumption sequence. It **permits**
one as a simplification of individual measurement, which is the rule (§ 252 Abs. 1 Nr. 3). A
simplification the law offers is not an object the law requires the books to hold.

**What an embedder does instead, and it is not a workaround.** The unit value is an input. A business
running Fifo computes its unit cost where the goods movements actually are — in the system that
records deliveries — and passes it to `valuateInventory`. summae books the valuation, keeps the act,
and reports which basis was used; `measurementConsistency` then catches the year somebody switched
from Fifo to weighted average without saying so, which is the part the law is actually strict about
(§ 252 Abs. 1 Nr. 6).

**What this costs, stated plainly.** A business whose only stock system *is* its accounting system
cannot run Fifo inside summae. That is a real limit and it is the same limit as "summae does not
count a warehouse". It is written here rather than discovered.

**What would reopen it:** a stock record arriving for another reason. If permanent inventory
(§ 241 Abs. 2 HGB) is ever built — and it needs exactly the same running record — Fifo becomes a
projection over data that is already there, and the argument above expires with the premise.

---

## 2. The voucher document (`DocumentStore`)

**Decided in `library-boundary.md` §5 — it belongs behind a library port. Not yet built, and the
open question is not *whether* but *under which retention model*.**

The reasoning stands and is not re-litigated here: summae *enforces* the Belegprinzip — no entry
without a `voucherId`, `E_ENTRY_NO_VOUCHER` — and cannot see the voucher. It checks that a **string**
exists. That is the same defect shape as the six findings closed on 2026-08-28: a field the engine
reads and nothing validates. And the objection that binary data is a different kind of problem for a
library dissolves against this project's own architecture: summae stores no SQL either; it owns the
repository port.

**Why it was not built today, and this is a judgement rather than a shortage of time.** A
`DocumentStore` drags in a second immutability regime and a second retention regime — for bytes,
including erasure under Art. 17 GDPR, which the partner erasure (F-CORE-040) shows is never
mechanical. Three questions have no answer in any document here:

- **Does deleting a document leave a shell, as a redacted audit record does?** The trail keeps the
  *fact* of an erasure precisely so a lawful deletion stays distinguishable from a manipulation. A
  document store without that has a hole where an auditor looks.
- **Is the document hashed into the audit chain?** If yes, an erasure breaks the chain unless it is
  modelled like the redaction case. If no, "the books are provable" is weaker than it sounds.
- **Who owns retention — the library or the embedder?** Ten years is German; the port is not.

Those are the kind of answers that are cheap to get wrong and expensive to migrate.

**The consequence while it is unbuilt has been written where it bites.** If the application's
document store is lost, the books are complete and **unprovable**. That belongs in the application's
backup obligation (A-10) explicitly, not as an implication — and it is the one action item this
section carries.

---

## 3. Foreign currency (§ 256a HGB) — ⚠️ **awaiting a product answer**

**Not decided, because it is not mine to decide.** This is the one place in the whole HGB list where
the honest move is to put the question rather than an answer.

**What is true today.** `Money` is `(amount, currency)` and refuses arithmetic across currencies;
`Tenant.baseCurrency` is singular. A German business with one invoice in dollars cannot represent its
books here. That is not a rounding limit — it is structural.

**Option A — declare it deliberately out of scope, and say so in the census as ➖.**
*Cost:* the product is honestly described and nothing changes. *Consequence:* summae serves
single-currency businesses only, and that has to appear in the README rather than being discovered
at the first foreign invoice. A single-currency bookkeeping library is a defensible product; an
undescribed one is not.

**Option B — build it.** It decomposes correctly along the usual seam: the **substrate** carries the
pair of amounts and the rate, the **pack** says which rate applies on which date (§ 256a's
Devisenkassamittelkurs, the one-year imparity carve-out). The architecture is not wrong about it.
*Cost:* it is the only item on the entire list that starts at the bottom. It changes `Money`, the
entry line, the **data format** (a version bump), every projection, and the byte-parity contract
that SF-15 rests on — which means the cross-test has to be re-proven, not merely re-run.

**Recommendation: A for now, B when a real embedder needs it**, with the census row moved to ➖ and
the limit named in the README. The reason is not the cost: it is that B without a concrete embedder
would fix the *design* of multi-currency around a guess, and the seam it has to cut (which rate,
whose date, per line or per entry) is exactly the part that a real case decides and a hypothetical
one does not.

**This needs a human decision before either path is taken.** Until then the census row stays ⚠️ and
points here.

---

## 4. Single-circle or two-circle cost accounting — ⚠️ **awaiting a product answer**

**Not decided, and it hangs on a question no document in this repository answers: is the municipal
pack still coming?**

**The architectural finding that came out of the boundary work.** Under the **single-circle** model
the Abgrenzungsrechnung (F-KLR-002, in scope and unbuilt) fits no policy kind at all: it reads the
journal like a projection and then *adds* values that are not in the journal — imputed owner's
salary, imputed rent — which no projection may do. Under the **two-circle** model it becomes a plain
**expansion**: it produces postings, in the costing circle. The strain disappears rather than being
worked around, which is usually the sign that the second model is the right one.

**What makes two circles affordable** is the property the architecture was built on: the substrate is
closed under composition, so a second accounting circle is a second `Ledger` and a second
`JournalRepository` on `Tenant` — a *composition* change, not a second substrate. The classical
German answer says the same thing from the other side: GKR and IKR carry the Betriebsbuchhaltung in
account class 9 as a second double-entry circle linked by mirror accounts, and the shipped `de`
chart is single-circle with no class 9 at all.

**What it costs:** two period regimes, two finalization rules, two audit trails and twice the
persistence. That is not architectural elegance talking; it is real work in both languages and both
adapters.

**Why it hangs on the pack question.** A municipal pack (kameralistik-adjacent, or an IKR-shaped
chart) would make the second circle the *normal* case rather than an option, and building it
single-circle first would then be a rewrite. If no such pack is coming, the single-circle model with
a documented limitation on imputed costs is cheaper and honest.

**Until it is answered, F-KLR-002 stays in scope and unbuilt** — which is where it already is, on
record, with its reason (IMPL-041). Nothing about that is new; what is new is knowing *which
question* unblocks it.

---

## What this file is not

It is not a backlog. Three of the four are decided and two of those need no further work at all.
The remaining two are questions for a person, written down so that the next reader inherits the
argument and not just the gap.
