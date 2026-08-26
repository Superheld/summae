# SPEC-FINDINGS — resolved

Every finding that is decided, in full. Open ones live in
[`SPEC-FINDINGS.md`](SPEC-FINDINGS.md), which is deliberately short enough to read whole.

> **Finding IDs were re-prefixed on 2026-08-23 — the numbers did not change.**
> The old prefixes sat inside the requirement namespaces: `F-0xx` was one category
> word away from the functional requirements (`F-CORE-…`, `F-IO-…`), and `NF-0xx` was
> separated from the non-functional requirements (`NF-1` … `NF-7`) by nothing but its
> leading zeros.
>
> | old | new | series |
> |---|---|---|
> | `F-001` … `F-011` | `SPEC-001` … `SPEC-011` | spec/fixture/model contradictions |
> | `F-CROSS-001` | `SPEC-C01` | cross-implementation |
> | `NF-001` … `NF-025` | `IMPL-001` … `IMPL-025` | implementation & cross-language |
>
> `CHANGELOG.md` deliberately keeps the old IDs: released notes describe what was
> published, so rewriting them would falsify the record.

> **✅ All findings SPEC-001 to SPEC-007 resolved in spec v0.5** (2026-06-08,
> decision log + `SPEC-UPDATE-v0.5.md`) and implemented in JOB-V05:
> - SPEC-001 → dedicated code `E_VOUCHER_UNKNOWN`
> - SPEC-002 → `E_ENTRY_NOT_FINALIZED` removed, `reverse` status-independent (my workaround was correct)
> - SPEC-003 → dedicated code `E_FISCALYEAR_UNFINALIZED_ENTRIES`
> - SPEC-004 → rule-module block `assetAccounts` (name heuristic removed)
> - SPEC-005 → manifest required fields `streams`/`hashAlgorithm`, `auditLog` always, `formatVersion` current
> - SPEC-006 → dedicated code `E_COSTING_RUN_UNKNOWN` (already matched my choice)
> - SPEC-007 → `side: assets|liabilitiesAndEquity` on the balance-sheet root node
>
> The detail entries below remain as history.

**Why these are kept rather than pruned.** 112 comments in the source cite these IDs (`SPEC-015`
37×, `SPEC-004` 11×, `IMPL-008` 11×, `IMPL-018` 8×, `IMPL-023` 7×), the root `CLAUDE.md` uses
`IMPL-025` as *the* worked example of the jurisdiction litmus test, and `NoJurisdictionTextTest`
explains itself with it. A resolved entry is what "see IMPL-025" resolves to; delete it and the
comment points at nothing. The rule was written down on 2026-08-15 and still holds: *why* a decision
was made is worth more than a short file — which is an argument for keeping the text, not for
keeping it in the reader's way.

Kept in full, deliberately, and not because deleting felt wrong: **112 comments in the source cite
these IDs** (`SPEC-015` 37×, `SPEC-004` 11×, `IMPL-008` 11×, `IMPL-018` 8×, `IMPL-023` 7×), plus the
root `CLAUDE.md`, which uses `IMPL-025` as *the* worked example of the jurisdiction litmus test, and
`NoJurisdictionTextTest`, which explains itself with it. A resolved entry is what a comment saying
"see IMPL-025" resolves to; delete the entry and the comment points at nothing, which is worse than
a long file. The rule was written down on 2026-08-15 and still holds: *why* a decision was made is
worth more than a short file.

What changed on 2026-08-25 is only the **order** — open findings first, the index below them, the
reasoning after that. Nothing was removed.

## Status at a glance

Re-verified against the code on 2026-08-15 — the per-finding headings below now carry their
status, so scanning the list no longer suggests open work that is long done. Resolved entries
keep their original text under the resolution note: why a decision was made is worth more than
a short file.

| Finding | Status |
|---|---|
| SPEC-001 unknown `voucherId` | ✅ `E_VOUCHER_UNKNOWN` + `core/voucher-unknown.json` |
| SPEC-002 `E_ENTRY_NOT_FINALIZED` in api.md, not in the catalogue | ✅ code dropped from the spec, `reverse` is status-independent |
| SPEC-003 fiscal-year close with unfinalized postings | ✅ `E_FISCALYEAR_UNFINALIZED_ENTRIES` + `core/fiscalyear-close-guard.json` |
| SPEC-004 asset posting accounts | ✅ accounts via the `assetAccounts` pack module; **pool period** `poolYears` in the depreciation module since 2026-08-16 (fixture `gwg-pool-period`) |
| SPEC-005 journalExport manifest streams | ✅ `auditLog` always included; `formatVersion` follows the spec version (0.6 since 2026-08-16, guarded against drift) |
| SPEC-006 `E_COSTING_RUN_UNKNOWN` | ✅ code + `costing/costing-run-unknown.json` |
| SPEC-007 balanceSheet side assignment | ✅ explicit `side` in the mapping schema and in both projections |
| SPEC-008 `includeNonCash` missing from the schema | ✅ schema extended |
| SPEC-009 `cashBasisReport` German VAT passthrough | ✅ resolved |
| SPEC-010 `EXEMPT` cannot be posted | ✅ `exempt` mechanism (0.5.0) |
| SPEC-C01 timestamp serialization | ✅ resolved |
| IMPL-001 pack draft fixture `defaults` | ✅ draft completed at the source |
| IMPL-002 `includeNonCash` missing from the schema | ✅ schema extended |
| IMPL-003 `cashBasisReport` German VAT passthrough | ✅ resolved |
| IMPL-004 `EXEMPT` cannot be posted | ✅ `exempt` mechanism (0.5.0) |
| IMPL-005 cash-basis reversal | ✅ fixed 2026-08-15; **remainder closed 2026-08-16** by IMPL-008 — settled-then-reversed cannot occur any more |
| IMPL-006 `cashBasisReport` without `year` crashes | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| IMPL-007 missing mapping reports `E_MAPPING_OVERLAP` | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| IMPL-008 reversal leaves open items standing | **RESOLVED 2026-08-16** — the reversal clears them (`cause: cancellation` → status `cancelled`), a touched item refuses the reversal (`E_ENTRY_HAS_SETTLED_ITEMS`); fixtures `reverse-clears-open-items`, `reverse-settled-item`, `vat-cash-basis-reversal-unpaid` |
| IMPL-009 `CalendarDate` years 0000–0099 diverged PHP vs. Node | ✅ fixed 2026-08-15 — Node no longer uses the host `Date` |
| IMPL-010 `Money.of` accepted amounts the data format forbids | ✅ fixed 2026-08-15 — `1.5e+21` was bookable; `+10.00` also diverged |
| IMPL-011 `post` accepted a fabricated `taxTag` into the VAT return | ✅ fixed 2026-08-15 |
| IMPL-012 `balanceSheet` silently ignored `fiscalYear` | ✅ fixed 2026-08-15 |
| IMPL-013 a wrong `direction` booked an incoming invoice inverted | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| IMPL-017 an unmapped balance account made the balance sheet stop balancing | **RESOLVED 2026-08-15** — `_unassigned` per section + `gapWarnings[]` (fixture `balance-sheet-gap`) |
| IMPL-014 accounts outside a mapping vanish from the income statement | **RESOLVED 2026-08-15** — `_unassigned` + `gapWarnings[]` (fixture `income-statement-gap`) |
| IMPL-015 `packages/laravel` has no tests of its own | **RESOLVED 2026-08-16** — own suite (19 tests), coverage floor 95%; found and fixed a tenant-scoping defect in **both** adapters |
| IMPL-016 four declared parameters that no implementation reads | ✅ three fixed 2026-08-16 (`journalExport.format`, `costAllocationSheet.fiscalYear`/`period`); `balanceSheet.incomeMapping` stays without effect **by decision** (IMPL-014) |
| **`E_INPUT_INVALID` added** | exit code 45 — ✅ catalogue entry written in the knowledge base |
| IMPL-018 four error codes have no exit code | **RESOLVED 2026-08-16** — appended at 49–53 in both languages (`E_AMOUNT_SCALE_MISMATCH` with them, so the guard needs no exception list); `ExitCodesTest`/`exit-codes.test.ts` read the catalogue and fail when a code in it has no exit code of its own |
| IMPL-019 pooled assets stopped depreciating on disposal | **RESOLVED 2026-08-16** — `runDepreciation` skipped every disposed asset, pooled ones included; F-AST-006 requires the pool to run its full term regardless. Both languages fixed, fixture `pool-unaffected-by-disposal` |
| IMPL-020 `supplierTaxationMethod` could never be set | **RESOLVED 2026-08-16** — declared in the data format (`enum accrual\|cash`, F-TAX-007) and carried by both record classes, but no code ever read it from the input. Now accepted and validated (`E_INPUT_INVALID` on an unknown value); fixture `supplier-taxation-method` |
| IMPL-021 asset disposal never writes off the carrying amount | **RESOLVED 2026-08-16** — `dispose` now credits the carrying amount off the asset account and books the difference to the pack's `disposalProceedsAccount`/`disposalLossAccount`; pooled assets stay exempt (IMPL-019). Fixture `pool-unaffected-by-disposal` |
| IMPL-022 disposal does not catch up depreciation to the disposal date | **RESOLVED 2026-08-16** — `dispose` books the depreciation that is due before writing off; due follows the schedule's own convention (a plan month falls due on its last day). Fixture `disposal-catches-up-depreciation` |
| IMPL-023 machine entries cannot carry a required dimension | **RESOLVED 2026-08-16** — the asset carries its dimensions (`acquireAsset(dimensions)`), and acquisition, depreciation, catch-up and disposal all book with them; both persistence adapters carry them through the round trip. Fixture `asset-dimensions` |
| IMPL-024 pooled assets reported a carrying amount of zero | **RESOLVED 2026-08-16** — `bookValueAt` returned zero for everything except `capitalize`, so the fixed-asset schedule (F-AST-005) understated the balance sheet it explains. Only `immediate_expense` has no carrying amount |
| IMPL-025 the pool-disposal rule was German law inside the core | **RESOLVED 2026-08-16** — the IMPL-019 fix hard-coded § 6 Abs. 2a EStG (`route !== 'pool'`). It is now the pack's answer (`poolReducedOnDisposal`, conditionally required next to `poolMax`), refused rather than defaulted, with fixtures for both answers |
| IMPL-026 a yearly depreciation run before a mid-year disposal left the asset account below zero | **RESOLVED 2026-08-24** — the disposal read the carrying amount *as of the disposal date* and so ignored a run booked on 31 December; it wrote off the full cost on top of what the run had already written off. Now read from the whole ledger, like every other caller. Found by the embedding app (its F-15), fixture `disposal-after-yearly-depreciation` |
| IMPL-027 the partner stream could not validate against its own schema | **RESOLVED 2026-08-24** — two halves of one gap: the format declared `accountIds` (uuids) while the engine writes `accountNumbers` (strings), and `journalExport` exported partners without stripping nulls, unlike accounts and vouchers, so a partner with no `vatId` wrote a `null` where the schema demands a string. Latent because no schema test had ever exported a partner; one does now in both languages, with the leanest partner there is. Found while adding `status` for the format 0.7 bump |
| IMPL-028 the cross-test compared against stale artifacts | **RESOLVED 2026-08-24** — `cross-export` wrote into `.cross-dbs/` without clearing it, so a fixture that stopped being exported left its database and its oracle behind and the read side kept comparing against them. Surfaced when the format moved to 0.7: three retired fixtures' old oracles said 0.6 and failed the cross test on a run that no longer happens. The export starts from an empty directory now |
| SPEC-011 evaluations read released runs only, a fixture reads a draft | ✅ resolved 2026-08-23 — the second reading was right: a draft is readable, only *evaluations across runs* are restricted to released ones |
| SPEC-012 a shipped pack's manifest version cannot change | ✅ resolved 2026-08-23 — three `*-pack-resolves` fixtures welded the product to the contract; retired into `superseded.json` with successors that pin the mechanism instead of the product data |
| SPEC-013 a shipped pack's chart of accounts cannot grow | ✅ resolved 2026-08-23 together with SPEC-012 — same weld, same retirement |
| SPEC-014 no way to evolve a shipped database schema | ✅ **decided and built 2026-08-24** — option 2, the idempotent install: `installSchema` creates only what is absent, guarded per table. Covers additive changes (a new table, by hand a nullable column) and deliberately nothing else; a column that changes type still means recreating the workspace |
| SPEC-015 tenant configuration has no owner | **RESOLVED 2026-08-24** — profile, dimension registry, allocation scheme and imported mappings were constructor arguments rebuilt on every open, so the five operations that change them audited durably and persisted nothing; our own CLI shipped with four of the five silently ineffective. Now a `summae_tenants` table, the stored record winning over the passed seed, and the CLI's hand-rolled `importMapping` write-back deleted. No fixture could ever have seen it (single-process); guarded now by `regression/tenant-configuration.json` — one CLI invocation per step — plus both adapter suites: seven tests go red without the fix |
| SPEC-016 the set of VAT filing periods is a jurisdictional claim in the substrate | ✅ **RESOLVED 2026-08-25** — `packPolicy.vatPeriods` declares them and replaces the substrate's list; the three constants stay as a *default*, which is what makes the change additive for every shipped pack. `TaxProfile::restore` stops re-judging stored profiles, a hazard that only appeared once the list became changeable |
| SPEC-017 the parameter contract reaches keys, not element structures | ✅ **RESOLVED 2026-08-25** — `element`/`fields` on any declaration, recursive, with a guard that refuses a structural input declaring neither an inner shape nor an `opaque` reason. Found three silent inputs on its first run (`lines[].openItem`, a scenario's misplaced rate base, a receiver key in both adapter suites) |
| IMPL-029 `lines[].openItem` is read by nobody | ✅ **RESOLVED 2026-08-25 by declaration** — fixtures have passed it since v0.2; an open item is derived from the account subtype and the line side, which cannot disagree with the account. Declared `acceptedWithoutEffect` rather than accepted silently, per the rule that each one is recorded |
| SPEC-018 the audit trail can only be read whole | ✅ **RESOLVED 2026-08-25** — `AuditTrail::find(criteria)` pushes the filters into SQL by reading the JSON payload (`json_extract` / `->>`), so no column and no migration; `EntryAuthors` asks for the ids on the page. The entry's own proposal was wrong and says so: columns would have needed a data migration nothing here can run. An index is what is left |
| SPEC-019 the documentation gate reaches names, not meanings | ✅ **RESOLVED 2026-08-25** — every input key the contract declares, at any depth, must be named in the manual section that accepts it. Unblocked by SPEC-017, which gave the contract the key list. Found 46 undocumented keys, including the whole voucher and overhead-rate vocabulary |
| IMPL-031 the pack docs described a product that does not exist | ✅ **RESOLVED 2026-08-26** — every `de` module document named a module id the pack does not have, the `de` balance sheet listed positions from an older draft, and the `us` balance sheet had the two sides of the chart swapped (equity at 2000–2499, payables at 3000–3099). Headers, position tables and the folder READMEs now come from the modules; five missing documents written, including the `default` pack's first. Guarded by `PackDocsTest`/`pack-docs.test.ts` in both languages: one document per shipped module, header states the real kind/id/version, every mapping row names the accounts its position really claims |
| IMPL-032 the `default` pack cannot produce a statement, and did not say so | ✅ **RESOLVED 2026-08-26** — it ships no mapping module on purpose (a jurisdiction-free chart has no lawful gliederung to bring), but the caller learned it from `balanceSheet requires the parameter "mapping"`, which reads as *you forgot something*. The refusal now names the situation and carries `available` — empty for this pack — and points at `importMapping`; `tenantConfiguration.mappings` answers the same question without an error, and `default-pack-has-no-mappings` pins that the two agree. Documented where a reader looks: the manual's pack list and the pack's own README |
| SPEC-021 `accountSheet` lines could not reach their own entry | ✅ **RESOLVED 2026-08-26** — `entryId` (the identity `journal` already publishes) plus `contraAccounts[]`, the accounts on the other side of the same entry, deduplicated and sorted. A list rather than a field, because a tax code puts two or more there and naming "the" counter account would invent a fact; the side is decided per line, so one entry reads differently from the two sheets it appears on. Additive — the runner compares subsets, so no fixture changed. Reported by the embedding app (its F-31), fixture `account-sheet-entry-reference` |
| SPEC-020 `actorIsAuthenticated` could only ever say `false` | ✅ **RESOLVED 2026-08-26** — the field was right and unusable: a generated Verfahrensdokumentation printed it as "Urheber geprüft: nein" about an installation that had grown a login. `auditTrail.actorAuthentication` now carries `byLibrary` (false, and it can never go stale) plus the embedding's own `declaredByEmbedding`/`method`, declared in `summae.json` and passed on every open — **never stored**, because it describes the running installation and not the books. `null` survives as `null`: not declared is not a denial. Reported by the embedding app (its F-30); `ActorAuthenticationTest`/`actor-authentication.test.ts`, five cases in both languages |
| IMPL-030 both shipped packs hid the appropriation entry from the balance sheet | ✅ **RESOLVED 2026-08-26** — `de-bilanz` claimed 2000–2499 wholesale and `us-gaap-balance-sheet` 3000–3999, so each swallowed its own `result_allocation` account next to retained earnings and the two lines of a correct resolution cancelled inside one position. The balance sheet did not move and kept reporting the prior year's result as this year's. Ranges cut, labels stopped promising "this year", guard in `PackCompletenessTest`/`pack-completeness.test.ts` plus fixture `de-profit-appropriation` over the shipped pack. Found by the embedding app (its F-32) |

SPEC-004, IMPL-008, the IMPL-005 remainder, IMPL-015 and IMPL-018 were all closed on 2026-08-16, and IMPL-019 +
IMPL-020 were **found and closed** the same day while closing the gate gaps below.

**2026-08-24: the list is being filled from outside.** IMPL-026 did not come from this side at all —
it came from an app embedding summae, which hit it building a fixed-asset screen and wrote it down
as its own F-15. That is the return on dogfooding, and it is also a warning about where our own
tests stop: every asset fixture until now either disposed before the yearly run or never combined
the two, so the suite was green on an ordering that put a credit balance on an asset account. The
other findings that arrived with it are tracked as requirements rather than findings, because they
ask for a capability the library does not have yet rather than reporting one that misbehaves.

### SPEC-020 — `actorIsAuthenticated` could only ever say `false` — RESOLVED

**Reported from outside 2026-08-25** by the embedding application (its F-30), against 0.13.0, and
the one entry on that list where **the library was not wrong and the answer was still unusable**.

`systemDescription.auditTrail.actorIsAuthenticated` is a constant `false`. Read as *"this library
does not authenticate anybody"* it is exactly right: summae is handed an `actor` string and has no
way to know where it came from. The trouble is what the field is **used for**. The reporting app
puts it into the generated Verfahrensdokumentation under obligation A-1 as "Urheber geprüft:
**nein**" — deliberately, because the alternative is the app asserting something about itself in a
document whose whole point is that the technical part is *read* rather than written. Then the app
grew a login: scrypt in the people register, a signed session cookie, a gate nothing passes but the
login screen. The document went on telling an auditor that the identity behind every entry is
unverified, about an installation where a password had been proved before the actor was ever set.

An understatement in a compliance document is cheaper than an overstatement. It is not free.

**Both wishes were built, because they answer different halves.** `auditTrail.actorAuthentication`
carries `byLibrary: false` — the name that cannot go stale, whatever any embedding does — next to
`declaredByEmbedding` and `method`, which are the embedding's own sentence, quoted. `actorIsAuthenticated`
stays exactly as it was: it was never wrong, only easy to misread, and the note now says which of
the two questions it answers.

**Three states, and the third is the decision.** `true` and `false` are statements; `null` means
nothing was declared, and it survives as `null` all the way to the caller. An unanswered question
and a denial read differently to an auditor, and turning the first into the second is precisely the
error this finding is about — so a malformed declaration (a `method` with no `declared`) is ignored
rather than half-read into a claim nobody made.

**Not stored, and that is the other decision.** It would have fitted the `summae_tenants.config`
record next to the four things SPEC-015 put there, and it does not belong: this describes the
*running installation*, not the books. An embedding that drops its login tomorrow must not leave
yesterday's claim behind in a record that outlives it. So it arrives on every construction, like
the pack does, and in the CLI it lives in `summae.json`.

What summae is doing here is reporting a declaration, not endorsing one — it cannot verify that a
login exists and does not pretend to. That is still worth more than the app writing the line by
hand into the document, which was the outcome the reporter explicitly did not want: a hand-written
technical description is the part that quietly stops matching the software.

### SPEC-021 — `accountSheet` lines could not reach their own entry — RESOLVED

**Reported from outside 2026-08-25** by the embedding application (its F-31), against 0.13.0, while
making the account sheet answer the question it raises on every line: *6000 in debit, against what?*

The projection returned `sequenceNumber`, `entryDate`, `text`, `side`, `money`, `runningBalance` and
the reversal fields — no entry identity and no counter accounts. Both omissions have the same root:
the sheet is an extract of **one** account and knows nothing about the other lines of the entries it
is made of. Correct as a definition, and it left the caller two dead ends. The route to the entry was
`journal` with `fromDate` and `toDate` on the same day, then filtering that day's entries by
`sequenceNumber` — a search where a lookup belongs, for an entry whose identity the caller had two
fields ago. The counter accounts could not be formed at all without the embedding combining figures
of its own, which is the one thing a bookkeeping API should make unnecessary.

Both were built. `entryId` is the identity `journal` publishes and the audit trail records — the sheet
was building its lines from those very entries and dropping it.

`contraAccounts[]` is a **list**, and that is the whole decision: a plain entry has one counter
account, an entry with a tax code has two or more, and a field called "the counter account" would
have to pick one and thereby invent a fact. It is decided **per line** rather than per sheet — on a
debit line the credit accounts answer the question, on a credit line the debit ones — so the same
entry reads differently from the two sheets it appears on. The fixture pins both views of the same
two entries for exactly that reason.

Cheap in the end, and cheaper than it looked: the runner compares **subsets**, so a new field on an
object turns no existing fixture red. What the report called the second, optional half turned out to
be the half only the library can supply.

### IMPL-032 — the `default` pack cannot produce a statement, and did not say so — RESOLVED

**Found 2026-08-26** while checking whether the appropriation defect (IMPL-030) reached all three
shipped packs. It did not, for a reason worth recording: `default` ships **no mapping module at
all** — no balance sheet, no income statement, no cash-basis categories — while shipping the
accounts they would need.

That is the right answer and not a gap. A jurisdiction-free chart has no lawful statement layout it
could bring; every gliederung is somebody's law, and inventing one would be the `default` pack
claiming a jurisdiction it exists to avoid.

**What was wrong is how a caller found out.** `balanceSheet` requires `mapping`, so the refusal was
`balanceSheet requires the parameter "mapping"` — which reads as *you forgot something*. Pass one
anyway and the next refusal is `mapping "x" is not loaded`, which reads as *you named the wrong
one*. Neither says *this pack ships none, load one*. For an application, and much more for an
agent, that is the difference between a dead end and an instruction.

The refusal now distinguishes the two situations and carries `available` either way — the ids this
tenant could pass, empty for `default`. No new mechanism was needed for the honest answer: a tenant
that reports `mappings: []` through `tenantConfiguration` has already said it, and the fixture
`default-pack-has-no-mappings` pins that the projection and the error agree. Documented in the
manual's pack list and in the pack's own README, both of which a reader reaches before an error.

Deliberately **not** built: letting a pack declare which projections it equips. That would be a new
kind of statement about a pack (the `packPolicy.vatPeriods` shape), and the data to answer this
question already exists. If a second question of that kind turns up, it is worth revisiting.

### IMPL-031 — the pack docs described a product that does not exist — RESOLVED

**Found 2026-08-26** while repairing the balance-sheet mappings (IMPL-030) and looking for every
place that had to be corrected with them. `knowledge/99-pack-docs/` is the reference work for
whoever builds or audits a pack — one file per module, position by position. Nothing held it
against the modules, and the drift was total rather than detailed:

- **Every one of the eight `de` module documents named a module id the pack does not have**
  (`de-konten-2026` for `de-konten`, `de-hgb-bilanz-266` for `de-bilanz`, `afa-de` for `de-afa`, …).
- The **`de` balance sheet** listed positions `A`, `B.I`, `B.II`, `B.III` / `A`, `C.1`, `C.2`, `C.3`
  where the module ships `A.I`–`A.V` and `P.A1`–`P.D` — not one key in common.
- The **`us` balance sheet** had the two sides of the chart **swapped**: equity documented at
  2000–2499 and payables at 3000–3099, the exact opposite of the module. Eleven of eleven rows wrong.
- Four `us` documents stated a version the module had moved past; `de-guv` was missing three
  positions and `de-euer` one; five modules had no document at all, and the `default` pack had no
  folder.

They read as design notes written before the modules were built and never reconciled. **A reference
work that is wrong is worse than none: it is believed** — and this one is what somebody builds the
next pack from.

**Resolved by making the checkable parts checked.** Headers, position tables and the folder READMEs
are now derived from the modules; the prose was kept, since a document may well call a position
something clearer than the module's own label. Five documents were written, among them the
`default` pack's first two — which is also where IMPL-032 became visible enough to record.

The guard is `PackDocsTest` / `pack-docs.test.ts`, identical in both languages, three rules that a
pack author can satisfy without guessing: every shipped module has exactly one document, found by
its own `id:` header; that header states the module's real kind, id and version, so a version bump
cannot land without the document being opened; and every mapping table names each position of its
module with the accounts that position really claims. Verified red against the old content in both
languages.

One lesson about the guard itself: its first version had a dangling `else` and therefore checked
only single account numbers, not ranges — it reported four rows where there were thirty-one. A test
that passes for the wrong reason is the failure mode a documentation gate is most prone to, because
nobody rereads what turned green.

### IMPL-030 — both shipped packs hid the appropriation entry from the balance sheet — RESOLVED

**Reported from outside 2026-08-25** by the embedding application (its F-32), measured against
0.12.0: *"the result of a year is never carried forward, and the next year's balance sheet says it
was."* Books with three entries and a result of 900.00 —

```
incomeStatement 2027 → netIncome 0.00, positions []
balanceSheet    2027 → P.A2 "Jahresergebnis" 900.00
journal         2027 → count 0
```

— two reports of the same empty year, disagreeing. The report proposed carrying the result at
`closeFiscalYear`, or failing that a balance sheet that distinguishes this year's result from what
was carried in.

**Both proposals were wrong, and the second was nearly right.** The engine has been correct since
v0.3 and says so in `api.md`: a balance sheet is a snapshot, the `includesNetIncome` position holds
the **cumulative** result, and that is precisely what makes it balance without closing entries —
the same deliberate choice that makes the carry-forward implicit for *every* balance-carrying
account. Carrying the result by posting would have made the result the one exception.

And distinguishing the two was already built, in v0.4, as F-CORE-024/SF-25: appropriating profit is
a **resolution**, not a calculation (§ 29 GmbHG, § 174 AktG — distribute, reserve or carry forward
is not a library's decision), so it arrives as an ordinary entry, `result_allocation` account
against retained earnings, and the position then reports the result *not yet appropriated*. The
packs even ship the accounts: `de` 2300/2100, `us` 3300/3100.

**What was actually broken was the product data.** `de-bilanz` claimed 2000–2499 wholesale, which
swallowed 2300 next to 2100; `us-gaap-balance-sheet` claimed 3000–3999, swallowing 3300 next to
3100. The two lines of a correct resolution therefore cancelled each other *inside one position*.
Measured on the shipped `de` pack:

```
post 2300 → 2100  900.00        (the documented path, booked correctly)
balanceSheet 2027 → P.A1 0.00 | P.A2 "Jahresergebnis" 900.00     ← unchanged
```

The only visible effect of a correct entry was a new zero row. With the range cut around the
account and nothing else altered, the same books report `P.A1 900.00 | P.A2 0.00`, and 2026 is
untouched.

**Why nothing caught it, which is the part worth keeping.** The schema validates shape, not
meaning. `PackCompletenessTest` checked that profit-and-loss accounts are assigned, which a balance
sheet legitimately does not do. And every fixture covering SF-25 brings a **mapping of its own**
that gets it right — mechanism proven on inline data says nothing about the data shipped with the
product. Both gaps are closed: the completeness guard now requires each `result_allocation` account
to sit in the result position and nowhere else (verified red against the old mapping in both
languages), and `de-profit-appropriation` drives the path over the shipped pack, dating the
resolution in the *following* fiscal year — which is also why `closeFiscalYear` could never have
done this on anyone's behalf.

`E_MAPPING_OVERLAP` is why the repair is a cut rather than an addition, and presumably why it was
missed: adding the account to the result position without cutting the wholesale range is an error.

Left open by this finding, recorded separately: the pack documentation describing these mappings is
stale in its own right (IMPL-031), and the `default` pack ships no mapping at all (IMPL-032).

### IMPL-026 — a yearly run before a mid-year disposal left the asset account below zero — RESOLVED

**Found by the embedding app, not by us** (its `FINDINGS.md`, F-15), and it is the only finding on
its list where the books came out wrong rather than merely incomplete.

Acquire 3.600,00 on 15 January over 36 months, `runDepreciation({ fiscalYear: 2026 })`, then
`disposeAsset({ disposedOn: '2026-09-30', proceeds: 2.000,00 })`. Expected a carrying amount of
2.400,00 and a loss of 400,00. What happened: the disposal wrote off 3.600,00, booked a loss of
1.600,00, and left the asset account at **−1.200,00**.

Two readings of "how much is already depreciated" disagreed. The yearly run books the whole year in
**one entry dated 31 December** and records twelve plan months against it. `catchUpDepreciation`
(IMPL-022) asks the **plan**, finds every month recorded, and books nothing — correct.
`bookValueAt(disposedOn)` asked the **posting dates**, saw the 31 December entry as later than
30 September, ignored it, and reported the full acquisition cost as still carried. So the write-off
was computed against a ledger state that no longer existed.

The fix is one argument: the disposal reads `bookValueAt(null)`, the whole ledger. What made it the
obvious answer rather than a choice is that **every other caller in the service already did that** —
the write-down, the special depreciation, the usage report, the traces, all of them ask for the
accumulated depreciation without an as-of. The disposal was the only as-of query in the file, and it
is the one place where an as-of query cannot be right: what leaves the account has to equal what
stands on it, or the account cannot reach zero. That is F-AST-004, and the fixture pins the trial
balance rather than the asset's own numbers for exactly that reason.

**What was deliberately not changed.** The disposal year's depreciation is not re-apportioned to the
disposal month. Under the fix the year keeps its twelve months of depreciation and the disposal takes
the difference into its result — 1.200,00 depreciation + 400,00 loss, against 900,00 + 700,00 if the
three months after the disposal were given back. The income statement carries the same 1.600,00
either way, and the split is a *jurisdiction's* answer: Germany apportions monthly, US conventions
use half-year or mid-quarter. IMPL-022 already recorded that reasoning for the catch-up direction and
left it to the pack; taking it back in the core here would have made the same mistake IMPL-025
describes, one direction over.

**Where our own coverage failed.** Twelve asset fixtures, and not one of them ran a yearly
depreciation *before* a mid-year disposal. Each half was tested; the order was not. The new fixture
pins the order, not the arithmetic.

### IMPL-025 — the pool-disposal rule was German law inside the core — RESOLVED

Roland's catch, and the sharpest finding of the day: fixing IMPL-019 I wrote „a pooled asset keeps
depreciating after disposal" straight into `runDepreciation` as `route !== 'pool'`. That is not a
property of pooling — it is **§ 6 Abs. 2a Satz 4 EStG**, one jurisdiction's answer. The UK and
Australia do the opposite: disposals are taken out of their pools. So every future pack with a
pooled de-minimis regime would have inherited the German answer silently — the exact mistake SPEC-004
had already fixed for the pool *period*, one line further down in the same file.

It is now `poolReducedOnDisposal` in the depreciation module, conditionally required next to
`poolMax` in the schema (like `poolYears`), and refused rather than defaulted: a pack that opens a
pool must answer both questions. `de-pack` says `false` (§ 6 Abs. 2a), and the two fixtures
`pool-unaffected-by-disposal` / `pool-reduced-on-disposal` drive the same sequence through both
answers — the same core, two results.

**The lesson generalises:** the litmus test in `CLAUDE.md` ("does your code cite a statute → wrong
layer") catches statutes that are *quoted*. It does not catch a statute that has been silently
translated into a condition. Both my IMPL-019 fix and its comment read as mechanism; only the
question "would another country answer this differently?" exposes it.

### IMPL-024 — pooled assets reported a carrying amount of zero — RESOLVED

`bookValueAt` short-circuited to zero for every route except `capitalize`. True for an immediately
expensed asset, which was never capitalised — false for a pooled one, which sits on the pool
account and is written down over the pack's term. The fixed-asset schedule (F-AST-005) therefore
reported zero book value for assets that are in the balance sheet with a real one. Invisible while
nothing consumed the value for pooled assets; the IMPL-021 write-off consumed it, and the disposal
of a pooled asset under a `poolReducedOnDisposal: true` pack wrote off nothing at all.

### IMPL-023 — a machine entry cannot carry a required dimension — RESOLVED

Found when the disposal catch-up started booking depreciation in `edge-errors`, whose rule module
makes a cost centre mandatory for 4000–4999. `postMachineEntry` builds its lines itself and has no
dimension to give, so any tenant that puts a mandatory dimension on the depreciation account cannot
run depreciation **at all** — neither the regular run nor the catch-up. Pre-existing, not caused by
this work; the fixture dodges it by moving the account out of the range.

**Decided: the asset carries its dimensions.** `acquireAsset` takes `dimensions`, the asset stores
them, and every machine entry about it — acquisition, the regular run, the disposal catch-up, the
disposal itself — books every line with them. Both persistence adapters carry them through the
round trip, so a restart does not silently make depreciation impossible again.

Why not the alternatives:

- *Exempt machine entries from dimension constraints* would have been wrong on the merits.
  Depreciation is exactly the kind of expense cost accounting wants per cost centre — exempting it
  guts the constraint at the one place it matters most.
- *A default dimension in the rule module* answers "which cost centre?" once for every asset in the
  company, which is not an answer anyone would want.
- *Leave it to the pack to keep such accounts out of dimension ranges* would have made the pack work
  around a core limitation.

The chosen way is also plain fixed-asset practice: an asset belongs to a cost centre and its
depreciation belongs there with it — a master-data fact, not a jurisdiction's rule, so it stays in
the core without repeating the IMPL-025 mistake.

### IMPL-022 — the disposal does not catch up depreciation to the disposal date — RESOLVED

> **Resolved 2026-08-16.** `dispose` now books the depreciation that is due before it writes
> anything off. Which months are due follows the schedule's own convention — a plan month falls due
> on its last day, exactly as `monthTarget` reads it for the regular run — so no new rule enters the
> core. **Deliberately left to the pack:** whether the month an asset leaves in counts as a whole
> month is a jurisdiction's answer (Germany grants it, US conventions are half-year or mid-quarter),
> so an asset disposed mid-month gets no depreciation for that month today. That is the honest
> limit, and it is the same shape as IMPL-025 — the moment we answer it in the core, we have written
> law again. Original finding:

Fallout from fixing IMPL-021, and visible only because the write-off exists now. `bookValueAt`
reports what has actually been **booked**, not what would be owed up to that date; the yearly
`runDepreciation` books on 31 December. Dispose an asset on 30 June without running depreciation
first and the carrying amount written off is the one from the start of the year — the loss is
overstated by exactly the pro-rata share, and the expense lands in the disposal account instead of
in depreciation.

Deliberately not fixed along the way, because it is a second decision rather than a missing line:
`dispose` would have to trigger a partial depreciation run of its own, which makes one operation
write two economically different entries and raises its own questions (which voucher? what if the
period is already closed? what if the caller *wants* to book depreciation separately, as the
period-wise `runDepreciation(fiscalYear, period)` path suggests). The honest interim state: run
depreciation up to the disposal period first, then dispose. `pool-unaffected-by-disposal` does
exactly that and says so.

### IMPL-021 — asset disposal never writes off the carrying amount — RESOLVED

> **Resolved 2026-08-16.** `dispose` now books the whole event: the carrying amount is credited
> off the asset account, and the difference between proceeds and book value goes to the pack's
> `disposalProceedsAccount` (gain) or `disposalLossAccount` (loss) — the two accounts the resolver
> had been requiring and nothing had been booking. A scrapping without proceeds is the loss case;
> a fully depreciated asset scrapped for nothing books no entry at all rather than an empty one.
> Pooled assets are exempt, because IMPL-019 established that the pool is not reduced when an item
> leaves. The write-off is a single credit against the asset account because this core depreciates
> *net* — there is no accumulated-depreciation account to reverse. Original finding:

`dispose` sets the asset's status and, if proceeds were passed, books exactly one entry:
`bankAccount → proceedsAccount`. The asset account is never relieved. Two consequences, both
silent:

- A disposed asset **stays in the balance sheet at its carrying amount**, forever. The fixed-asset
  line keeps something the company no longer owns.
- The proceeds land as income **in full**, instead of as a gain or loss against book value. Sell a
  machine with 1500.00 book value for 2000.00 and the books show 2000.00 income rather than 500.00
  gain — profit overstated by exactly the carrying amount.

`disposalProceedsAccount` and `disposalLossAccount` are declared in the pack, and the resolver
*requires* them (I3, `E_PACK_UNRESOLVED_REF` if missing) — but no code path books either of them.
`proceedsAccount` comes from the caller's input instead.

**Not fixed unilaterally**, because the fix is a design decision, not a missing line:
1. **Which entry?** The customary form nets it — `bank + accumulated depreciation → asset +
   gain/loss` — but that presumes gross presentation with an accumulated-depreciation account,
   and this core depreciates by crediting the asset account directly (net presentation). So the
   entry is `bank → asset (carrying amount)` plus the difference to `disposalProceedsAccount` or
   `disposalLossAccount`.
2. **Depreciation up to the disposal month** would have to run first, otherwise the carrying
   amount being written off is stale. Today `runDepreciation` is a separate call.
3. **The pool must be exempt** — IMPL-019 just established that the pool is not reduced when an item
   leaves. So this is `route === 'capitalize'` only, and the two rules must not collide.
4. **Are the pack accounts mandatory or is the input override kept?** Today's `proceedsAccount`
   input parameter is documented and used by fixtures.

**How it escaped the sweep that found IMPL-019/IMPL-020:** F-AST-004 *has* a `covers` link, so it never
showed up in the list of uncovered requirements. The fixture it points at (`edge-errors`) only
exercises the error paths — `E_ASSET_UNKNOWN`, `E_ASSET_DISPOSED`, and `status: "disposed"`. It
asserts no booking at all. A `covers` link means "some fixture names this requirement", not "this
requirement is checked" — the sweep needs a second pass over the linked ones, not just the
unlinked.

The fixture `pool-unaffected-by-disposal` written on 2026-08-16 records the wrong behaviour in one
row (the disposed machine still standing at 2400.00) and says so inline, so the fix has to change
it visibly rather than silently agreeing with it.

### IMPL-019 / IMPL-020 — found by asking which requirements have no test

Neither came from a bug report; both came from listing the requirements (`30-anforderungen/`)
against the `covers` fields of the fixtures and then checking, for each requirement without a
link, whether the capability exists in the code. That separates two very different things: not
built yet (no finding — six requirements are in that group and the handbook claims none of them)
versus **built and unwatched**, which is where these two sat.

- **IMPL-019** — `runDepreciation` skipped every disposed asset. Correct for a single asset, wrong
  for a pooled one: F-AST-006 requires the pool to be written off on its fixed schedule
  *unaffected by disposals*, and the jurisdiction behind the rule states it outright — the pool is
  not reduced when an item leaves. The effect was silent and directional: too little depreciation
  and too much profit, for every remaining year of the term. Fixed in both languages by exempting
  `route === pool` from the skip; the disposal still books its proceeds.
- **IMPL-020** — `supplierTaxationMethod` sat in `format.schema.json` as `enum ["accrual","cash"]`,
  in `datenformat.md` with F-TAX-007 next to it, and in both `Voucher` classes — but the PHP
  constructor call passed a literal `null` for it and the Node object literal omitted it, so no
  caller could ever set it. The field decides whether input tax is deductible on invoice or only
  on payment. An unknown value is now rejected rather than dropped: storing null silently would
  read as "supplier taxes on accrual", which is the answer that permits the earlier deduction.

### IMPL-018 — four error codes fall through to exit code 1 — RESOLVED

> **Resolved 2026-08-16.** The four codes were appended to `ExitCodes`/`exit-codes.ts` (49–52),
> together with `E_AMOUNT_SCALE_MISMATCH` (53): it is declared in the catalogue but not yet
> thrown anywhere, and mapping it means the new guard test can demand the *whole* catalogue
> without an exception list — an exception list would be the same hole again. Nothing was
> renumbered. The missing piece was never the four entries but the comparison: `ExitCodesTest`
> (PHP) and `exit-codes.test.ts` (Node) now parse `testing/testsuite/fehlerkatalog.md` and fail
> when a catalogued code maps to `1`, when two codes share a number, or when an insertion shifts
> the anchors (10 / 45 / 53).
>
> The reservation below — that the numeric mapping needs a knowledge-base decision the way
> `E_INPUT_INVALID` did — turned out not to apply: the catalogue carries *names*, not numbers
> (the number is the position in the append-only list), and all five codes were already in it.
> So no knowledge-base change was needed, and the fix is code + guard.
>
> **The gap in the other direction is closed too.** `E_NOT_IMPLEMENTED` had an exit code (44)
> and a handbook entry but no catalogue row — invisible to every machine check, including the
> guard above. The catalogue's line is not "domain errors" but *everything a caller can rely on*
> (which is why the pure CLI code `E_WORKSPACE_INVALID` is in it), so the row was missing, not
> withheld. It was added in the knowledge base on 2026-08-16 and mirrored here; both guards now
> compare the two lists **as sets**, so neither direction can drift. 44 codes, covering each
> other exactly.
>
> Original finding:

Found while checking the handbook's error catalogue against the code. `E_SETTLEMENT_EXCEEDS_ENTRY`
(new with R-1) and the three pack-composition codes `E_PACK_UNRESOLVED_REF`, `E_PACK_INCOHERENT`
and `E_POLICY_INVALID` are thrown by the core but are not in the `CODES` list that
`exitCodeFor`/`ExitCodes` maps, so they return `1` — which the CLI documents as *unknown error*,
i.e. indistinguishable from a summae bug. It hits `summae init --pack …` (all three pack codes)
and every settlement that over-claims its entry.

The JSON on stderr still names the code, so nothing is lost for a human reader; a script that
branches on the exit code cannot tell these four from a crash.

**Not fixed unilaterally**, because it is not a code-only change: the list is **append-only and
identical in both languages**, and the numeric mapping is part of the error catalogue in the
knowledge base (the same route `E_INPUT_INVALID` took, exit 45). Appending four entries in
`exit-codes.ts` + `ExitCodes.php` would be mechanical; the catalogue entry is Roland's call.
Documented in the handbook meanwhile, so the gap is at least visible to users.

The **entire Round 1 backlog (R-1 … R-12) is closed** as of 2026-08-16 — the twelve defects the
two adversarial probing agents turned up on 2026-08-15. Per-item write-ups:
the Node-side section below.

Format per finding:

```
## F-XXX: short title
- **Job:** JOB-NNN
- **What:** description of the contradiction / gap
- **Where:** file(s) + section in spec/fixture/model
- **Chosen behavior:** what the implementation does now
- **Proposal:** recommendation for spec v0.3
```

---

## SPEC-001: No error code for unknown voucherId — ✅ RESOLVED

> **Resolved.** The proposed dedicated code was introduced: `E_VOUCHER_UNKNOWN` is in the
> error catalogue and in the exit-code table (`ExitCodes.php` / `exit-codes.ts`), and
> `testing/testsuite/fixtures/core/voucher-unknown.json` pins it. Original finding:

- **Job:** JOB-003
- **What:** `E_ENTRY_NO_VOUCHER` is defined as "voucherId missing". For a
  *set but unknown* voucherId no code exists; no fixture covers the case.
- **Where:** fehlerkatalog.md (E_ENTRY), api.md (post)
- **Chosen behavior:** an unknown voucherId is also reported as
  `E_ENTRY_NO_VOUCHER` (reference check step 2, before accounts).
- **Proposal:** either pin it down explicitly that way or introduce a dedicated code
  `E_VOUCHER_UNKNOWN` + fixture.

## SPEC-002: E_ENTRY_NOT_FINALIZED in api.md, but not in the error catalog — ✅ RESOLVED

> **Resolved in spec v0.5.** The code was dropped from the spec rather than added to the
> catalogue: `reverse` is status-independent, which is what the implementation already did.
> Original finding:

- **Job:** JOB-003
- **What:** api.md lists `E_ENTRY_NOT_FINALIZED`* for `reverse` (with footnote
  "decision open question 5"); the error catalog (29 codes, all with
  fixtures) does not know it. Fixture finalize-reverse-period reverses a
  *non*-finalized reversal posting successfully.
- **Where:** api.md (ledger table) vs. fehlerkatalog.md vs. finalize-reverse-period.json (Step 9)
- **Chosen behavior:** `reverse` is permitted independent of status (follows
  fixture + catalog).
- **Proposal:** resolve the footnote in api.md — remove the line from the error
  column or define the behavior for `entered` explicitly.

## SPEC-004: Account resolution for asset postings not specified — ✅ RESOLVED

> **Accounts: resolved.** The proposed keys became a pack module of their own — `kind:
> assetAccounts` (`pack-library/de-pack/assets/`, `pack-library/us-pack/assets/`) supplies the
> acquisition counter account, the depreciation expense account and the low-value-asset
> account as pack data, not as a name-matching fallback.
>
> **Pool period: resolved 2026-08-16.** The depreciation module gained `poolYears` on each
> `gwgThresholds` row; `AssetService` reads it and spreads the cost over exactly that many years
> (`poolYears × 12` plan months). The number that used to be compiled in — five years, one
> jurisdiction's rule — is now `de-afa`'s data; `us-macrs` carries `null` because the de-minimis
> route there is an outright expense, not a pool. Three guards, so the field cannot rot:
> `$defs/depreciationData` in the schema makes `poolYears` **conditionally required** wherever a
> `poolMax` is declared (validated for every shipped module by the pack-library schema test in
> both languages, with an explicit teeth check that a range without the field is rejected);
> `AssetService` refuses with `E_PACK_INCOHERENT` rather than defaulting, which covers hand-fed
> rule data that never passed a pack; and the conformance fixture `assets/gwg-pool-period`
> declares **three** years instead of the German five, so the old hard-coded behaviour would fail
> it loudly (36 plan months vs. 60, 300.00 depreciation vs. 180.00).
>
> Found while writing it: the **statute-citation guard has teeth**. The first version of the new
> comment named the paragraph and turned `no-jurisdiction-text.test.ts` red — which is exactly the
> mechanism working, and the reason it did not catch the original defect (the old comment named no
> paragraph, only the number 5).
>
> **Deliberately not built:** a pool with a *rate* instead of a *period* — the UK style, where a
> pool is written down at a percentage per year and never fully closes. That needs a method
> discriminator on the threshold, and no shipped pack has the case. Noted here so the next
> jurisdiction knows it is an extension, not a bug.
>
> Original finding:

- **Job:** JOB-009
- **What:** acquireAsset/runDepreciation generate postings, but neither spec
  nor rule-module data name the counter account (cash account), depreciation
  expense account or low-value-asset immediate-write-off account. The fixtures
  expect 1200/4830/4855.
- **Where:** assets-modell.md, api.md (Assets), gwg-and-depreciation.json
- **Chosen behavior:** rule-module keys `acquisitionCounterAccount`/
  `depreciationExpenseAccount`/`gwgExpenseAccount`; fallback convention:
  the single bank account, expense account by name part ("AfA"/"GWG").
- **Proposal:** add the keys to the rule-module spec; add fixtures.

## SPEC-005: journal-export-z3 vs. audit-trail — manifest streams contradict each other — ✅ RESOLVED

> **Resolved.** The contradiction is gone: `testing/testsuite/fixtures/io/journal-export-z3.json` now
> expects `formatVersion "0.4"` and `streams: [journal, accounts, vouchers, auditLog]` — the
> audit trail is always part of the export — and the schema declares `streams`/`hashAlgorithm`.
> Original finding:

- **Job:** JOB-011
- **What:** journal-export-z3 expects exactly [journal, accounts, vouchers]
  (even though post/finalize generate audit entries), audit-trail (v0.3) exactly
  [..., auditLog]. In addition journal-export-z3 expects formatVersion "0.2"
  (spec is v0.4), and the schema manifest does not know `streams`/`hashAlgorithm`,
  which the fixture requires.
- **Where:** journal-export-z3.json, audit-trail.json, schema/format.schema.json
- **Chosen behavior:** auditLog stream only on a real change history
  (actions beyond created/finalized); formatVersion fixed at "0.2";
  manifest validation limited to schema-known fields.
- **Proposal:** re-cut journal-export-z3 as a v0.4 fixture
  (auditLog always, formatVersion current), extend the schema manifest with
  streams/hashAlgorithm.

## SPEC-006: E_COSTING_RUN_UNKNOWN missing from the catalog — ✅ RESOLVED

> **Resolved.** The proposed code was added: `E_COSTING_RUN_UNKNOWN` is in the catalogue and
> the exit-code table, pinned by `testing/testsuite/fixtures/costing/costing-run-unknown.json`.
> Original finding:

- **Job:** JOB-010
- **What:** releaseCosting/costAllocationSheet with an unknown runId has
  no defined error code.
- **Chosen behavior:** dedicated code `E_COSTING_RUN_UNKNOWN` (analogous to
  E_OPENITEM_UNKNOWN).
- **Proposal:** add it to the error catalog + fixture.

## SPEC-007: balanceSheet side assignment by root order — ✅ RESOLVED

> **Resolved.** The proposed explicit field was introduced instead of relying on root order:
> `format.schema.json` `$defs/mappingPosition` declares `side: assets|liabilitiesAndEquity`
> at the root node, and `BalanceSheetProjection` / `balance-sheet.ts` read it. Original finding:

- **Job:** JOB-008
- **What:** the spec does not define which mapping root is assets and
  which is liabilities-and-equity; the fixtures consistently use [assets, liabilities].
- **Chosen behavior:** first root position = assets (debit−credit),
  all others = liabilities-and-equity (credit−debit).
- **Proposal:** `side: assets|liabilitiesAndEquity` on the mapping root node.

## SPEC-003: No error code for "fiscal-year close with non-finalized postings" — ✅ RESOLVED

> **Resolved.** The proposed dedicated code was introduced rather than reusing
> `E_PERIOD_OUT_OF_ORDER`: `E_FISCALYEAR_UNFINALIZED_ENTRIES` is in the catalogue and the
> exit-code table, pinned by `testing/testsuite/fixtures/core/fiscalyear-close-guard.json`.
> Original finding:

- **Job:** JOB-003
- **What:** api.md requires for `closeFiscalYear` that "all postings are
  finalized", but defines no code for the violation; no fixture.
- **Where:** api.md (period semantics, closeFiscalYear)
- **Chosen behavior:** `E_PERIOD_OUT_OF_ORDER` (the same code as for
  open periods — "close precondition violated").
- **Proposal:** consider a dedicated code `E_FISCALYEAR_UNFINALIZED_ENTRIES`
  or document the reuse.

## SPEC-C01: Timestamp serialization not canonical across implementations — ✅ RESOLVED

> **Resolved (2026-06-20):** canonical format introduced — UTC, RFC 3339 with
> a fixed millisecond place and `Z` (byte-identical to JS `toISOString`). PHP:
> new helper `Summae\Core\Substrate\Timestamp::canonical()`, used for `recordedAt`
> (JournalEntry + DB column `recorded_at`), `at` (AuditRecord) and `exportedAt`
> (journalExport). Node already produced the format. The bidirectional cross-test
> has since compared the **full** journalExport **byte-exactly** (incl.
> contentHashes + exportedAt), without any exception — 44/44 in both directions.
> No fixture pinned the timestamps, hence no conformance change. Spec note
> for `determinismus.md`: pin down the canonical timestamp format.

- **Job:** Node-M4 (SF-15 cross-test, both directions)
- **What:** PHP and Node serialize the timestamps `recordedAt` (posting) and
  `at` (audit) **differently**: PHP as ATOM with the offset preserved and without
  milliseconds (`2026-06-07T12:00:00+02:00`), Node via `toISOString` as UTC with
  milliseconds (`2026-06-07T10:00:00.000Z`). **Same moment, different
  notation.** Only noticeable in the bidirectional cross-test: in PHP→Node Node
  passes PHP's string through verbatim (fits), in Node→PHP PHP reformats on read
  via `DateTimeImmutable` → the inline fields *and* the derived
  `manifest.contentHashes` (sha256 over the raw stream bytes) diverge. The
  conformance suite tolerates it (normalized comparison); strict cross-impl
  byte equality does not.
- **Where:** `determinismus.md` (timestamp format not pinned); PHP
  `JournalEntry`/`AuditRecord` (ATOM via `DateTimeImmutable`), Node
  `recordedAt`/`at` as a raw string.
- **Chosen behavior:** the cross-test (`cross-read.ts`) compares `at`/
  `recordedAt` as an **instant** (normalized to UTC/ms) and leaves the format-
  dependent `contentHashes` + the volatile `exportedAt` out; all remaining
  fields byte-exact. Proves data parity, not notation equality.
- **Proposal:** pin a **canonical timestamp format** in `determinismus.md`
  (e.g. RFC 3339, UTC `Z`, fixed milliseconds) and pull both
  implementations onto it — then the `contentHashes` also match
  byte-exactly in both directions.

## SPEC-008: `format.schema.json` `mappingPosition` omits `includeNonCash` — ✅ schema extended

> **Resolved (2026-06-23):** `$defs/mappingPosition` now declares
> `includeNonCash` (`{ "type": "boolean" }`) — the schema matches the engine.
> **Still open (separate question):** pack-library JSON is not validated against
> the schema at all (only journalExport streams + manifest are). Whether to add
> schema validation for the pack-library (the third-party extension surface) is its
> own decision — this drift slipping through unnoticed is the argument for it.

- **Job:** us-pack build (2026-06-23)
- **What:** the cash-basis projection reads a position-level flag `includeNonCash`
  off the mapping leaf (`Policies/Projection/Mapping/Mapping.php` → `CashBasisProjection`
  R7: non-cash categories such as depreciation count without a cash flow). The us-pack
  module 5 (`us-schedule-c-2026`, kind `cash-basis-categories`) sets `includeNonCash: true`
  on its depreciation line (L13) per the module spec. But the normative
  `testing/testsuite/schema/format.schema.json` `$defs/mappingPosition` does **not** declare
  `includeNonCash` and carries `additionalProperties: false` — by the schema the field
  is illegal on a mapping position.
- **Where:** `testing/testsuite/schema/format.schema.json` (`$defs/mappingPosition`);
  `pack-library/us-pack/mappings/us-schedule-c.json`; core Mapping importer +
  `CashBasisProjection`.
- **Chosen behavior:** shipped `us-schedule-c-2026` with `includeNonCash: true` per the
  module spec and the engine that consumes it. Not currently breaking — pack-library JSON
  is loaded content-based (never validated against `format.schema.json`: `validate.py`
  skips module/pack files; `SchemaValidationTest` validates only journalExport streams +
  manifest), so the module resolves and runs green in both languages. First shipped
  `cash-basis-categories` module (the de-pack never shipped an EÜR mapping), hence the
  first time the gap surfaces.
- **Proposal:** extend `$defs/mappingPosition` with `"includeNonCash": { "type": "boolean" }`
  (meaningful only for `cash-basis-categories`) so the normative schema matches the engine
  before any move to schema-validate pack modules. Shared schema artifact — applies to Node too.

## SPEC-009: `cashBasisReport` hard-codes a German VAT-passthrough treatment — ✅ resolved

> **Resolved (2026-06-24):** the hard-coded German strings are gone from the core. Tax
> accounts flow through the cash-basis result **only where the pack's mapping maps them**
> (label from the mapping leaf); unmapped tax accounts are a neutral pass-through. DE: the
> `de-euer` mapping maps its VAT accounts → German labels from the pack. US: `us-schedule-c`
> leaves sales tax unmapped → neutral. Regression guard: `SubstrateBoundaryTest`
> (`testCoreEmitsNoHardcodedJurisdictionLabels`) + the Node `no-jurisdiction-text` test.

- **Job:** us-pack conformance audit (2026-06-24)
- **What:** the cash-basis projection routes tax accounts by subtype with **hard-coded German
  labels** (`tax_out` → `"Vereinnahmte USt"`/`"USt-Zahlung an FA"`, `tax_in` → `"Gezahlte
  Vorsteuer"`) — the German EÜR rule (VAT flows through profit). For the US, sales tax is a
  pure pass-through (never income). With `2100 Sales Tax Payable` marked `tax_out` (required by
  `vatReturn`, SPEC-… below), a SALETAX cash sale would count its collected tax as income under a
  German label — wrong for US.
- **Where:** `Policies/Projection/CashBasisProjection.php`; `pack-library/us-pack/accounts/us-accounts.json`.
- **Chosen behavior / workaround:** `us-schedule-c` posts its sample revenue **tax-free** so the
  mechanism (mapping labels + `includeNonCash`) is proven without tripping the DE-centric tax path.
- **Proposal:** make the cash-basis tax treatment pack-appropriate (neutral pass-through unless the
  cash-basis mapping maps the tax accounts; drop the hard-coded German strings). Behavior change with
  DE-fixture ripple → own job, human decision. Applies to Node too.

## SPEC-010: `EXEMPT` (rate-0 standard) cannot be posted — 0.00 tax line rejected — ✅ RESOLVED

> **Resolved in 0.5.0 (2026-06-24).** The proposal below was built: `exempt` is now a
> registered tax mechanism (`TaxMechanisms`) that tags the base and emits **no** tax line,
> and the us-pack `EXEMPT` code selects it. Exempt sales post cleanly and appear in the
> return. Pinned by the conformance fixtures and by the `us` walkthrough scenario
> (`testing/scenarios/walkthrough/us.json`). Original finding kept for the record:

- **Job:** us-pack conformance audit (2026-06-24)
- **What:** the us-pack `EXEMPT` code (mechanism `standard`, rate `0.00`) emits a 0.00 tax line.
  `expandTax` returns it fine (proven by `us-exempt-sale`), but `postVoucher`/`post` reject it with
  `E_ENTRY_INVALID_AMOUNT` (zero-amount line). Exempt sales **cannot be recorded in the journal** with
  the EXEMPT code today — only previewed via `expandTax`; they also cannot appear in the sales-tax return.
- **Where:** tax expansion (`standard` at rate 0) + ledger amount validation; `us-exempt-sale`, `us-sales-tax-return`.
- **Chosen behavior:** documented; `us-sales-tax-return` covers the taxable line only.
- **Proposal:** add an `exempt` mechanism (base tag only, no tax line) — analogous to
  `intra_community_supply` — so exempt sales post cleanly and show in the return (open decision E).
  Engine addition → own job. Applies to Node too.

---

## Cross-language findings (IMPL-005 … IMPL-007)

The three findings below were found while walking the CLI for the handbook (2026-08-14) and are
**identical in PHP and Node** — same code path, same result, so cross-language equivalence holds
and they are model/spec questions rather than parity defects.

They deliberately keep **one shared number in both files** instead of the older double numbering
(IMPL-002 ↔ SPEC-008, IMPL-003 ↔ SPEC-009, IMPL-004 ↔ SPEC-010), which made the same finding look like two.
`SPEC-011` is *not* reused here: that number belongs to the knowledge base's own finding list.

**Full analysis, assessment and proposed directions: the Node-side section below.**
Summary and the PHP sites:

- **IMPL-005 — cash-basis VAT counts the reversal of an *unsettled* open item immediately — ✅ FIXED
  2026-08-15.** The direct-contribution loop in `VatReturnProjection.php` now also skips an entry
  that *reverses* an entry carrying open items: its tax follows the reversed entry's settlements
  instead of counting as a cash movement. Reversals of genuinely cash-effective entries still count
  directly, at their own date. **Remainder closed 2026-08-16 by IMPL-008:** the settled-then-reversed
  case cannot arise any more, because a reversal is refused once the open item carries a settlement.
  That answers the question rather than choosing between its two readings — the tax stays declared,
  and the correction is a separate cash-effective posting with its own date, which is what
  § 17 Abs. 1 UStG prescribes ("in the taxation period in which the change occurred").
- **IMPL-006 — `cashBasisReport` without `year` raises an uncaught `InvalidValue`.**
  `CashBasisProjection.php:63` defaults a missing `year` to `0`, then builds
  `CalendarDate::of('0000-01-01')` in `assertCalendarYearFiscalYears`. Not a `DomainError`, so the
  CLI prints a stack trace instead of its documented JSON error line. Realistic trigger: every
  other projection except `vatReturn` takes `fiscalYear`.
- **IMPL-007 — a missing or unknown mapping reports `E_MAPPING_OVERLAP`.**
  `IncomeStatementProjection.php:46`, `BalanceSheetProjection.php:49` — a code whose name says the
  opposite of what happened, and the only error a tax-free configuration hits on a normal report.
  Current behaviour pinned in `testing/scenarios/walkthrough/default.json`.
- **IMPL-008 — RESOLVED 2026-08-16.** Researched rather than guessed, because the data-format
  decision hung on it. GoBD settles the first half: nothing is ever deleted, before or after
  finalization — even a finalized batch is corrected by a counter-entry, so the reversal posting
  itself was never in question. The half that mattered is a different axis, and SAP draws it
  sharply (message F5308, `FB08`): *"a reverse posting clears all line items that are managed as
  open items, but this is not possible if one of the items in question has already been cleared by
  another method."* Both halves are now built. An **untouched** item is cleared by the reversal —
  a settlement pointing at the reversal entry, marked `cause: "cancellation"`, which derives the
  status `cancelled`. A **touched** item refuses the reversal with the new
  `E_ENTRY_HAS_SETTLED_ITEMS`; the correction goes through a credit note or refund instead.
  The data-format change stayed small because status was already derived, not stored: the enum
  gained `cancelled`, settlements gained `cause`. `cancelled` and not `settled` is the whole point
  — `settled` reads as "the money came in".
  **The marker is load-bearing, not cosmetic.** Cash-basis VAT follows an item's settlements, so
  the cancelling settlement would have been read as a receipt: reversing a never-paid 1,190.00
  invoice would have declared 190.00 of VAT out of thin air. `vatReturn` skips cancellation
  settlements, and `vat-cash-basis-reversal-unpaid` pins it with a paid invoice alongside as the
  control case.
  While writing the schema for it, a second gap surfaced: `$defs/openItem` declared neither
  `remaining` nor `status` nor the settlement `difference`, all of which the engine has always
  written, under `additionalProperties: false` — the IMPL-002/SPEC-008 class again, latent because no
  test validated a stored open item against it. The declaration now matches what is written.
  Original finding:

- **IMPL-008 — a reversal leaves the reversed entry's open items standing.** `reverse` posts the
  counter-entry but does not touch the open items the reversed entry created: the trial balance
  shows the payable account at `0.00` while `openItems` still reports it open and settleable.
  Found while fixing IMPL-005; distinct from it and not its cause. A cancelled open item must keep
  its settlement history (dropping it would rewrite filed VAT periods), and whether it disappears
  or gains a terminal `cancelled` status is a **data-format** decision. Documented, not changed.

Each still-open item needs a spec decision (IMPL-007 an append to the error catalogue, IMPL-008 a
data-format decision) before either language moves.

- **IMPL-009 — `CalendarDate` accepted years 0000–0099 in PHP and rejected them in Node — ✅ FIXED
  2026-08-15.** A substrate-level equivalence break: Node validated by round-tripping through
  `Date.UTC(year, …)`, which maps years 0–99 onto 1900+year, so `0000-01-01`…`0099-12-31` were
  rejected there and accepted here. **PHP is unchanged** — Node was widened to match it by
  dropping the host `Date` from the value object entirely (explicit days-per-month table +
  Gregorian leap rule). Pinned by `CalendarDateTest` and Node's `calendar-date.test.ts`, which
  carry the **same** accepted/rejected tables (34 cases each). Full write-up:
  the Node-side section below.

- **IMPL-010 — `Money.of` accepted amounts the data format forbids — ✅ FIXED 2026-08-15.**
  Validation went straight to `brick/math`, which parses far more than
  `format.schema.json` `$defs/money/properties/amount` (`^-?\d+(\.\d{1,4})?$`) allows:
  `"1e3"` booked as `1000.00`, `"1.5e+21"` as `1500000000000000000000.00`, `"10."` and
  `".5"` likewise — and `"+10.00"` was accepted here while Node rejected it, a second
  substrate divergence after IMPL-009. `Money::of` now matches the string against the
  data-format expression before parsing; `fromCalculation` is untouched. Full write-up:
  the Node-side section below.

- **IMPL-011 — `post` accepted a caller-fabricated `taxTag` straight into the VAT return — ✅ FIXED
  2026-08-15.** `Ledger.php:706` stored whatever the caller sent; the VAT return is built from
  those tags, so an invented `reportingKey` became a line of a statutory return at exit 0.
  A tag whose `code` is set must now resolve in the `TaxCodeRegistry` (`E_TAXCODE_UNKNOWN`,
  existing code). **PHP needed the registry wired in twice** — `DatabaseTenantFactory.php:78`
  duplicates the ledger construction from `Tenant.php:96`, where Node has one path; worth
  removing that duplication separately.
- **IMPL-012 — `balanceSheet` silently ignored `fiscalYear` — ✅ FIXED 2026-08-15.** Two different
  years returned byte-identical sheets, while the cheat sheet and the gated scenarios both
  passed the parameter. It now scopes cumulatively ("as at the end of year N"); mirroring
  trialBalance's G1 rule was tried first and left the sheet unbalanced by exactly the prior
  year's result, because summae writes no closing entries. Full write-up:
  the Node-side section below.

- **IMPL-013 — a wrong `direction` booked an incoming invoice fully inverted — ✅ FIXED 2026-08-15.**
  `TaxService.php:58` treated anything that was not exactly `'input'` as an output voucher, so
  `"Input"` with a capital I credited the expense and debited the payable — the mirror image of
  the correct booking, carrying a valid tax tag so nothing downstream flagged it. An absent
  `direction` still defaults to `'output'`; a wrong value is now `E_INPUT_INVALID`.
- **`E_INPUT_INVALID` (exit 45) added to the catalogue** for "a parameter is present but not valid
  input". In use in `TaxService`, `CashBasisProjection`, `AccountSheetProjection`,
  `IncomeStatementProjection`, `BalanceSheetProjection`. The normative entry
  (`50-spezifikation/fehlerkatalog.md`, section `E_INPUT`) and the conformance fixture
  (`core/input-invalid.json`) were written in the knowledge base and mirrored via `make sync` —
  green in both languages on the first run.
- **IMPL-014 — RESOLVED 2026-08-15.** An unmapped account no longer vanishes from the income
  statement: it goes into the catch-all `_unassigned` and is named in `gapWarnings[]`, the
  treatment the error catalogue prescribes and `importMapping` already applied. `balanceSheet`
  stays as it was on purpose — its type-based sum is what makes the identity hold by
  construction. Full write-up: the Node-side section below.

## IMPL-015: `packages/laravel` has no tests of its own — gate gap

- **Job:** chore/coverage-all-packages (2026-08-15)
- **What:** the persistence adapter is the one package with no test suite. `packages/laravel/tests/`
  contains a single `.gitkeep`; `phpunit.xml.dist` declares a `laravel` testsuite that therefore
  runs zero tests. Root `CLAUDE.md` calls a contract surface without its own guard a gate-gap
  finding — this is one, and it sits on the surface that writes and reads the shared data format.
- **Where:** `implementations/php/packages/laravel/` (src ~534 statements), `phpunit.xml.dist`,
  `runner/bin/coverage-gate.php`
- **Chosen behavior (until 2026-08-16):** the package was **excluded** from the coverage source set
  and carried **no floor**, with the reason stated at both places. Measuring it would have pinned a
  number nobody maintains: it did get ~79 % line coverage, but purely as a side effect of the
  conformance runner's `database` subject and the cross-test driving it — coverage that no test in
  this package asserted anything about. Excluding it kept the gate honest and the gap visible.
- **RESOLVED 2026-08-16.** The adapter has its own suite: `AdapterTestCase` (in-memory SQLite with
  the `summae_*` schema) · `RepositoryRoundTripTest` (books written by one tenant instance and read
  back by a second one on the same connection, so everything asserted has genuinely been through a
  column) · `TenantScopingTest` · `HydratorAndSchemaTest`. 19 tests, line coverage 96.97 %, floor
  95 % in `coverage-gate.php` and the package added to `phpunit.xml.dist`'s source set.
- **And it paid for itself immediately: every `byId`, `byOriginEntry` and `save` in the adapter
  ignored `tenant_id`.** A repository built for tenant A handed out — and wrote over — tenant B's
  rows by primary key. Seven of the new tests were red on the first run. The root `CLAUDE.md` calls
  summae "multi-tenant at the data level", and the `tenant_id` column exists for exactly this, but
  nothing enforced it on the by-key paths. No existing suite could have seen it: the conformance
  runner builds one tenant per fixture and the cross test one per database, so an adapter that
  ignores `tenant_id` entirely passes both. **The Node `packages/knex` adapter had the identical
  defect** — same lines, same fix, and it had no tests of its own either. Both are fixed and both
  now carry the mirrored suite (`packages/knex/test/adapter.test.ts`), verified red-before-green in
  each language.
- **Still uncovered:** `SummaeServiceProvider` (0 %, ~15 statements) — Laravel framework glue that
  needs a booted application (`orchestra/testbench`) to exercise. The floor is set below the
  measured value *with* that hole, so covering it later can only push the floor up.
  Deliberately **not** done here: it is a test-writing job, not a gate-wiring one. Node has no
  counterpart — `packages/knex` has no tests of its own either, but is covered through the CLI
  package's tests and does carry a floor there.

## SPEC-011: F-KLR-001 says evaluations read released runs only — a fixture reads a draft

**Found 2026-08-23, while building `overheadRates` (F-KLR-004).**

F-KLR-001 reads: "Abrechnungsläufe MÜSSEN je Periode versioniert sein (draft → released);
**Auswertungen lesen nur released Läufe.**" Taken literally, `costAllocationSheet` and the new
`overheadRates` should refuse a run that is still `draft`.

The append-only fixture `core/parameter-effect` reads `costAllocationSheet` for a run it never
releases, and expects numbers back. So the contract, as it has always been exercised, says draft
runs *are* readable.

**Not resolved by bending the fixture.** Editing it would rewrite what the contract always said and
retroactively make every implementation that agreed with it wrong — the reason the suite is
append-only in the first place. Nor by enforcing the rule on the new projection only: two costing
projections with different rules about the same run is worse than one consistent rule.

**Built with the next most plausible behaviour:** both projections read a run in any status and
return `status` in the answer, so a caller can see which they got and decide. What is missing is the
*decision*, not the code — either the requirement means "the embedding application must only publish
released runs" (in which case it is an app obligation and F-KLR-001 should say so), or it means a
hard refusal (in which case a new fixture has to establish it and `parameter-effect` stays as the
record of what the contract used to allow). Left open deliberately; it is a question about intent,
and guessing it would be the same mistake in the other direction.

## SPEC-012: the shipped pack's manifest version cannot change — a fixture pins it

**Found 2026-08-23, while adding the `productionCost` module to the `de` and `us` packs.**

`pack/de-pack/de-pack-resolves` calls `resolvePack({ manifest: "de", version: "2026.2" })` and pins
`pack.version` in the tenant it then creates. Raising the manifest's own version therefore makes an
append-only fixture unresolvable — the pack the fixture asks for no longer exists.

The practical consequence is that **only module versions move**. Every pack change so far has bumped
the module (`de-afa` 2026.5 → 2026.6) and the manifest's *reference* to it, while the manifest's own
`version` has stood at 2026.2 through several rounds of real change. A consumer reading `pack.version`
— which `systemDescription` reports, and which is the field the "tzdata for accounting" idea rests on
— cannot tell those rounds apart.

**Not resolved by bending the fixture,** for the usual reason. What the contract needs is a decision:
either the manifest version is deliberately a *format* version and something else carries the content
version (then say so, and the field is fine as it is), or it is the content version (then the fixture
has to stop pinning an exact one — `resolvePack` would take the manifest name alone, and a *new*
fixture would establish that). Left open: guessing would either freeze the version forever or break a
published contract.

**Resolved 2026-08-23** (`fix/pack-version-immutability`). The second reading was the right one: the
manifest version *is* the content version, so the fixture had to stop pinning it. Investigating it
also made the finding worse than written above — a published `(id, version)` was not merely lagging,
it was **not immutable**. `de@2026.2` named at least three different bundles, because the module
files it referenced were overwritten rather than versioned alongside. The fix is in four parts:

1. `de-pack-resolves` is **superseded**, not edited (`testing/testsuite/superseded.json`), by
   `de-pack-resolves-current`, which pins the behaviour and leaves the product's numbers alone. Same
   for `us` and `default`. What the retired fixtures pinned about the mechanism moved to
   `xx-6-pack-version-pinning`, which brings its own pack and is therefore frozen for good.
2. `PackResolver::findManifest` is now the single place that selects a manifest — runner and CLI both
   call it — and a request without a version resolves to the **highest** version, not the first
   match, so several versions of one pack can live in the library side by side.
3. `resolvePack` returns a derived `contentDigest` (SHA-256 over the canonical JSON of the whole
   resolution, byte-identical in both languages), and a tenant carries it. It is what a hand-written
   version cannot be: impossible to forget.
4. `de` and `us` moved to `2026.3`, and `PackVersionIdentityTest` / `pack-version-identity` refuse two
   files claiming the same published identity.

Deliberately **not** done: no fabricated history. `de@2026.2` is gone rather than reconstructed from
today's modules, because a frozen file claiming to be the old bundle would be a second lie on top of
the first. Immutability starts here.

## SPEC-013: a shipped pack's chart of accounts cannot grow — a fixture pins its size

**Found 2026-08-23, while adding the exempt-export tax code to the `de` pack.**

`pack/de-pack/de-pack-resolves` pins `accountCount: 41` on the tenant it creates. Adding an account
to `de-konten` therefore breaks an append-only fixture — the same shape as [SPEC-012], where the
manifest's own version is pinned and so cannot move.

The concrete cost here: `AUSFUHR` (§ 4 Nr. 1 Buchst. a, Kz 43) reports an exempt export, and the
German chart has an account for exempt *intra-community supplies* (4030) and none for exempt
*exports*. A user has to add one with `createAccount`, which the fixture `de-ausfuhr` demonstrates
because it is what actually has to happen today. It works, but a shipped pack that cannot gain an
account as its tax codes gain coverage will keep accumulating that kind of hole.

**Not resolved by bending the fixture.** The decision it needs is the same one SPEC-012 needs, and
probably the same answer: what a fixture may pin about a *shipped* pack. Pinning the resolver's
behaviour is the point of `de-pack-resolves`; pinning the size of a product catalogue that is
expected to grow is a different thing that came along for the ride. Left open — a new fixture could
establish the resolver contract without the count, but deciding that is not a mechanical change.

**Resolved 2026-08-23** together with [SPEC-012] — it was the same defect, and the guess in the last
paragraph was right: the two needed one decision, not two. `de-pack-resolves-current` pins that the
pack resolves, that a tenant is built from it and that a standard VAT sale comes out right; it pins
neither the version nor the account count. The chart can grow again, which unblocks the missing
exempt-export account and the operating-expense accounts the embedding app asked for.

## SPEC-014: summae has no way to evolve a shipped database schema

**Found 2026-08-23, while scoping the costing persistence port.**

Both adapters create their tables exactly once, at workspace initialisation —
`SchemaInstaller::create` in PHP, `installSchema` in Node — and nothing upgrades an existing
database. PHP at least sits behind Laravel migrations, so a second dated migration file could add a
table; Node has no migration concept at all, and an existing SQLite workspace would simply lack the
new table with no path to gain it.

Nothing has needed this yet: the eight tables have been enough since 0.2.0. The costing port is the
first change that adds a table, which is why the question surfaces there and why it is the *actual*
blocker — the port, the adapters and the table itself are mechanical work.

**Three answers are defensible and they are not equivalent:**

1. **Versioned migrations in both languages.** Honest and conventional, and a subsystem of its own —
   Node would need a migration runner it does not have.
2. **Idempotent schema install.** `create` becomes "ensure", guarded per table, run on open rather
   than on init. Cheap, covers additive changes, and covers nothing else — a column that changes
   type still has no path.
3. **Frozen for 0.x.** Say plainly that a schema change means recreating the workspace, and hold the
   line until 1.0. Defensible for a 0.x library and the only one of the three that costs nothing —
   but it means the costing port waits.

Left open deliberately. Whichever is chosen becomes a promise to every existing installation, and
that is not a mechanical decision.

**Decided 2026-08-24: option 2, the idempotent install** — `create` became "ensure", guarded per
table, and both languages now create only what is absent. The costing-run table went in on the same
day and reached an existing workspace without recreating it, which is the whole test of the choice.

The limit is kept explicit rather than papered over, in the code comment and in the manual: it
covers **additive** changes — a new table, and by hand a new nullable column — and nothing else. A
column that changes its type or a table that has to be rewritten still needs a real migration, which
neither language has, and a change of that kind still means recreating the workspace. Saying that
plainly is better than a runner that only looks like one; if a non-additive change ever becomes
necessary, option 1 is still open and this note is where the reasoning starts.

**IMPL-028 came with it:** the cross-test kept comparing against stale artifacts. `cross-export`
wrote into `.cross-dbs/` without clearing it, so a fixture that stopped being exported — retired
into `superseded.json`, or renamed — left its database and its oracle behind, and the read side
compares whatever it finds. After the format moved to 0.7 the three retired fixtures' old oracles
failed the cross test forever, describing a run that no longer happens. The export now starts from
an empty directory.

## SPEC-015: tenant configuration has no owner — five operations audit a change that does not survive the process

**Found 2026-08-24, while tracing what an embedding has to store when it opens a tenant.**
The embedding app reported the same thing from the outside as its F-22 (three of the five);
this entry is the view from inside, and it is worse than the report, because **summae's own CLI
is affected and ships that way.**

### The asymmetry, in one table

A tenant's chart of accounts is seeded from the pack at creation, stored in `summae_accounts`,
changed by `createAccount`/`lockAccount`/`unlockAccount`, and read back by the `accounts`
projection. Four properties, and nobody ever questioned that it should have them — a chart the
tenant may adapt is tenant state, not pack data, the moment it is adapted.

Every other piece of tenant configuration has one of the four at most:

| tenant state | seeded from the pack | persisted | changed by an operation | readable |
|---|---|---|---|---|
| chart of accounts | ✅ | ✅ `summae_accounts` | ✅ `createAccount`, `lockAccount`, … | ✅ `accounts` |
| tax profile | ✅ `profile.defaults` | ❌ | ✅ `setTaxProfile` — audits `taxProfile/changed` | ❌ |
| dimension types + values | — (tenant's own) | ❌ | ✅ `defineDimensionType` / `defineDimensionValue` — audit `dimensionType/created`, `dimensionValue/created` | ❌ |
| allocation scheme + rates | — | ❌ | ✅ `setAllocationScheme` — audits `allocationScheme/changed` | ❌ |
| mappings | ✅ `ruleModules.mappings` | ❌ | ✅ `importMapping` — audits `mapping/imported` | ❌ |

All four unpersisted ones are constructor arguments of `Tenant` (`DatabaseTenantOptions`:
`dimensions`, `taxCodes`, `taxProfile`, `mappings`; the allocation scheme is not even that — it is
a private field of `CostingService` with no way in except the operation). They are rebuilt from
whatever the caller passes on every open, and the operation that changes them changes a live object
and nothing else.

### Why this is not merely inconvenient

**Each of the five writes a durable audit record about a change that does not survive the process.**
The record is not wrong about the call — the call happened — but it is the only trace left of an
effect that no longer exists, and the trail is the one place in a bookkeeping system that must
never overstate. `taxProfile/changed` on 12 March, followed by a restart, followed by books that
tax exactly as they did on 11 March, with nothing anywhere saying so.

### It already bit us once, and we patched one fifth of it

`importMapping` reported `imported: true` and behaved on the next invocation as though nothing had
been imported. The fix was app-side, in the CLI, and the comment records it plainly
(`packages/cli/src/cli.ts`, PHP `Command/OpCommand.php`):

```
// The ledger persists itself through the database adapter; a mapping does not — it lives
// in a registry rebuilt from summae.json on every call, so the import has to be written
// back or it is forgotten the moment this process ends (R-4).
if (operation === 'importMapping' && isRecord(payload.mapping)) {
  workspace.rememberMapping(payload.mapping);
}
```

That `if` is the **only** write-back that exists, in either language. The other four operations run
through the same `op` command, return a success payload, write their audit record, and are
forgotten:

```
summae op defineDimensionType --input '{"code":"costcentre"}'          → {"code":"costcentre"}  exit 0
summae op post --input '{... "dimensions":[{"type":"costcentre",...}]}' → E_DIMENSION_INVALID    exit 4x
```

So the CLI — a package we publish — reports success for operations that do nothing. It is not a
gap in an embedding's understanding; the reference embedding has it too.

### Why no test sees it

Structurally invisible to the suite we have:

- **Fixtures build a tenant in one process.** In-memory configuration and persisted configuration
  are indistinguishable there by construction, so no fixture can ever fail on this — not a coverage
  gap, a category one.
- **The CLI scenarios, which run across processes and could see it, never touch these operations.**
  `defineDimension` appears nowhere in `testing/scenarios/`; it exists only in fixtures and in the
  schemata. `setAllocationScheme` and `setTaxProfile` are equally absent.

That is a gate gap in its own right: the walkthroughs are the only place where "does this survive
the process" is even askable, and the operations most in need of the question are the ones they
skip.

### Three answers are defensible, and they are not equivalent

1. **Persist it — one `summae_tenant_config` table**, keyed by tenant, holding the four (profile,
   dimension types + values, allocation scheme + rates, imported mappings) as JSON, seeded from the
   pack at creation the way the chart already is. This is the answer the chart of accounts already
   gives for the same question, and **SPEC-014's decision of 2026-08-24 is what makes it cheap**:
   adding a table now reaches an existing workspace, which is exactly how `summae_costing_runs` got
   there. It also settles two neighbouring reports — the profile becomes readable (the app's F-16),
   and a `tenantId` that belongs to no books becomes distinguishable from a new one (its F-21),
   because a tenant with configuration is a tenant that exists.
2. **Declare it the embedding's** — the registry, the profile, the scheme and the mappings are the
   caller's to keep, documented as such, **and the five operations stop auditing**, because an
   operation effective for the lifetime of one object has nothing durable to record. Costs no
   schema change; costs the CLI its `defineDimensionType` and the app a documented seam.
3. **Refuse them on a persisted tenant** — the operations exist for the in-memory path and answer
   `E_UNSUPPORTED` behind DB ports. Honest, and it deletes shipped capability.

Not (3): `defineDimensionType` is how a tenant gets a cost centre, and cost accounting without cost
centres is not a capability. Between (1) and (2), (1) is the one that matches what the chart of
accounts has done since 0.2.0, and (2) requires arguing why the chart is different — which it is
not.

**Resolved 2026-08-24: answer (1), the configuration is persisted.** A `summae_tenants` table now
holds the tenant itself — id, name, base currency, pack provenance, and the four configurations as
one JSON document. The port is `TenantRecordRepository` (in-memory + knex + laravel), the writer is
`TenantConfigStore`, and each of the five operations calls it *after* it has succeeded, so a
rejected operation stores nothing.

**The stored record wins; what a caller passes is a seed.** It is written on the first open of a
tenant with no row and ignored afterwards. The alternative — arguments win when given — is the
state this finding came out of: two claims on the truth that drift the first time an operation
changes one. The rule lives in the core (`openTenantConfiguration`), not in the adapters, so the
two languages cannot answer it differently. It also dissolves the trap the embedding app reported:
passing your cost centres in *and* declaring them was the only way to make cost accounting work,
and it answered `E_DIMENSION_INVALID` for a code you were declaring for the first time. Now either
one works.

The pack stays the embedding's, passed on every open and never stored: it is versioned product data
the caller pins, and a copy beside the books would make two answers out of "which rules is this
tenant on". `packIdentity` is recorded as provenance, not as a substitute.

**Two things came out of the fix that were not in the finding:**

- **The replay had to be deferred.** Applying a stored allocation scheme while building the tenant
  runs *before* the caller sets the pack, so a scheme naming production-cost treatments made opening
  the books fail on something that was valid when it was set. `restoreAllocationScheme` now hands
  the scheme back and `setAllocationScheme`/`run` — the only entry points that read one — apply it
  on first use. Reading a journal needs no allocation scheme, and a stored scheme the *current* pack
  no longer accepts should fail when somebody runs a costing, with that operation's own error. The
  cross-test found this, not a unit test.
- **`listTenants`** answers which tenants a store holds (`packages/knex/src/repositories.ts`,
  `DatabaseTenantRecordRepository::listTenants`). It is deliberately **not** a projection — a
  projection is computed *on* a tenant and this question has none to run on — which is also the
  answer to the app's F-21: the register is the adapter's, not the dispatcher's, and an unknown
  `tenantId` is now distinguishable from a new one because a tenant with no row does not exist.

**The gate gap is closed with it.** `testing/scenarios/regression/tenant-configuration.json` drives
the five operations through the CLI, each step its own invocation, so every configuration change has
to survive a rebuild of the tenant to pass. Both languages read that file. Plus the adapter suites
(`packages/knex/test/adapter.test.ts`, `packages/laravel/tests/TenantConfigPersistenceTest.php`).
Verified by removing the fix: **seven scenario/adapter tests go red**, including two CLI tests that
were only green because of the hand-rolled `rememberMapping` write-back — which is now gone from
both CLIs.

`testing/scenarios/README.md` records the general lesson: a scenario is the only place in this test
landscape where "does this survive the process" can be asked at all.

## SPEC-017: the parameter contract reaches keys, not element structures

**Found 2026-08-24, while carrying the net lines' dimensions through the tax expansion (F-CORE-006).**

`api-parameters.json` declares every accepted parameter and every accepted operation input with its
type, and the dispatcher validates against it *before* routing — an undeclared key is
`E_INPUT_INVALID`, a declared one of the wrong type is rejected, an absent one keeps its default.
That closed the accepted-and-ignored class for inputs, and it is what F-9 was about.

It reaches one level deep. `netLines` is declared as `array`, and nothing looks inside the
elements. So:

```json
{ "netLines": [ { "account": "6040", "money": {…}, "dimension": [ … ] } ] }
```

is accepted, books correctly, and drops the cost centre — because the key is `dimension` and the
engine reads `dimensions`. No error, no warning, and the posting looks right. That is exactly the
shape of the defect the same fixture just closed, one level further in: the element was dropped
because nothing carried it, and now it is dropped because nothing checks the key that carries it.

The same gap exists on every array-typed input: `lines` on `post`, `allocations` on `settle`,
`steps`/`rates` on `setAllocationScheme`, `weights` on `allocate`, `smallBusiness` segments on
`setTaxProfile`. Some of those have their own parsing that fails loudly on a missing *required*
field — `post` refuses a line without an account — but an *optional* one is silently absent in all
of them.

**Why it is not closed here:** element schemas are a different mechanism from a key table, not a
bigger one. Three shapes are defensible and they are not equivalent:

1. **Element declarations in `api-parameters.json`** — `netLines: { type: 'array', element: {…} }`,
   validated by the same dispatcher pass. Consistent with what exists, and it means writing the
   element shape of every array input in the file plus a second traversal in both languages.
2. **JSON Schema for inputs**, reusing `format.schema.json`'s machinery. Expressive, and it puts a
   second validation language in front of the dispatcher.
3. **Per-parser strictness** — each parser rejects keys it does not know, where it already reads the
   element. Cheapest, and the rule then lives in a dozen places instead of one file, which is
   precisely what the parameter contract was created to end.

(1) is the obvious continuation, and it should be a decision rather than a side effect of the next
fix that trips over it. Nothing here is a regression: this gap has existed since the contract was
written, and the contract made the *outer* layer strict enough that the inner one now stands out.

**What raises the priority:** the fix that found this made `dimensions` meaningful on a net line, so
there is now an optional element key whose silent absence changes what cost accounting reports.
Before it, the elements carried nothing optional worth losing.

### RESOLVED 2026-08-25 — the contract reaches into structures, recursively

`element` (for arrays) and `fields` (for objects) on any declaration, checked by the same two rules
the outer level always had: an undeclared key is a caller mistake, a declared key of the wrong type
is rejected rather than coerced. Requiredness deliberately stays with the operation, whose own error
says more. Errors name the full path — `post: unknown input "lines[0].dimension"`.

**Recursive rather than "one level deeper", on purpose.** A fixed depth is a number somebody
re-decides in a year; a recursion that stops where the *declaration* stops is a choice the contract's
author makes visibly each time. `opaque` is the same statement with a reason attached, for a
structure another schema owns (`importMapping.mapping` belongs to `format.schema.json`;
`createPartner.address` is free-form master data the engine stores whole).

**The guard is the durable half.** `testEveryStructuralInputDeclaresWhatIsInsideIt` /
`declares what is inside every structural input` refuses an `array` or `object` declaration that has
neither an inner shape nor an `opaque` reason. Declaring today's inputs fixes today; without the
guard the next array would be structural and silent again, which is exactly how this arose.

**It found three things on its first run, all of them silent until then:**

1. `post`/`postVoucher` `lines[].openItem` — passed by fixtures since v0.2, read by nobody. An open
   item is derived from the account's subtype and the line's side, which cannot disagree with the
   account. Declared `acceptedWithoutEffect` with the reason, recorded as **IMPL-029**.
2. `testing/scenarios/regression/tenant-configuration.json` declared an overhead rate with
   `accounts` at rate level, where the parser reads `base.accounts`. The rate had no base and the
   scenario asserted only the cost-centre name, so it passed. Corrected.
3. `packages/knex/test/adapter.test.ts` and its PHP twin passed `receivers[].costCenter` where the
   parser reads `code`, so the receiver was dropped and both tests stayed green. Corrected.

Fixture `core/input-structure-contract` pins the refusals and the opaque boundary in both languages.
Nothing in the suite had to bend: 168 fixtures green against both subjects.

**This is a tightening**, and it is the same call the outer level was (F-9): a caller passing an
undeclared key inside a structure now gets `E_INPUT_INVALID` where it used to be ignored. That is
the point — the ignoring was the defect.

## SPEC-019: the documentation gate reaches names, not meanings — and an embedding lost a release to it

**Found 2026-08-25, while closing the embedding app's F-27.** The application reported that a
consideration reduction could not reach the VAT return with the right sign, tried three routes, and
concluded the case was unbuildable — so a legal obligation went unimplemented and a screen shipped
without a discount field.

**The third route works, and has since v0.4.** A plain `post` whose tax line carries a `taxTag` with
a negative `baseMoney` does exactly what was wanted; the fixture `core/settlement-discount` has
pinned it — including the corrected reporting key — the whole time.

They could not know. `taxTag` appeared in the manual as one item in a list:

```
Posting line (`lines[]`): `account` (…), `side` (…), `money` (…), `dimensions` (…), `taxTag` (object, no).
```

No shape. No word that `vatReturn` counts **only** tagged lines, which is the fact that makes the
field load-bearing. Nothing about the sign convention. A field that is named and never explained is
worse than one that is absent: absent, they would have asked.

**Why this is a gate gap and not a typo.** This repository guards contract surfaces on purpose, and
the documentation is one of them: `HandbookCoversTheApiTest` / `handbook-covers-the-api.test.ts`
fails when a published operation or projection has no section, and the walkthrough scenarios fail
when documented *behaviour* stops being true. Both work on **names**. Neither can see a documented
field that means nothing, and that is the shape this defect had. The published API surface is
guarded down to the operation; the published *vocabulary* is not guarded at all.

**Chosen behaviour:** `taxTag` is documented (shape, the only-tagged-lines rule, the sign
convention), and `postVoucher`/`expandTax` gained `reduction: true` so the raw field is not the only
road (F-TAX-014). Both halves, because the second one does not repair the first: the next
under-explained field will be somewhere else.

**Proposal — and the honest part is that none of the three is obviously right:**
1. Declare the documented shape of the fields the contract already knows about. `api-parameters.json`
   reaches keys, not element structures — which is SPEC-017 — so the two findings share a fix: an
   element declaration would be both checkable *and* documentable from one source.
2. A weaker guard with a good ratio: every field named in a parameter table must appear again in
   prose in that section. Mechanical, catches exactly this case, says nothing about quality.
3. Accept it as a review obligation and write it down. Cheapest, and the option this project usually
   argues against — a contract surface without a guard is what the gate-gap list is for.

Left open deliberately: option 1 depends on a decision SPEC-017 already owns, and pre-empting it
here would be the wrong order.

### RESOLVED 2026-08-25 — the gate reaches the vocabulary, and SPEC-017 made option 1 possible

Option 1, which was blocked on SPEC-017 and stopped being blocked the moment it closed: the contract
now declares every input key at every depth, so the manual can be held against **the same list the
dispatcher validates**. `HandbookCoversTheApiTest` / `handbook-covers-the-api.test.ts` fails when a
declared key is not named in the manual section of the operation that accepts it.

Still deliberately weak, and in the same way the name check always was: appearing in the prose is
not the same as being explained well. It catches the shape this defect had — declared, published,
meaningless — and leaves quality to review, which is the honest division. A guard that judged prose
is a guard nobody keeps green.

**One exemption, as a list rather than a rule:** `actor` is documented once in "Conventions for this
whole section" instead of in each of the thirty sections that accept it. Adding to that list is a
decision somebody makes visibly.

**It found 46 undocumented keys**, which is the answer to whether `taxTag` was one accident: the
whole `voucher` vocabulary on `postVoucher` (`due`, `economicYear`, `issuer`, `kind`, `recurring`,
`serviceDate`, `servicePeriod`), every element key on `correct.lines`, the row fields of
`importChartOfAccounts`, `acquireAsset`'s `specialDepreciation` and `totalUnits`, `setTaxProfile`'s
`reason`, and the entire rate / production-cost vocabulary of `setAllocationScheme`. All of them are
written now — and the rate one mattered: it is where `base.accounts` lives, the key a regression
scenario had been getting wrong at rate level for exactly as long as nothing documented it.

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

### RESOLVED 2026-08-25 — the criteria travel to the store, and the proposal in this entry was wrong

**Correction first, because the entry above argued for the expensive answer.** It proposed promoting
the four fields to columns and said that needs a column-adding installer, which SPEC-014 had left
out. Re-reading it turned up two things: a new column is easy but **filling it for rows that already
exist is a data migration**, and neither language has a migration runner — an unfilled column makes
the filter miss exactly the history an audit is about, which is worse than no filter. And the
alternative the entry never considered works: **read the JSON in SQL.** SQLite has `json_extract`,
Postgres has `->>`; no schema change, no migration, old rows included. The parity objection the entry
raised does not survive contact either — different SQL is not different rows, and `seq` still decides
the order.

`AuditTrail::find(criteria)` on the port: `objectType`, `objectId`, `objectIds` (the set a page of
postings needs), `actor`, `action`, `from`/`to`, `offset`/`limit`, returning the page and the count
**before** paging. `auditLog` passes its parameters straight through, and `EntryAuthors` asks for the
ids on the page — a journal view of forty postings now reads forty records instead of ten years of
history. That was the half the first fix missed: it moved the embedding\'s walk into the library
without making it smaller.

**Two implementations of one rule, held together by a test.** SQL in the database adapters,
`AuditFilter` / `applyAuditCriteria` in the in-memory ones. `AuditQueryEquivalenceTest` and its Node
twin drive **every declared filter, alone and combined, plus the paging edges** through both and
compare — the shared-data check the quality policy asks for, applied to a port instead of a format.
The empty id set is in there on purpose: "these entries" with none of them is not "all of them", and
a naive `IN ()` gets that exactly backwards.

**Three things the build taught while closing it**, all of them recorded where they bite:
- SQLite refuses an `OFFSET` without a `LIMIT`. Paging is pushed down only when there is a limit;
  without one the caller has asked for every remaining row anyway, so the offset is applied after
  reading.
- PHPStan requires raw SQL to be a `literal-string`. Every predicate is a whole literal per field
  rather than assembled — the right habit exactly where caller-supplied ids meet a query — and the
  id set is a group of ORs rather than an `IN` list, whose placeholders cannot be literal.
- The dialect branch lives in `AuditSql` as pure strings, because the adapter suite runs on SQLite
  and the Postgres path would otherwise be exercised only by the conformance run against a real
  server — proof that it works, invisible to the coverage floor. Both branches are pinned as
  strings; behaviour stays the job of `--subject=database`, which is green.

**What is genuinely left:** an index. Extraction reads every row of the table even when it returns
few, so the *bandwidth* is bounded and the *scan* is not. Fixing that means a generated column or an
expression index, which is the schema question this entry started with — now separable, because
correctness no longer waits on it.

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

### RESOLVED 2026-08-25 — the pack declares its filing windows, and the substrate keeps a default

`packPolicy.vatPeriods` names the windows a jurisdiction files in, and it **replaces** the
substrate's list rather than extending it: a pack whose jurisdiction has no quarterly filing does
not get quarterly quietly available. Ireland can say bi-monthly without the core learning the word.

**The substrate's three stay, as a default rather than a definition** — and that distinction is the
whole fix, not a compromise. What made this a finding was the core *deciding* which windows exist;
what it does now is answer for a pack that says nothing. Keeping it is also what makes the change
additive: every existing pack, every inline bundle and every tenant already in the field behaves
exactly as before, so no shipped pack had to change for this to close. (Declaring the three
explicitly in `de`/`us`/`default` is a tidy-up in the pack source, not a prerequisite — and the pack
source is authored outside this repository.)

The fallback for an absent `vatPeriod` follows whoever owns the list: the substrate default keeps
its documented `quarterly`, a declaring pack gets its own first window. A pack that does not file
quarterly should not have quarterly as its default either.

**Rehydration stopped re-judging.** `TaxProfile::restore` rebuilds a stored profile without
validating it, and the database factories use it. Validation belongs at the boundary; re-checking on
the way *out* of our own store would mean a tenant whose pack later drops a window can no longer be
opened — a rule change reaching backwards into books kept correctly under the old one, which is the
opposite of what an append-only ledger promises. That hazard was invisible until the list became
changeable, which is a good argument for the finding having been written down rather than fixed in
passing.

Proven in two halves, because one test cannot see both: fixture
`pack/conformance-xx/xx-7-pack-declares-filing-periods` builds a fictional jurisdiction that files
bi-monthly and shows the window reaching `systemDescription` — red without the change. The
*replacement* half needs a pack that **excludes** something, which no fixture can express, so
`VatPeriodsFromPackTest` / `vat-periods-from-pack.test.ts` pin it in both languages along with the
fallback and the untouched no-pack behaviour.

**What the entry got right and kept:** `taxationMethod` is still not this finding. Accrual and cash
are the two ways this engine can time a liability and it implements both — a jurisdiction picks from
that set, it does not extend it. Two fields one line apart, on opposite sides of the boundary.

---

## Findings first written on the Node side

Everything from here arrived through the second implementation. Most of it concerns **both** —
`IMPL-009`, `IMPL-010`, `IMPL-011` and `IMPL-013` were fixed in PHP as well — which is the other
half of why one register beats two: a finding filed under a language is not a finding that belongs
to it.

## IMPL-001 — Pack draft fixture `tenant-from-de-complete`: `defaults` missing in the manifest — ✅ RESOLVED

**Finding (2026-06-21, Gate-1 pack conformance).** The draft fixture
`testing/testsuite/fixtures/pack/de-composed-equals-de/tenant-from-de-complete-posts-identically.json`
expects `createTenant.result.taxationMethod = "cash"`, but its manifest `de-mini-regression`
carried **no** `defaults` object — neither the manifest nor the modules encode
`taxationMethod` anywhere. By design the resolver derives `defaults`
(`module-manifest-resolver.md` § 2/§ 4.1) exclusively from the manifest; without
`defaults` the engine default `accrual` ≠ the expected `cash` kicks in.

**Assessment.** Authoring gap in an **explicitly non-normative draft fixture**
("DRAFT, not normative; only proceeds after human approval"). The fixture mirrors
`core/create-tenant-profile`, whose profile carries
`defaults: {taxationMethod: cash, smallBusiness: false, vatPeriod: quarterly}` —
the manifest had simply left out this adoption. The resolver is correct.

**Resolution.** Since the correct value is unambiguous (mirrored `create-tenant-profile`
+ design § 2) and it is a pre-freeze draft, the draft was completed rather than
bent: `defaults: {taxationMethod: "cash", smallBusiness: false, vatPeriod: "quarterly"}`
added — in both manifest copies (`tenant-from-…` **and** `resolve-de-complete-…`,
pinning consistency `de-mini-regression@2026.1`), at the source (internal source) and the mirror.
Also applies to the PHP side (shared fixture).

## IMPL-002 — `format.schema.json` `mappingPosition` omits `includeNonCash` — ✅ schema extended

> **Resolved (2026-06-23):** `$defs/mappingPosition` now declares
> `includeNonCash` (`{ "type": "boolean" }`) — the schema matches the engine.
> **Still open (separate question):** pack-library JSON is not validated against
> the schema at all (only journalExport streams + manifest are). Whether to add
> schema validation for the pack-library (the third-party extension surface) is its
> own decision — this drift slipping through unnoticed is the argument for it.

**Finding (2026-06-23, us-pack build).** The cash-basis projection reads a
position-level flag `includeNonCash` off the mapping leaf
(`policies/projection/mapping/mapping.ts:82` → `cash-basis.ts` R7: non-cash
categories like depreciation count without a cash flow). The us-pack module 5
(`us-schedule-c-2026`, kind `cash-basis-categories`) sets `includeNonCash: true`
on its depreciation line (L13) per the module spec. But the normative
`testing/testsuite/schema/format.schema.json` `$defs/mappingPosition` does **not** declare
`includeNonCash` and carries `additionalProperties: false` — so by the schema the
field is illegal on a mapping position.

**Assessment.** A schema-vs-engine gap, not currently breaking: pack-library JSON
is loaded content-based (`JSON.parse` → resolver), never validated against
`format.schema.json`. `validate.py` explicitly skips module/pack files; the schema
test validates only the journalExport streams + manifest. So `us-schedule-c.json`
resolves and runs green in both languages. The gap would only bite if schema
validation is ever extended to pack modules. The de-pack never shipped an
EÜR/cash-basis mapping, so this is the first shipped `cash-basis-categories` module
and the first time the gap surfaces.

**Resolution.** Shipped `us-schedule-c-2026` with `includeNonCash: true` per the
module spec and the engine that consumes it (do not bend the data to a schema the
loader does not enforce). **Proposal:** extend `$defs/mappingPosition` with
`"includeNonCash": { "type": "boolean" }` (meaningful only for
`cash-basis-categories`) so the normative schema matches the engine before any move
to schema-validate pack modules. Shared schema artifact — applies to PHP too.

## IMPL-003 — `cashBasisReport` hard-codes a German VAT-passthrough treatment — ✅ resolved

> **Resolved (2026-06-24):** the hard-coded German strings are gone from the core.
> Tax accounts now flow through the cash-basis result **only where the pack's mapping
> maps them**, taking the label from the mapping leaf; an unmapped tax account is a
> neutral pass-through. DE: the `de-euer` mapping maps its VAT accounts (E3 "Vereinnahmte
> USt", A6 "Gezahlte Vorsteuer") → German labels from the **pack**. US: `us-schedule-c`
> leaves sales tax unmapped → neutral (now a realistic SALETAX sale in the fixture). A
> regression guard (`SubstrateBoundaryTest` / `no-jurisdiction-text.test.ts`) fails if such
> German label text reappears in the core. (The remaining mechanism-name branching —
> `reverse_charge` etc. — is the separate, documented closed/open matter.)

**Finding (2026-06-24, us-pack conformance audit).** The cash-basis projection
(`policies/projection/cash-basis.ts`) routes tax accounts by subtype with **hard-coded
German labels**: `tax_out` → income `"Vereinnahmte USt"` / expense `"USt-Zahlung an FA"`,
`tax_in` → `"Gezahlte Vorsteuer"`. This is the German EÜR rule (VAT flows through the
profit calculation). For the US, sales tax is a **pure pass-through** (held in trust for the
state, never income). With `2100 Sales Tax Payable` correctly marked `tax_out` (needed by
`vatReturn`, IMPL-… below), a SALETAX cash sale would have its collected tax counted as
income under the German label — wrong for US.

**Assessment.** The tax treatment is **pack/jurisdiction-specific**, but the engine hard-codes
the DE variant. Same family as the journalExport German-output finding. Not fixable from pack
data (it is engine behavior).

**Resolution / workaround.** `us-schedule-c` posts its sample revenue **tax-free** so the
projection's mechanism (mapping labels + `includeNonCash`) is proven without tripping the
DE-centric tax path. **Proposal:** make the cash-basis tax treatment pack-appropriate — e.g.
neutral pass-through unless the pack's cash-basis mapping explicitly maps the tax accounts
(would also drop the hard-coded German strings). Behavior change with DE-fixture ripple →
own job, human decision. Applies to PHP too.

## IMPL-004 — `EXEMPT` (rate-0 standard) cannot be posted: 0.00 tax line rejected — ✅ RESOLVED

> **Resolved in 0.5.0 (2026-06-24).** The proposal below was built: `exempt` is now a
> registered tax mechanism (`tax-mechanisms.ts`) that tags the base and emits **no** tax
> line, and the us-pack `EXEMPT` code selects it. Exempt sales post cleanly and appear in
> the return. Pinned by the conformance fixtures and by the `us` walkthrough scenario
> (`testing/scenarios/walkthrough/us.json`). Original finding kept for the record:

**Finding (2026-06-24).** The us-pack `EXEMPT` code (mechanism `standard`, rate `0.00`)
emits a 0.00 tax line on the tax account. `expandTax` returns it fine (proven by
`us-exempt-sale`), but **`postVoucher`/`post` reject it** with `E_ENTRY_INVALID_AMOUNT`
(a zero-amount entry line). So exempt sales **cannot be recorded in the journal** with the
EXEMPT code today — only previewed via `expandTax`. Consequently they also cannot appear in
the sales-tax return (`us-sales-tax-return` covers the taxable line only).

**Assessment.** Confirms open-decision E has teeth — the 0.00 line is not merely cosmetic,
it blocks real bookkeeping of exempt sales. The de-pack avoids this for the analogous
intra-community supply via a dedicated `intra_community_supply` mechanism (base tag only, no
0.00 line). **Proposal:** add an `exempt` mechanism (tag the base, emit no tax line), then
exempt sales post cleanly and show up in the return. Engine addition → own job. Applies to PHP too.

## IMPL-005 — Cash-basis VAT: the reversal of an *unsettled* open item counts immediately — ✅ FIXED

> **Fixed 2026-08-15 (both languages, `vat-return.ts` / `VatReturnProjection.php`).** The
> direct-contribution loop now also skips an entry that *reverses* an entry carrying open
> items. Its premise is "no open item ⇒ the money moved at posting time"; a reversal has no
> open item of its own but is not a cash movement either, so its tax follows the reversed
> entry's settlements instead. Reversals of genuinely cash-effective entries (target without
> open items) still count directly, at their own posting date — unchanged.
>
> Chosen because it is the part **both** candidate semantics agree on: an invoice that was
> never paid and then reversed contributes nothing either way. Pinned by the `de` walkthrough
> scenario (`testing/scenarios/walkthrough/de.json`, VAT-return step: key `66` must be
> absent); verified to fail without the fix. All 86 fixtures stay green in both languages,
> SF-15 cross-test 45/45 both directions — nothing existing pinned this case.
>
> **Still open (narrower than the original finding):** an invoice that *was* settled and is
> then reversed. The fix leaves the tax declared until a refund is posted ("follow the
> money"); the alternative corrects it at the reversal's own date (the §17 / SPEC-011 reading
> used on the accrual path). No fixture pins either. Needs a normative fixture in the
> knowledge base before the behaviour is relied upon. Original finding:

**Finding (2026-08-14, CLI walkthrough for the handbook).** With
`taxProfile.taxationMethod = "cash"` the VAT return has two paths
(`vat-return.ts:60-80`, PHP `VatReturnProjection.php:72-112`): entries **with**
an open item contribute per settlement, entries **without** one contribute
directly at their posting date (`if (this.openItems.byOriginEntry(entry.id).length > 0) continue;`).

A reversal creates no open item of its own. So reversing an unpaid incoming
invoice puts the *full* negative input tax into the return, while the positive
original still waits for a payment that will now never come:

```
postVoucher ER-001  VSt19  net 200.00  →  open item payable 238.00, unsettled → contributes 0
reverse     ER-001                     →  no open item                        → contributes −200.00 / −38.00
report vatReturn {"year":2026,"quarter":1}
  → keys.66 = { base: "-200.00", tax: "-38.00" }
```

The tenant claims a 38.00 input-tax refund for an invoice that was never paid
and then cancelled. Netting to zero would be the expected result.

**Assessment.** Not a language defect — **PHP and Node are byte-identical here**
(same two-path structure, same `byOriginEntry` skip), so cross-language
equivalence holds; this is a **model** question. The accrual path has an explicit
rule for exactly this case (`SPEC-011`: "a tax correction counts by its own posting
date"); the cash path has no counterpart. Plausible fix directions: (a) a
reversal inherits the settlement state of the entry it reverses, (b) reversing
an entry that carries an unsettled open item also cancels that open item
proportionally, (c) the second loop skips entries whose `reverses` target has an
open item. Which one is correct is a **spec decision**, not an implementation
detail — no fixture pins the case today.

**Resolution.** Documented, not changed. Needs a normative fixture in the
knowledge base before any implementation moves. Applies to PHP too.

## IMPL-006 — `cashBasisReport` without `year` raises an uncaught `InvalidValue`

**Finding (2026-08-14, CLI walkthrough for the handbook).** `CashBasisProjection.compute`
defaults a missing/mistyped `year` to `0` (`cash-basis.ts:43`, PHP `CashBasisProjection.php:63`)
and then builds `CalendarDate.of("0000-01-01")` in `assertCalendarYearFiscalYears`.
`CalendarDate` rejects year 0, so the caller gets a raw `InvalidValue` — **not** a
`DomainError`. Via the CLI that means a stack trace on stderr instead of the
documented `{"error", "message", "details"}` line, and exit code 1 instead of a
catalogued code:

```
summae report cashBasisReport --params '{"fiscalYear":2026}'   # note: wrong param name
  → InvalidValue: Not a valid calendar date: "0000-01-01"      (stack trace, non-JSON stdout)
```

The trigger is realistic: every other projection except `vatReturn` takes
`fiscalYear`, so passing `fiscalYear` here is the natural mistake.

**Assessment.** Contract violation of the CLI's own output guarantee ("stdout is
always one line of JSON, errors carry an `E_*` code"), and the failure mode is
worst for automated callers — an agent parsing stdout gets garbage rather than a
branchable error. The error catalogue has no code for "required projection
parameter missing"; the closest existing behaviour is that projections silently
return empty results for a wrong `fiscalYear`, which is its own problem. Same
code path in both languages.

**Resolution.** Documented, not changed. Needs a decision on how missing
projection parameters report at all (new `E_*` code vs. reusing an existing one)
before either language moves. Applies to PHP too.

## IMPL-007 — A missing mapping reports `E_MAPPING_OVERLAP`

**Finding (2026-08-14, walkthrough scenarios).** `incomeStatement` and `balanceSheet`
require a `mapping` parameter. When it is absent or names a mapping the tenant has
not loaded, both raise `E_MAPPING_OVERLAP` — a code whose name says the opposite of
what happened (`income-statement.ts:30`, `balance-sheet.ts:32`; PHP
`IncomeStatementProjection.php:46`, `BalanceSheetProjection.php:49`):

```
summae report incomeStatement --params '{"fiscalYear":2026}'      # default pack, no mapping module
  → {"error":"E_MAPPING_OVERLAP","message":"Mapping \"\" is not loaded"}
```

The empty `""` in the message shows the second half: a *missing* parameter is not
distinguished from an *unknown* one.

**Assessment.** The error catalogue has no code for "required projection parameter
missing" and none for "mapping unknown"; `E_MAPPING_OVERLAP` is documented as the
overlap error of `importMapping`. Reusing it here makes an operator debug the wrong
thing — and it is the *only* error a tax-free configuration (default pack) hits on a
normal report. Identical in both languages, so no parity issue.

**Resolution.** Documented, not changed; the walkthrough scenario `default.json`
pins the current behaviour so a future fix is a visible, deliberate change. A new
code (`E_MAPPING_UNKNOWN`) would be an append to the error catalogue and therefore
to the exit-code table — catalogue changes are append-only, so this needs a spec
decision first. Applies to PHP too.

## IMPL-008 — A reversal leaves the reversed entry's open items standing

**Finding (2026-08-15, while fixing IMPL-005).** `reverse` posts a full counter-entry but does
**not** touch the open items the reversed entry created. The two views of the same fact then
contradict each other:

```
postVoucher ER-1  VSt19  net 200.00, counterAccount 3000 (ap)  → open item payable 238.00
reverse     ER-1
report trialBalance → account 3000: balance "0.00"          (the liability is gone)
report openItems    → payable 238.00, remaining 238.00, status "open"   (it is not)
```

The ledger says the debt does not exist; the open-item list still offers it for settlement. It
can be settled against a payment that has no economic basis, and it inflates any
receivables/payables ageing built on `openItems`.

**Assessment.** Distinct from [IMPL-005] and *not* its cause — fixing this alone would not have
changed the VAT return, and fixing IMPL-005 does not clear the stale item. Identical in both
languages. The plausible behaviour is that a reversal cancels the open items of the entry it
reverses while **keeping their settlement history** (any settlements already made must keep
contributing to past VAT returns — silently dropping them would rewrite filed periods).

Whether a cancelled open item disappears from `openItems` or shows a distinct terminal status
(`cancelled` alongside `settled`) is a **data-format decision**: `status` is part of the
serialized shape, so a new value is an append to the format. Needs a spec decision plus a
normative fixture.

**Resolution.** Documented, not changed.

## IMPL-009 — `CalendarDate` accepted years 0000–0099 in PHP and rejected them in Node — ✅ FIXED

**Finding (2026-08-15, probing the CLI error boundary).** The same string was valid in one
engine and invalid in the other, in the **substrate** — the layer that is meant to be frozen
and is bound by the top quality policy:

| input | PHP | Node (before) |
|---|---|---|
| `0000-01-01` | accepted | `InvalidValue` |
| `0001-01-01` | accepted | `InvalidValue` |
| `0099-12-31` | accepted | `InvalidValue` |

**Cause.** Node validated by round-tripping through `new Date(Date.UTC(year, month-1, day))`.
`Date.UTC` maps years 0–99 onto **1900+year** (a JavaScript legacy rule), so `0000-01-01`
became `1900-01-01`, the round-trip failed, and the date was rejected. The boundary was not a
design decision — it was a host quirk leaking into a value object. `lastDayOfMonth()` and
`firstDayOfNextMonth()` used the same helper and were wrong in the same band.

How it surfaced: `cashBasisReport` defaults a missing `year` to `0` and builds `0000-01-01`
(IMPL-006), which crashed in Node and returned an empty report in PHP. The crash was hiding a
divergence.

**Resolution.** Node's `CalendarDate` no longer uses the host `Date` at all — validation and
month arithmetic are done with an explicit days-per-month table and the Gregorian leap rule
(`calendar-date.ts`). This **widens** Node to match PHP (nothing that was valid became
invalid), and it removes the whole class of `Date`-quirk divergences from the substrate.

Verified identical in both languages across the accepted/rejected tables and the month
arithmetic, including `0050-02-29` (year 50 is not a leap year), `0100-02-29`, `1900-02-29`
and the December rollover. Pinned by `calendar-date.test.ts` / `CalendarDateTest.php`, which
carry the **same tables** — 34 cases each. All 86 fixtures green in both languages against
both subjects, SF-15 cross-test 45/45 both directions.

**Note.** This does **not** resolve IMPL-006: with the divergence gone, both engines now return
an empty cash-basis report for a missing `year` instead of one crashing. Consistent, still a
silent wrong answer — the missing-parameter question stands.

## IMPL-010 — `Money.of` accepted amounts the data format forbids — ✅ FIXED

**Finding (2026-08-15, probing malformed write input).** `Money.of` validated by handing the
string to the decimal library (`big.js` / `brick/math`) and checking the resulting scale. The
libraries parse considerably more than the **data format** allows
(`format.schema.json` `$defs/money/properties/amount` = `^-?\d+(\.\d{1,4})?$`), so amounts that
can never be exported were accepted into the journal:

| amount | before | data format |
|---|---|---|
| `"1e3"` | booked as `1000.00` | invalid |
| `"1E3"` | booked as `1000.00` | invalid |
| `"1.5e+21"` | **booked as `1500000000000000000000.00`** | invalid |
| `"10."` | booked as `10.00` | invalid |
| `".5"` | booked as `0.50` | invalid |
| `"+10.00"` | **PHP: `10.00` · Node: rejected** | invalid |

Two defects in one. **Silent acceptance:** an app that formats amounts with
`String(number)` emits exponent notation for very large or very small values, and 1.5
sextillion euros went into the journal without complaint. **Cross-language divergence:** a
leading `+` was accepted by PHP and rejected by Node — the same class as [IMPL-009], again in the
substrate.

**Resolution.** `Money.of` now checks the string against the data-format expression *before*
handing it to the decimal library, in both languages, with the expression written out in each.
`Money.fromCalculation` is untouched — it takes a decimal value, not a user string, and is the
only path on which Money rounds.

The schema was the arbiter here, not taste: it already declared the format, and both engines
were simply not enforcing what the exported data promises. Pinned by the same accepted/rejected
tables in `money.test.ts` and `MoneyTest.php`. All 86 fixtures green in both languages against
both subjects, SF-15 cross 45/45 both directions — no fixture used any of the loose forms.

## IMPL-011 — `post` accepted a caller-fabricated `taxTag` straight into the VAT return — ✅ FIXED

**Finding (2026-08-15, adversarial probing of the write path).** `postVoucher` builds tax tags
through the `TaxCodeRegistry`, so a wrong code is `E_TAXCODE_UNKNOWN`. The direct `post` path
took whatever the caller supplied (`ledger.ts:577` / `Ledger.php:706`: `isRecord(rawLine.taxTag)
? rawLine.taxTag : null`) — no registry lookup, no check of `reportingKey`, `appliedVersion` or
`baseMoney`. Stored verbatim, and the VAT return is built **from these tags, never from account
numbers**:

```
op post --input '{… "taxTag":{"code":"MADEUP","reportingKey":"4711","baseMoney":{"amount":"999999.00", …}}}'
  → exit 0
report vatReturn --params '{"year":2026,"quarter":0}'
  → {"keys":{"4711":{"base":"-1.00","tax":"0.00"}, …}}
```

An invented reporting key and an unregistered tax code became line items of a statutory return,
at exit 0. The sharpest hole found in this round.

**Resolution.** A caller-supplied tag whose `code` is a non-empty string must resolve in the
`TaxCodeRegistry`; otherwise `E_TAXCODE_UNKNOWN` (the existing catalogue code — no append). Tags
built internally by the tax expansion come from the registry and pass unchanged; `reverse`
copies `EntryLine` objects and never goes through this path. The registry had to be wired into
the ledger in both languages; PHP needed it in **two** places, because
`DatabaseTenantFactory.php` duplicates the ledger construction that `Tenant.php` also does —
Node has a single construction path. That duplication is worth removing separately.

Pinned by `testing/scenarios/walkthrough/regressions.json` (fabricated code rejected with
exit 32; a tag naming a *registered* code still posts, so the guard checks the registry rather
than forbidding tags).

## IMPL-012 — `balanceSheet` silently ignored `fiscalYear` — ✅ FIXED

**Finding (2026-08-15, probing the read path).** `balance-sheet.ts` read only `asOf` and
`mapping`. `fiscalYear` was accepted and discarded, so **two different years returned
byte-identical balance sheets** over a two-year journal. The handbook cheat sheet lists
`balanceSheet` in the `fiscalYear` row, and — worse — the gated walkthrough scenarios pass
`fiscalYear` to it (`de.json`, `us.json`), so the documentation endorsed a parameter that did
nothing. The gate could not catch it: every scenario had exactly one fiscal year.

**Resolution.** `fiscalYear` now scopes the projection **cumulatively** — everything up to and
including that year, i.e. "as at the end of fiscal year N".

The first attempt mirrored `trialBalance` (income accounts restart each year, G1) and produced
a sheet that **did not balance** in year two: assets 3570.00 against equity+liabilities 2570.00,
a hole exactly the size of the prior year's result. That is correct behaviour for a trial
balance and wrong for a balance sheet, because summae deliberately writes **no closing entries**
(`closeFiscalYear` is a pure status change), so a prior year's result was never carried into
equity. Cumulative scoping keeps assets == liabilities+equity in every year, and for a system
without closing entries the cumulative result *is* the equity delta.

Pinned by `regressions.json`, the first scenario that spans two fiscal years — 2026 → 1190.00,
2027 → 3570.00, both balancing.

## E_INPUT_INVALID — new catalogue code (exit 45)

**Added 2026-08-15.** A supplied parameter or field is **present but not valid input** — a caller
mistake, not an internal failure. Before it, such situations either escaped as an uncaught
`InvalidValue` (a stack trace, and since the CLI error boundary an `E_UNEXPECTED`/exit 1 that an
agent cannot tell apart from a summae bug) or were silently coerced into a plausible default.

Appended to both exit-code tables, so no existing exit code moved. In use at:
`tax-service` (`direction`), `cash-basis` (`year`, unknown `mapping`), `account-sheet`
(`account`, `fiscalYear`), `income-statement` and `balance-sheet` (missing/unknown `mapping`).

**Normative source: done.** The catalogue entry was written in the knowledge base
(`50-spezifikation/fehlerkatalog.md`, section `E_INPUT` with the delimitation rule: where a more
specific code exists it wins — an unknown account stays `E_ACCOUNT_UNKNOWN`, an unknown tax code
stays `E_TAXCODE_UNKNOWN`), and the conformance fixture `core/input-invalid.json` covers all three
forms plus two positive cases, mirrored here via `make sync`. Green in both languages on the first
run (87/87 `--strict`).

## IMPL-013 — a wrong `direction` booked an incoming invoice fully inverted — ✅ FIXED

**Finding (2026-08-15, adversarial probing).** `tax-service.ts:56` read
`input.direction === 'input' ? 'input' : 'output'`. Anything that was not exactly `"input"` —
`"Input"` with a capital I, `"INPUT"`, a typo, `null` — silently became an **output** voucher:

```
postVoucher … "taxCode":"VSt19","direction":"Input","netLines":[{"account":"6000", "200.00"}],"counterAccount":"3000"
  → exit 0, lines: 3000 debit 238.00 · 6000 credit 200.00 · 1500 credit 38.00
```

The exact mirror of the correct booking: the expense credited, the input VAT credited, the
payable debited. The lines carry a valid `taxTag`, so nothing downstream flags it, and the
posting looks entirely ordinary in every report.

**Resolution.** An absent `direction` still defaults to `"output"` (documented); a **wrong**
value is `E_INPUT_INVALID`. Pinned in `testing/scenarios/regression/regressions.json` together with both
positive cases — the default still works, and lower-case `"input"` still books the right way
round.

## IMPL-016 — four declared parameters that no implementation reads

**Finding (2026-08-15, while implementing the projection parameter contract).** Building the
contract made visible what a tolerant reader had hidden: four parameters are passed by existing
fixtures and read by nobody. They are declared in
`testing/testsuite/schema/api-parameters.json` with `acceptedWithoutEffect: true`, which is what
keeps those fixtures green now that an undeclared parameter is rejected.

| Projection | Parameter | What a caller would expect |
|---|---|---|
| `balanceSheet` | `incomeMapping` | the mapping used for the result position — today the sheet uses only `mapping` |
| `journalExport` | `format` | a choice of output format — today there is exactly one (GoBD Z3) |
| `costAllocationSheet` | `fiscalYear` | scoping the sheet to a year — today only `runId` selects |
| `costAllocationSheet` | `period` | scoping the sheet to a period — same |

**Assessment.** The flag is a marker, not a feature: it says "declared so it is visible", not
"works". Each is a small trap of its own kind — a caller who passes `format: "csv"` gets Z3 and no
word about it. The honest fix is one of two things per parameter, and both are decisions rather
than code: **implement** it, or **retire** it in a new fixture (fixtures are append-only, so the
existing ones are not bent). Deliberately left as-is here; the flag is what keeps the gap
readable in the meantime.

## Round 1 backlog — probed, confirmed, and closed (2026-08-16)

Found by two adversarial probing agents on 2026-08-15 and reproduced by hand before being
recorded. All twelve are closed: R-5 … R-7 on 2026-08-15, the remaining nine on 2026-08-16. Each
carries a fixture or a CLI test that fails loudly if it comes back.

| # | Defect | Class |
|---|---|---|
| R-1 | `settle` accepts an allocation larger than the settling entry actually books: GL keeps a 690.00 receivable while the subledger is empty, and cash-basis VAT declares 190.00 collected when 500.00 arrived | ✅ fixed 2026-08-16 — `E_SETTLEMENT_EXCEEDS_ENTRY` + fixture `settlement-bound` |
| R-2 | `auditDataExport` carries P&L accounts across fiscal years, `trialBalance` does not — the ADS balance stream and the trial balance disagree per account (identical in both languages, so byte-parity cannot catch it) | ✅ fixed 2026-08-16 — income accounts scoped per fiscal year (`audit-data-export-fiscal-year`) |
| R-3 | `correct` rewrites an entry's lines and leaves the open item it created untouched (same family as IMPL-008) | ✅ fixed 2026-08-16 — `E_ENTRY_HAS_OPEN_ITEMS` + fixture `correct-open-items` |
| R-4 | `importMapping` reports `imported: true` but the CLI rebuilds the registry from `summae.json` on every invocation and never writes back — the documented import→report flow cannot work | ✅ fixed 2026-08-16 — the import is written back into the workspace (CLI tests) |
| R-5 | `createFiscalYear` coerces a non-numeric `year` to 0 and creates the year anyway; `2027.5` and `-5` are accepted too | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| R-6 | `correct` with a misspelled field is a silent no-op that returns success | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| R-7 | `openItems` ignores an invalid `kind` and returns everything; `datevExport` returns the entries export under a bogus `kind` label | ✅ fixed 2026-08-15 — `E_INPUT_INVALID` |
| R-8 | `init` is not atomic: a failure after the workspace is written leaves a half-built, non-re-initialisable directory. `--first-fiscal-year` is not validated (`""` → year 0000) | ✅ fixed 2026-08-16 — validated year + rollback on failure (CLI tests) |
| R-9 | a corrupted-but-parseable `summae.json` silently yields an empty ledger, because `Workspace.tenant()` defaults every field and regenerates `tenantId` | ✅ fixed 2026-08-16 — `E_WORKSPACE_INVALID` instead of defaulting (CLI tests) |
| R-10 | `init --pack X --rules Y` silently drops `--rules`; the help calls them alternatives | ✅ fixed 2026-08-16 — `--pack` and `--rules` together are rejected (CLI tests) |
| R-11 | a 1–2 cent invoice with 19 % VAT is unbookable: the derived tax line rounds to 0.00 and is then rejected by the "amount > 0" rule | ✅ fixed 2026-08-16 — a zero tax line is dropped, not forced (`cent-invoice`) |
| R-12 | accounts outside the pack's mapping ranges vanish from `incomeStatement` while `balanceSheet`'s result position still counts them — the two reports then disagree | ✅ fixed 2026-08-15 via IMPL-014 (`_unassigned` + `gapWarnings[]`) |

Most of R-5 … R-7 are the same shape: `typeof x === 'T' ? x : <default>` used as validation, which
cannot tell "absent" from "wrong". With `E_INPUT_INVALID` available they were closed in one sweep
rather than patched individually.

**Resolution of R-5 … R-7 (2026-08-15).** Absent keeps its documented default everywhere — no
`kind` still means "no filter", no `datevExport.kind` still means `entries`. A *present* value that
is not a valid value is now `E_INPUT_INVALID` (exit 45): a `year` that is not a positive whole
number, an `openItems`/`datevExport` `kind` outside its enumeration, and a `correct` call that
carries neither `text` nor `lines` (which is what a misspelled `txt` amounts to). Pinned in
`testing/scenarios/regression/input-validation.json`, each rejection paired with a positive case so the
guards cannot forbid too much.

## IMPL-017 — an unmapped balance account made the balance sheet stop balancing

> **RESOLVED 2026-08-15** (fixture `balance-sheet-gap`, both languages).

**Finding (2026-08-15, while fixing IMPL-014).** `balanceSheet` iterates over the mapping's
**positions** and pulls the accounts each one matches. An account no position matches is therefore
never visited: its amount lands in neither total, and the sheet silently fails to balance.

```
accounts 1200 (bank, mapped) · 2600 (securities, NOT mapped) · 3000 (payables, mapped)
post 5000.00: 2600 debit / 3000 credit
report balanceSheet
  → assetsTotal "0.00"   liabilitiesAndEquityTotal "5000.00"     ← off by the whole amount
  → positions: only P.V — the asset is not in the report at all
```

This is worse than IMPL-014, where the two statements merely disagreed. Here a single statement is
internally inconsistent, and it is the statement whose defining property is that both sides match.

**What `by construction` actually covers.** api.md promises the balance-sheet identity "by
construction", and while fixing IMPL-014 I took that to mean the whole sheet. It does not. The
*result position* is by construction: it sums income accounts by TYPE, no mapping involved, so no
income account can escape it. The *asset and liability side* is a different mechanism — it is
mapping-driven, and its completeness was assumed rather than enforced.

**Resolution.** The same treatment as IMPL-014, which is also the one the error catalogue
prescribes: a catch-all position `_unassigned` per section plus `gapWarnings[]`. The section is
chosen by account **type** (asset → assets, everything else balance-carrying →
liabilitiesAndEquity), which is jurisdiction-free and always available; the mapping cannot answer
the question, since the whole problem is that it says nothing about this account.

The catch-all is deliberately not a licence to skip mapping work: `importMapping` still reports
every uncovered account at import time, and the position appears in the report whenever it carries
something, so the gap is visible rather than absorbed.

## IMPL-014 — accounts outside a mapping's ranges vanish from the income statement

> **RESOLVED 2026-08-15** (fixture `income-statement-gap`, both languages). The income statement
> now routes an unmapped account into the catch-all position `_unassigned` and reports
> `gapWarnings[]` naming the accounts that landed there — the treatment the error catalogue
> already prescribes ("Mapping-Lücken sind kein Fehler: gapWarnings[] + Auffangposition") and
> that `importMapping` already applied. The two statements agree again.
>
> `balanceSheet` was deliberately **not** changed. It sums income accounts by type, and that is
> precisely what makes the balance-sheet identity hold *by construction* (api.md); deriving its
> result position from a mapping instead would make the identity depend on the mapping being
> complete. Which also settles `balanceSheet { incomeMapping }`: it stays declared and without
> effect, because the position it would feed must not be mapping-dependent.
>
> The second case below — a depot at 1250 landing under bank balances — is **not** covered by
> this fix. It is a de-pack range question (`de-bilanz` maps 1200–1399 wholesale), not an engine
> one, and belongs to the pack.

**Finding (2026-08-15, answering "how many accounts can I create?").** There is no limit on the
number of accounts (546 created without complaint, `importChartOfAccounts` took 500 in one call)
and no assumption anywhere of a *single* bank account — several current accounts, call-money and
fixed-term deposits all work as `subtype: "bank"`. But a chart of accounts is only half the
story: **a mapping decides what a statement shows**, and an account outside every range is
silently dropped.

```
createAccount 7100 (expense) · post 300.00 to it
report incomeStatement --params '{"fiscalYear":2026,"mapping":"de-guv"}'
  → {"positions":[],"netIncome":"0.00"}        ← the expense is not there
report balanceSheet   --params '{"fiscalYear":2026,"mapping":"de-bilanz"}'
  → P.A2 Jahresergebnis "-300.00"              ← but it IS in the result
```

`de-guv` covers 4000–4099, 4900–4999, 5000–5999, 6000–6099, 6300–6399, 6500–6599, 6700–6999 —
everything else, including 4100–4899, 6100–6299 and anything ≥ 7000, is invisible to it. The
shipped de chart of accounts fits entirely inside those ranges, so only **custom** accounts are
affected. `balanceSheet` computes its result position from *all* non-balance-carrying accounts
regardless of mapping, which is why the two statements disagree rather than both being wrong.

A second, milder case: a securities depot placed at 1250 lands in "Kassenbestand und Guthaben bei
Kreditinstituten", because `de-bilanz` maps 1200–1399 wholesale — a bank-balance line, not a
financial asset.

**Assessment.** Two separate questions. (a) Should a projection *warn* about accounts no position
claims? `importMapping` already computes `gapWarnings`, so the machinery exists — surfacing it on
`incomeStatement`/`balanceSheet` would make the hole visible without changing any number.
(b) Should `balanceSheet`'s result position use the income-statement mapping instead of all
non-balance-carrying accounts, so the two cannot drift apart? (b) changes numbers and needs a
fixture. Documented, not changed.
