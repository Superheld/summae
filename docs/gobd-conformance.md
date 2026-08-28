# GoBD conformance — what summae proves, what it leaves to you

**Status: 2026-08-28.** Reference version of the GoBD: BMF letter of 28.11.2019, amended
11.03.2024 and 14.07.2025.

This document exists for one situation: an audit asks *"is this bookkeeping GoBD-compliant,
and where is the proof?"* — and the answer should be mechanical, not a promise.

Every row below carries one of three statuses, and the distinction is the point of the
document:

| Status | Meaning |
|---|---|
| ✅ **verified** | A named test or fixture fails if this stops being true. Run it. |
| ⚠️ **open** | summae should do this and does not yet. Named, scoped, not hidden. |
| ➖ **not verifiable here** | Cannot be proven by a library at all — it is an organizational or application obligation. Listed so it is not mistaken for covered. |

> **The most important sentence in this document:** a ✅ means a *machine* checks it, not
> that someone believes it. A ➖ is not a weaker ✅ — it means summae **cannot** give you
> this, and if nobody else does, the books are not compliant no matter how green the suite
> is. The ➖ rows are collected for the embedding application in
> `GOBD-APP-OBLIGATIONS.md` in the **summae-app** repository (a sibling repo, not a path in this one).

Reproduce every ✅ in this document:

```bash
make check                         # PHP: PHPStan, unit + contract tests, conformance suite
                                   # in-memory AND against the database adapter
cd implementations/node && pnpm test && pnpm fixtures --strict
make cross                         # both engines against one data set
```

---

## 1. What is mechanism, and what is German law

summae's core is a **jurisdiction-free substrate**; everything jurisdiction-specific is
data in a pack. That split cuts straight through the GoBD, and reading the table without it
is misleading.

Most of what the GoBD demands of a bookkeeping system — append-only journal, immutability
after finalization, gapless numbering, correction only by reversal, voucher reference — is
**not German law inside summae**. It is mechanism, it holds in the `us` pack exactly as in
the `de` pack, and it is proven by tests that were never written with the GoBD in mind. Those
rows are ✅ because the substrate is built that way, not because a German rule was
implemented.

The genuinely German parts are few, and they are where the ⚠️ rows cluster.

---

## 2. Nachvollziehbarkeit und Nachprüfbarkeit (Rz. 30–36)

| Obligation | Status | Proof |
|---|---|---|
| Progressive check: voucher → posting → report | ✅ | `F-CORE-003`, fixtures `core/post-and-invariants`, `core/voucher-unknown` — every posting references exactly one voucher; `voucherId` is not nullable and an unknown one is `E_VOUCHER_UNKNOWN` |
| Retrograde check: report → posting → voucher | ✅ | Every report is a projection recomputed from the journal (`F-CORE-015`, fixtures across both packs). No stored balance exists to diverge from the journal. |
| Reports are reproducible at a later date | ✅ | `F-CORE-016`, fixtures `projections/deviating-fiscal-year`, `pack/de-pack/de-jahresgang-current`. Byte-identical double run is part of every conformance run (`--strict`). |
| A knowledgeable third party can follow the system | ➖ | This is the *Verfahrensdokumentation*, and it is an organizational document about your installation, your processes, your controls. summae can supply the technical building block — see ⚠️ F-IO-007 below — but never the document. |

## 3. Vollständigkeit (Rz. 36–45)

| Obligation | Status | Proof |
|---|---|---|
| Gapless journal numbering | ✅ | `NF-6.1`; dedicated per-language tests `NfConcurrencyPerformanceTest` / `nf-concurrency-performance.test.ts` — the number is assigned atomically at `post`, which is the serialization point. |
| Every posting balances (no netting of transactions) | ✅ | `F-CORE-001`, fixtures `core/post-and-invariants`, `core/post-malformed` — Σ debit = Σ credit is enforced on write, not checked on read. |
| Individual recording, no aggregation | ✅ | Structural: the journal stores entries with lines; no aggregation path exists. Reports are folds over that. |
| **Every business transaction actually reaches the system** | ➖ | summae cannot know about a transaction nobody entered. Completeness of *input* is the operator's obligation. |

## 4. Richtigkeit (Rz. 46)

| Obligation | Status | Proof |
|---|---|---|
| Amounts are exact, rounding is specified | ✅ | `NF-2.1`; `Money` on `brick/math` / `big.js`, commercial half-up away from zero, `allocate` by largest remainder. Never a float. |
| Same input → same result, in every language | ✅ | The top quality policy: the conformance suite as a shared oracle + `make cross` with both engines on one data set, in both directions. Counts are deliberately not repeated here — they drift faster than anyone updates them, and `pnpm fixtures` / `make cross` say what they are. |
| VAT is computed per the applicable rules at the reference date | ✅ | `F-TAX-*`, tax codes versioned with `validFrom` in the pack; `NF-5.1`. |
| A tax amount that reaches the books without a tax code is not silently dropped | ✅ | `F-TAX-013`, fixture `vat-return-gap-warnings` — the return is built from tax-*coded* lines, so a hand-posted expense / input-tax / bank entry balances, satisfies every invariant, shows correct figures on the accounts and in the trial balance, and contributes **zero** to the filing. The books look right everywhere except the one place that decides what is filed. `vatReturn.gapWarnings` names every such posting, at the figures rather than in a projection whoever files may never open. Deliberately a warning and not a refusal: correction postings legitimately touch those accounts. Which accounts count is the pack's answer — `tax_in`/`tax_out` are chart subtypes, so a jurisdiction without input-tax deduction produces no such warning. |
| The shipped `de` pack covers every VAT case a German business meets | ⚠️ **three cases short, and none of them is a GoBD obligation** | **Read the status before the list.** The GoBD asks that what is booked be computed correctly at its reference date (row above, ✅) and that nothing filed be silently wrong (row above, ✅). It does *not* require a pack to carry every code in the UStG — a business with no OSS turnover is not less GoBD-compliant for a pack that cannot express one. What is missing is therefore **product scope**, named here so it is not mistaken for covered, and tracked in the project backlog (P8): **§ 15a** input-tax adjustment (a multi-year correction with its own state — an expansion policy, not a code), **§ 14c** incorrectly shown tax (a tax amount with no base, which the current code shape cannot express: every code computes tax *from* a base), and **OSS** (destination-country rates and a separate return). Each is a feature, not a gap in a data file. **What this row said until 2026-08-28 was stale, and how it got that way is the part worth keeping:** it named intra-community *acquisition* and exempt *export* as missing, and both were built the very same day, hours after the row was written (`IGE19`/`IGE7`/`AUSFUHR`, fixtures `de-ig-erwerb` and `de-ausfuhr`). The census claim was prose and nothing held it against the pack, so it survived five days of green builds describing a product that had moved. Prose in a census is a claim like any other — see §15, whose table the document's own gate now holds against the shipped manifests. |
| **The postings represent what actually happened** | ➖ | Correct *accounts* for a real transaction is a bookkeeping judgement. summae enforces form, not truth. |
| **A settlement that reduces the consideration corrects the VAT** | ✅ **since 2026-08-28** | `F-CORE-042`, fixture `pack/de-pack/de-entgeltminderung-erzwungen` — the shipped `de` pack declares `accountCombinationRules`: an entry touching `4020` (granted discount, taxable) must also touch an output-VAT account, and one touching `5010` (received discount, taxable) must also touch an input-VAT account, else `E_COMBINATION_REQUIRED`. § 17 Abs. 1 UStG and its sentence 2, both halves, because the input side means deducting VAT that was never owed. **What made this dangerous is that the incomplete entry is otherwise flawless:** it balances, it has a voucher, it sits in the right period, and every account and the trial balance read correctly — the only wrong figure is the one that gets filed. **`settle` itself is still not constrained and will not be:** it records an allocation and posts nothing, so there is no entry there for a predicate to see. A-13 is reached through the *posting* the application makes for the discount, which is where the books would actually go wrong; that is the whole of what a library can do here, and the app-obligation list says so. The predicate sees one entry, so it can never ask whether the *original* sale carried tax — which is why the obligation lives in the account name and a tax-free reduction is booked against the revenue account it reduces. The `de` chart gained `5010` for this (the input side had no reduction account at all) and `4020` was renamed to say what it now enforces; the pack moved to `de@2026.8`, and a reduction of a tax-free supply is proven to pass untouched in the same fixture. |

## 5. Zeitgerechte Erfassung (Rz. 47–56)

This is the section where summae deliberately reports instead of enforcing, and the
distinction matters for an audit.

| Obligation | Status | Proof |
|---|---|---|
| Three dates kept apart (voucher date, posting date, recording time) | ✅ | `F-CORE-011`, fixture `core/post-and-invariants`; `entryDate` / `voucherDate` / `recordedAt` on every entry. |
| The finalization deadline is **monitorable** | ✅ | `F-CORE-027`, fixture `core/unfinalized-entries` — projection `unfinalizedEntries` reports every posting still in status `entered`, its age in days measured from `entryDate`, filterable by `olderThanDays` and fiscal year. |
| Cash transactions daily, non-cash within 10 days, posting by the end of the following month | ➖ | **Deadlines are not enforced and will not be.** They are German rules with exceptions, and the substrate is jurisdiction-free; a hard block would also make late but honest bookkeeping impossible, which the law does not ask for. summae supplies the number (`unfinalizedEntries`); acting on it is the application's workflow. |
| Separation of cash and non-cash transactions (Rz. 57 ff.) | ✅ | `cashJournal`, fixture `projections/cash-journal`. The separation needs no marker and no format field: in double-entry a transaction *is* a cash transaction exactly when it touches an account of subtype `cash`. What was missing was the view — the cash account's sheet *is* the Kassenbuch. Since 2026-08-23 each movement also names its reversal counterpart (see §7). |
| The cash balance is never negative (Kassensturzfähigkeit) | ✅ | Same projection: the running balance is checked at **every** movement, not only at the close, so a day that dips below zero and recovers is reported rather than hidden. `negativeBalances[]` names each point; `cashCountable` is false whenever the list is non-empty. Physics, not jurisdiction — hence substrate, not pack. |

## 6. Ordnung (Rz. 57–60)

| Obligation | Status | Proof |
|---|---|---|
| Systematic recording (account assignment) | ✅ | `F-CORE-005`/`F-CORE-007`, chart of accounts as versioned pack data, own accounts creatable within the systematics. |
| Periods close in order; postings into closed periods are refused | ✅ | `F-CORE-004`, fixtures `core/period-ordering`, `core/finalize-reverse-period` — `E_PERIOD_CLOSED`. |
| Separation of cash / non-cash | ✅ | Same as section 5: `cashJournal` presents it, and flags any negative cash balance. |

## 7. Unveränderbarkeit (Rz. 58–60, 107–112) — the centre of the GoBD

| Obligation | Status | Proof |
|---|---|---|
| Journal is append-only | ✅ | Iron invariant. `F-CORE-002`, fixtures `core/finalize-reverse-period`, `core/correct-open-items`. |
| Finalization is a state per posting | ✅ | `F-CORE-013`, fixtures `core/finalize-reverse-period`, `core/fiscalyear-close-guard` — lifecycle `entered → finalized`. |
| Mass finalization "up to date X" | ✅ | Same requirement; `finalize` accepts `finalizeUntil`. |
| A finalized posting cannot be changed | ✅ | The entity refuses it itself (`JournalEntry::assertCorrectable` → `E_ENTRY_FINALIZED`), so no service can bypass it; fixture `core/finalize-reverse-period` asserts the refusal. |
| Correction only by reversal, with a back-reference | ✅ | `F-CORE-002`; `reverses` / `reversedBy` on the entry, fixtures `core/reverse-clears-open-items`, `core/reverse-settled-item`. |
| **A reversal is visible AS a reversal in the sheets a person reads** | ✅ | `F-CORE-002`, fixture `projections/reversal-visible-in-sheets` — `cashJournal` and `accountSheet` carry `reversesEntry` / `reversedByEntry` (journal numbers, not ids). Until 2026-08-23 both showed a reversal as an ordinary opposite movement, and this core reverses by *general reversal* (same side, negated amount), so the cash book showed a negative amount on the debit side with nothing explaining it. A system that permits cancellations without showing them in its output is formally defective on that ground alone — no proof of manipulation required (BFH 29.07.2025, X R 23/21 and X R 24/21). |
| **The trail's own integrity is checkable, not merely asserted** | ✅ **since 2026-08-28** | `F-CORE-043`, fixture `core/audit-hash-chain` (in-memory **and** persisted). Each record carries the hash of its predecessor over canonical JSON; changing, removing or inserting one breaks the link at its successor, and `auditTrailIntegrity` reports where. This is the row §13's second warning used to have no answer for: a direct `UPDATE` against a `summae_*` table still bypasses the library, but it no longer does so **without a trace**. Read the four counts apart: `chained` is verified, `unchained` is pre-0.8 and deliberately not a break, `redacted` is an erasure's shell whose linkage holds while its content is gone by design, and `breaks[]` names the rest with a reason. The head is published because no chain notices records dropped from its end. |
| Corrections before finalization are logged | ✅ | `F-CORE-012`, fixture `core/audit-trail`. |
| **Every state-changing operation leaves a trace** | ✅ | `F-CORE-014`/`F-CORE-020`, fixture `core/audit-trail-period-and-config` **plus** the enumerating guard `AuditTrailContractTest` / `audit-cases.ts`: all 32 state-changing dispatcher operations are run for real and must produce an audit record **of their own kind** — not merely the `journalEntry/created` their postings leave behind. The list of operations is taken from the *published* API surface, so a capability cannot be exempt by being forgotten; the guard's exception list is empty. **Since 2026-08-25 the same enumeration runs a second time against real persistence** (`AuditTrailPersistedTest`, `packages/knex/test/audit-trail-contract.test.ts`). That binding is the one that matters for an audit: the check used to exist only for the in-memory tenant, which is the construction summae does not ship, and it therefore could not see the defect that actually occurred — `DatabaseTenantFactory` takes the audit writer as an *optional* argument and once left it off for three services, so the tax profile, the asset events and the costing runs wrote nothing behind a database while every test stayed green. |
| Every record carries before/after values | ✅ **since 2026-08-25** | `F-CORE-014`, fixture `core/audit-trail-creations` plus the guard `testEveryRecordCarriesABeforeAfterDiff` (both bindings, both languages). The trail was inconsistent and nothing noticed: vouchers, fiscal years, dimensions and costing runs recorded `{from: null, to: …}` on creation while accounts, postings and partners recorded an empty diff — so the invariant `systemDescription` publishes was true of most records and not of all. The diff holds the identifying fields, never a copy of the object: an entry's lines stay in the append-only journal, and a trail that duplicated them would be a second source of truth rather than a history. |
| The published list of audited events is what actually happens | ✅ **since 2026-08-25** | `systemDescription.auditTrail.events` is what an auditor reads as "this is what the trail records". It was a hand-kept literal that nothing compared to reality, and it had already fallen behind once (0.11.0). `testPublishedEventListMatchesWhatIsActuallyRecorded` now holds it against the events the operations *actually* write, in **both** directions and in both bindings — under-reporting hides a recorded event, over-reporting promises one that never appears. |
| **The change history is retrievable per object, not only as a whole** | ✅ **since 2026-08-25** | `F-CORE-036`, fixture `core/audit-log-filtered`. This is progressive and retrograde traceability at the level of the API: `auditLog` filters by `objectType`, `objectId`, `actor` and `action` and pages with `offset`/`limit`, `count` being the matches before paging. Before that only a date range existed, so "what happened to *this* posting" could be answered only by transporting the entire trail and filtering outside the library — which made the traceability the GoBD asks for a property of the embedding application rather than of the books. |
| A voucher's creation is traceable even when no posting follows | ✅ | Same guard; `createVoucher`/`postVoucher` write `voucher/created`. |
| Asset events are traceable as asset events | ✅ | Same guard; `asset/acquired`, `asset/disposed`, `depreciationRun/completed` — a repeated no-op run is recorded too, so re-runs do not vanish. |
| Costing runs are traceable | ✅ **since 2026-08-24** | `F-KLR-001`, fixture `costing/costing-runs-list` plus the adapter suites (`CostingRunPersistenceTest`, `packages/knex/test/adapter.test.ts`) — a fixture builds one tenant in one process and therefore cannot see whether a run outlives it, which is exactly the ground the adapter tests cover. The audit records (`costingRun/created`, `costingRun/released`) always existed and were persisted; what was missing was the runs themselves, so a durable record pointed at an object that no longer existed after a restart. They now live behind `CostingRunRepository` in a `summae_costing_runs` table, the next version of a period comes from the store rather than a counter in the process, and the `costingRuns` projection answers which runs exist — so the trail can be followed to the thing it names. The blocker this row used to describe (summae could not evolve a shipped schema at all) was decided and built: SPEC-014, the idempotent install. |
| Reopening a closed period is traceable | ✅ | Same guard; `reopenPeriod` writes `period/reopened` with actor and timestamp. It wrote nothing at all until 2026-08-23. |
| The audit trail survives migrations and is part of the data format | ✅ | `F-CORE-020`; `auditLog` is a stream of `journalExport` and is carried by both persistence adapters (`make cross`). The adapter round trip is asserted rather than assumed: every record the persisted audit contract checks has been through a JSON column and come back, `changes`, `actor` and the canonical timestamp included. |
| **Nobody edits the database behind summae's back** | ➖ | A library cannot defend its own storage. Access control, DB permissions and separation of duties are yours. |

## 8. Belegwesen (Rz. 61–81)

| Obligation | Status | Proof |
|---|---|---|
| No posting without a voucher | ✅ | `F-CORE-003`; `voucherId` is mandatory, `E_ENTRY_NO_VOUCHER` / `E_VOUCHER_UNKNOWN`. |
| Voucher carries number and date | ✅ | Fixture `core/post-and-invariants`; `createVoucher` refuses a voucher without `voucherDate`. |
| **The voucher file itself is stored and retrievable** | ➖ | summae manages the *reference*, never the file. Document storage is the application's (design decision, `lieferumfang.md`). |

## 9. Journal- und Kontenfunktion

| Obligation | Status | Proof |
|---|---|---|
| Journal function: all postings in posting order | ✅ | `journalExport` with `ordering: sequenceNumber`, fixture `io/journal-export-z3-current`. |
| Account function: general and subsidiary ledgers | ✅ | `accountSheet`, `openItems` (filterable by partner, `F-CORE-022`), `trialBalance` with opening balance / debit / credit / balance (`F-CORE-026`). |

## 10. Datenzugriff Z1 / Z2 / Z3 (Rz. 158–183)

| Obligation | Status | Proof |
|---|---|---|
| Machine-evaluable data set | ✅ | `F-IO-001`, fixture `io/journal-export-z3-current` — `journalExport` produces journal, accounts, vouchers, partners and auditLog with SHA-256 content hashes per stream and a field catalogue (name, type, meaning). |
| Z1 / Z2 (direct and indirect access) | ➖ | Access modes are properties of *your installation*, not of the library. |
| **Export in the Beschreibungsstandard (index.xml / IDEA)** | ✅ **since 2026-08-28** | `F-IO-012`, fixture `io/gdpdu-data-carrier` — `gdpduExport` produces the flat files **and** the `index.xml` that describes them, written to the *Beschreibungsstandard für die Datenträgerüberlassung* **1.6 of 1 March 2019**, DTD `gdpdu-01-03-2019.dtd`. The journal is flattened to one row per posting line (a CSV cannot nest, and an auditor sums debit and credit per account), primary keys and foreign keys are declared so IDEA can join the five files rather than receive them unrelated, and the fixture pins the **exact bytes** in both languages — because the DTD fixes element order and a subset expectation would let a reordering pass. Initial conformance was established by validating the output against the published DTD with a negative control (a `Table` whose `Name` and `Description` are swapped is rejected); `GdpduIndexStructureTest` / `gdpdu-index-structure.test.ts` then state the DTD's content models as assertions, so the *reason* lives in the repository. **This row was ⚠️ "open, by design" until 2026-08-28**, and the design said the mapping was the app's. What changed is not the reasoning but the conclusion: "we ship the mapping's input" and "the books are auditable" are different claims, and an audit asking for a data carrier does not care which one was meant. **Three things are still yours** and the response says so in `notProvided`: writing the files (summae owns no file system), placing `gdpdu-01-03-2019.dtd` on the medium (a normative document we name rather than redistribute), and supplying the voucher images. |

## 11. Verfahrensdokumentation (Rz. 151–157)

| Obligation | Status | Proof |
|---|---|---|
| Technical system description generated by the package | ✅ | `F-IO-007`, fixture `io/system-description-claims` — the `systemDescription` projection reports format version, the tenant's tax profile, the eleven enforced invariants *with the mechanism that enforces each*, that the trail is reported at all, and an explicit `notProvided` list. Byte-identical in both languages. That it also reports the **full** API surface is proven where it can be proven: `tenant-operations-contract.test.ts` / `TenantOperationsContractTest` hold the published list against the dispatcher's own routing table in both directions, so a capability that exists and is not described fails the build. A fixture could only hold a hand-copied list, and did until 2026-08-24 — at the price of going red whenever summae gained a capability. The **audited-event** list went the same way on 2026-08-27, for the same reason and with a better guard behind it: `audit-trail-contract.test.ts` / `AuditTrailContractTest` run every state-changing operation for real and compare what it writes against what the description claims, in both directions. |
| The description states its own limits | ✅ | Same fixture: `auditTrail.actorIsAuthenticated` is `false` and `notProvided` says the actor is never verified. A system description that omitted that would be the most dangerous kind of true document. |
| The description names the pack the tenant runs on | ✅ | Fixture `pack/de-pack/de-system-description` — a tenant composed from a manifest reports `pack: {id, version}`; one built from an inline bundle reports `pack: null`, because there is no manifest to name. Both cases are pinned, so the wiring is not only tested against null. |
| General description, user documentation, operating documentation | ➖ | Yours. summae's [handbook](handbuch/README.md) and [CLI walkthrough](handbuch/cli-walkthrough.md) can be cited as the user documentation for the bookkeeping component, but the process description around it is written by you. |

## 12. Aufbewahrung (10 / 8 years) and internal controls

| Obligation | Status | Proof |
|---|---|---|
| Retention periods, deletion concept, retention despite open assessment periods | ➖ | **Explicitly out of scope, decided 2026-06-07.** summae supplies voucher type and every date field and contains no deletion logic at all. Whoever deletes, decides. |
| Machine evaluability for the whole retention period; system change | ✅ *partly* | The versioned, language-neutral data format is the mechanism (`NF-1.2`, `F-IO-004`, proven by `make cross`, not by a fixture). Keeping the archive readable for ten years is still an operational task. |
| Internal control system, access control, separation of duties | ➖ | summae has no user model. `actor` is recorded, never authenticated — see the warning in §13. |
| Data security, backup | ➖ | Yours. |

## 13. Two limits you must know

**`actor` is recorded, not verified.** Every audit record carries the `actor` from the
operation's input, and if none is supplied it reads `system`. summae has no user model and
no authentication: it writes down what the caller claims. The audit trail is therefore
tamper-evident against *summae's own operations*, and says nothing about who really sat at
the keyboard. Binding `actor` to an authenticated identity is the application's job, and it
is the single most important thing the application must get right for the audit trail to
mean anything.

**Everything below the API is outside summae's reach — but since 2026-08-28 not without a
trace.** Append-only, finalization and the audit trail hold for operations that go *through* the
library, and a direct `UPDATE` against a `summae_*` table still breaks all three. What changed is
that the **audit trail** now notices: its records are hash-chained, so an altered, removed or
inserted record shows up in `auditTrailIntegrity` as a named break. The journal is **not** chained —
that would need the reserved field `previousEntryHash`, which writers must not populate in v0.x
(SPEC-022) — so a direct write to `summae_journal_entries` still leaves nothing behind. The app
design rule "only through the documented API, never a direct query" therefore remains a compliance
rule and not a style preference; what the chain buys is that one of the two places you would have
to look now answers by itself.

---

## 14. The open list, in one place

| # | Item | Kind | Why it is not done |
|---|---|---|---|
| 1 | ~~`F-IO-007` technical system description~~ | ✅ **closed 2026-08-23** | Built as the `systemDescription` projection, pinned by `io/system-description-claims` in both languages (three earlier fixtures covered this ground and are retired; `testing/testsuite/superseded.json` names them and says why). One sub-item remains: it does not name the pack (next row). |
| 1b | ~~Pack identity in the system description~~ | ✅ **closed 2026-08-23** | The identity now travels with the resolved bundle and is pinned on the tenant at composition. |
| 2 | ~~Cash / non-cash separation~~ | ✅ **closed 2026-08-23** | My earlier scoping was wrong and is worth recording: I judged it to need a journal field and a format bump. It needed neither — the separation was already structural through the account subtype, and only the projection was missing. The cash-count check came along for free and is the part that finds real defects. |
| 3 | ~~Z3 Beschreibungsstandard mapping~~ | ✅ **closed 2026-08-28** | **Was:** ➖ by design — summae supplies the self-describing data set, the `index.xml` is the app's, and the decision was written into the root `CLAUDE.md` and `out-of-scope.md` so nobody would start it by accident. **Now:** built as `gdpduExport` (F-IO-012), and the out-of-scope entries are gone with the reasoning kept where they stood. The part of the old entry that aged worst is the justification "nothing in the test suite fails because of this, which is exactly why it is written down here" — true, and in hindsight the warning rather than the defence. A scope decision that survives only because nothing tests it is one to re-read periodically; this one did not survive the re-read. |
| 4 | ~~Ten operations write no audit record of their own~~ | ✅ **closed 2026-08-23** | Every state-changing operation writes a record of its own kind — 32 of them today, taken from the *published* surface so a new one cannot be exempt by being forgotten; `UNCOVERED_KNOWN` is empty in both contract tests, and since 2026-08-25 the same enumeration also runs against real persistence. `allocate` moved to the read-only list instead — it distributes an amount and returns the parts, with no journal effect, so there is nothing to log. |
| 5 | Finalization deadline as a *constraint* | ➖ deliberately not | See §5. Reported, never enforced. |
| 5b | ~~VAT correction on a consideration-reducing settlement~~ | ✅ **closed 2026-08-28**, with the half that stays the app's named | **Was:** neither built nor documented, and the gap between "summae records the allocation" and "the books are right" was invisible. **Now:** the shipped `de` pack declares `accountCombinationRules` (§4, `de-entgeltminderung-erzwungen`), so the *posting* that reduces the consideration is refused unless it carries its VAT correction — both directions, output and input. `settle` itself remains unconstrained and will: it records an allocation and posts nothing, so there is no entry for a predicate to see. That residue stays **A-13** on the app's list, and this row is ✅ for the half a library can hold. |
| 5c | ~~Tamper evidence on the audit trail itself~~ | ✅ **closed 2026-08-28** | **Was:** the trail was append-only *because no code path updates or deletes it* — a property of the procedure, not of the data, so an auditor had to read the source or trust the deployment. **Now:** every record carries `previousRecordHash` and `recordHash` (SHA-256 over canonical JSON, RFC 8785, format 0.8) and `auditTrailIntegrity` walks the chain — `F-CORE-043`, fixture `core/audit-hash-chain`, run against **both** the in-memory core and real persistence. **Two things the deferral had not seen.** It assumed a normative rule blocked this (SPEC-022); the rule reserves `previousEntryHash` on the **posting**, and this row asked about the **trail** — two chains under one word, and the trail's records carry no reservation. And the trail has an erasure hole (`eraseFor`, the right to be forgotten), so a naive chain would make a **lawful erasure indistinguishable from a manipulation** and break the chain there permanently; an erased record leaves a shell keeping both hashes instead. What stays out of reach is stated in the projection itself: records dropped from the **end** (compare the published `head`), and a fork from two concurrent appends, reported as a break because from the data alone it is one. |
| 6 | ~~The constraint policy kind has a socket, with one predicate~~ | ✅ **closed 2026-08-28** | **Was:** the kind that exists to let a jurisdiction say *no* could express one thought — this account may not be posted without that dimension — so the row asked for a constraint about something other than dimensions. **Now:** `accountCombinationRules` (F-CORE-042) says which accounts must, or must not, appear in one entry together, proven by `xx-8-constraint-account-combination` in both languages. The vocabulary has two words, which is what makes it a vocabulary rather than a feature with a data file. The predicate sees **one entry** and says so: no deadlines (§5 keeps that ➖ deliberately), no reach across entries, and no rule about a settlement — `settle` records an allocation and posts nothing, so there is nothing there to constrain. A-13 is reached instead through the *posting* an application makes for the discount, which is where the books change. |

## 15. The facts this document asserts, held against the product

Everything above is prose, and prose is where a census rots. It happened here: §4's VAT row named
two tax codes as missing on 2026-08-23 and both were built the same day, hours later — the row went
on describing a product that had moved for five days of green builds, because nothing compared it to
the pack. The rows are argued in prose deliberately; the **facts inside them** do not have to be.

`GobdConformanceDocTest` / `gobd-conformance-doc.test.ts` parses this table and compares every row against its real
source. A pack that gains a tax code, an engine that gains a mechanism, a rule that changes its
accounts — each turns the build red here until this document is opened. Values are separated by
spaces; the order must match the source.

| Claim | Source | Value |
|---|---|---|
| `de` pack tax codes | `pack-library/de-pack/de.json` → `taxCodes` | `USt19` `USt7` `VSt19` `VSt7` `RC13b` `igL` `USt19WA` `IGE19` `IGE7` `AUSFUHR` |
| `us` pack tax codes | `pack-library/us-pack/us.json` → `taxCodes` | `SALETAX` `USETAX` `EXEMPT` |
| `default` pack tax codes | `pack-library/default-pack/default.json` → `taxCodes` | — |
| engine tax mechanisms | `TaxMechanisms::all()` / `allTaxMechanisms()` | `standard` `reverse_charge` `intra_community_supply` `exempt` |
| engine tax base kinds | `TaxBases::all()` / `allTaxBaseKinds()` | `net` `inclusive` |
| `de` pack account-combination rules | `pack-library/de-pack/constraint/de-entgeltminderung.json` | `4020`-`4020` → `3100`-`3110` · `5010`-`5010` → `1500`-`1510` |

A `—` means the source is empty, which is itself a claim: the `default` pack ships no tax codes, and
that is the reason it is the pack to start from when a jurisdiction has none of ours.

---

## 16. What this document is not

It is not a legal opinion, and it is not a certification. It states which obligations are
mechanically checked in this repository and which are not — nothing more. Whether *your*
bookkeeping is GoBD-compliant depends on the ➖ rows at least as much as on the ✅ rows, and
those rows are not summae's to fill.
