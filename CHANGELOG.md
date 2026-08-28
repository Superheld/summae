# Changelog

Notable changes per release. Loosely based on *Keep a Changelog*,
versioning per SemVer (0.x: minor may break).

> **Finding IDs in entries up to and including 0.9.2 use the old prefixes.** On 2026-08-23
> the finding series were re-prefixed to keep them out of the requirement namespaces —
> `F-0xx` → `SPEC-0xx`, `F-CROSS-001` → `SPEC-C01`, `NF-0xx` → `IMPL-0xx`; the numbers are
> unchanged. These notes keep the IDs they were published with, because a released note
> should describe what was released. The mapping lives at the top of
> [`SPEC-FINDINGS.md`](SPEC-FINDINGS.md).

## Unreleased

### An account's validity window is real now (F-CORE-045)

`validFrom` and `validTo` were declared on `account` in the normative format schema and **no
implementation read or wrote them**. `importChartOfAccounts` dropped them without a word, so an
account carrying `validTo: 2025-12-31` accepted a posting dated 2026-06-01 exactly as if the field
had never been there. A field the schema declares and the engine ignores is the mirror image of the
rule this project already had for the other direction ("a field the engine reads but the schema does
not declare is a finding"), and worse in one respect: whoever set it believed it did something.
Removing it from the normative format would have been the other honest closure and the more
expensive one — so it is built.

**It is a window, not a lock, and both have to exist.** `lockAccount` is unconditional and about
*now*: it refuses every posting including a late correction dated before the lock, which is exactly
wrong for an account retired at a year end. The window is judged against the **posting's own date**,
so an account valid to `2026-12-31` keeps taking its December correction booked in February and
refuses January — `E_ACCOUNT_NOT_VALID_AT_DATE`, a code of its own because "unlock the account" and
"fix your date" are opposite corrections. **Writes only:** an account outside its window keeps every
figure ever posted to it in every report and carries its balance forward. A `validTo` before its
`validFrom` is refused at the master data (`E_INPUT_INVALID`) rather than at the first posting.

`createAccount` and `importChartOfAccounts` accept both fields, `accounts` reports them (so a picker
can be filtered by the chosen date), the audit trail records them when set, and both adapters
persist them.

> **Upgrading an existing workspace needs nothing.** The schema installer now also adds a **missing
> nullable column** to a table that already exists, in both languages — until now it only ever
> created missing *tables*, and its own docblock called the column case "by hand". The first change
> that needed it showed why that was not good enough: an existing workspace kept the old table and
> failed on the next insert. Pinned by `testAnExistingTableGainsANullableColumnInsteadOfBreakingOnTheNextInsert`
> / `gives an existing table a nullable column instead of breaking on the next insert`. Type changes
> and rewrites still need a real migration, which neither language has.

New error code `E_ACCOUNT_NOT_VALID_AT_DATE` (`fehlerkatalog.md` + both exit-code tables), fixture
`core/account-validity`, and a row in `docs/gobd-conformance.md` §6.

### `duplicateVouchers` — the same document entered twice (F-CORE-044)

`voucherNumber` is a free string with no uniqueness of any kind, and `postVoucher` substitutes
`''` when none is supplied. An incoming invoice booked a second time therefore produced a second
voucher, a second balanced entry and a **second input-tax deduction** — with every invariant the
library has satisfied: the entries balance, both carry a voucher, both sit in an open period, the
trial balance adds up. Nothing looked wrong anywhere. The money was simply claimed twice, and no
projection said so.

**Grouping is by document identity, not by number:** the issuer (`partnerId` where the voucher
names a partner, the free-text `issuer` otherwise) plus the number. Two suppliers legitimately
send the same invoice number, which is why this is **a report and not a refusal** — a uniqueness
rule on `voucherNumber` would be wrong rather than merely strict, and the only thing worse than a
missing check is one that blocks correct bookkeeping. Same line `vatReturn.gapWarnings` draws.

Three exclusions, each because including it would produce noise instead of findings: an empty
`voucherNumber`, a `recurring` voucher (a standing document repeating its number is what the flag
means), and — per voucher — entries that are a reversal or have been reversed. `postedTotal`
counts only what still moves the books, so a duplicate already corrected reads `0.00` and stays in
the list *with its history*; `stillPosted` says how many of a group still count.

**No parameters, and a date window least of all:** an invoice entered in December and again in
January is exactly the case this exists for, and a window on the voucher date hides it at the
boundary. Fixture `core/duplicate-vouchers`; `docs/gobd-conformance.md` §4 gains a row that is ✅
for *detectable* and explicitly says nothing about prevention.

### The `de` pack now also says *no* — a small business's revenue may not show VAT (`de@2026.9`)

The constraint socket has had two predicates since 0.16.0, and the shipped packs used exactly one of
them. `requireAccountIn` was in force through `de-entgeltminderung` (§ 17 UStG); `forbidAccountIn`
existed only in `xx-8`, a fixture that brings its own pack. A vocabulary that no shipped pack ever
speaks half of is hard to tell from a feature with a data file.

**New module `de-kleinunternehmer` (`constraint`, 2026.1).** § 19 Abs. 1 UStG raises no tax on a
small business's turnover, so an entry that books `4040` (Erlöse Kleinunternehmer) together with an
output-VAT account is refused with `E_COMBINATION_FORBIDDEN`. The entry it refuses is otherwise
flawless — balanced, vouchered, in an open period — and § 14c Abs. 2 UStG then makes the tax it
shows **owed anyway**, which is the expensive half: the books are wrong *and* the money is due.

**Why `4040` and not `4030`,** because that reasoning is what a later pack author needs:
`forbidAccountIn` refuses a *combination inside one entry*, so it is only usable where the
combination cannot occur legitimately. A collective invoice may carry a taxable and an
intra-community supply at once, so a rule on `4030` would refuse a correct entry. The § 19 status
holds for a whole calendar year, so no single document mixes small-business and standard-rate
turnover. What the module deliberately does **not** say is the input side: § 19 Abs. 1 Satz 4 UStG
denies the input-tax deduction too, but an input-tax posting carries no revenue line, so a rule hung
on `4040` would never fire — that prohibition depends on the tenant's *profile*, and the constraint
vocabulary has no conditional. Named as a gap rather than covered by a rule that looks like it
covers it.

`de` moved to **2026.9** and has two `constraint` modules; they add up rather than replace, which
`xx-10-constraint-rules-readable` now pins (two modules in one pack, both firing, both reported by
`tenantConfiguration`) alongside the readability of `accountCombinationRules`.

**One fixture retired.** `de-entgeltminderung-erzwungen` pinned `tenantConfiguration.
accountCombinationRules` as a list of exactly two — the *complete* rule set of the shipped pack — so
Germany could not forbid a second thing without it going red. That is the weld `de-pack-resolves`
and `system-description-invariants` were retired for, six days later and one layer further in. The
successor `de-entgeltminderung-erzwungen-current` drives the same nine steps and pins everything
except how many rules the German pack happens to declare; `testing/testsuite/superseded.json` says
so.

New fixtures: `de-kleinunternehmer-ust-verboten`, `xx-10-constraint-rules-readable`,
`de-entgeltminderung-erzwungen-current`.

## 0.16.0 — 2026-08-28

> **Minor, not a patch, and the number was wrong before this line was written.** The work below
> moves the data format 0.7 → 0.8, adds two projections (`auditTrailIntegrity`, `gdpduExport`) and
> changes the shipped `de` pack's behaviour — a posting that reduces a taxable consideration without
> its VAT correction is now refused where it used to go through. Under 0.x a minor may break, and
> this one does, for a tenant that was booking incompletely.

### The Z3 data carrier an auditor actually receives (F-IO-012)

`journalExport` has always produced the *self-describing data set* — streams, content hashes, field
catalogue — which is what machine evaluability under GoBD Z3 requires. What a German tax auditor
receives on the medium is a different shape: **flat files plus an `index.xml`** written to the
*Beschreibungsstandard für die Datenträgerüberlassung*, which is what the audit software IDEA
imports. That mapping was not in the package, and `docs/gobd-conformance.md` §10 carried it as its
last open row.

**`gdpduExport` produces it**, written against **standard version 1.6 of 1 March 2019**, DTD
`gdpdu-01-03-2019.dtd`. Five tables — journal, accounts, vouchers, partners (only when there are any)
and the audit log — each with its columns typed and described in `index.xml`.

- **The journal is flattened to one row per posting line.** A CSV cannot nest, and an auditor's first
  act is to sum debit and credit per account, which needs the line rather than the entry.
- **Keys are declared**, primary and foreign, so IDEA can join the five files instead of receiving
  them unrelated. That is the difference between a data set and five dumps.
- **The fixture pins the exact bytes** in both languages. The DTD fixes element order — `Table` is
  `(URL, Name?, Description?, Validity?, …)` and an importer rejects a shuffled file — so a subset
  expectation would let a reordering pass unnoticed.
- **Conformance was established, not assumed:** the output was validated against the published DTD
  with a negative control (a `Table` whose `Name` and `Description` are swapped is rejected, so
  "valid" meant something). `GdpduIndexStructureTest` / `gdpdu-index-structure.test.ts` then state the
  DTD's content models as assertions, so the reason lives in the repository rather than in a memo.

**Three things stay yours**, and the response says so in `notProvided`: writing the files (summae
owns no file system), placing `gdpdu-01-03-2019.dtd` on the medium (a normative document we name
rather than redistribute), and supplying the voucher images.

**This reverses a documented scope decision, and the reversal is recorded where the decision stood.**
The root `CLAUDE.md` and `out-of-scope.md` listed the mapping as deliberately out of scope — "don't
start it by accident". The reasoning was never wrong about the facts; it was wrong about what it
implied. "We ship the mapping's *input*" and "the books are auditable" are different claims, and an
audit asking for a data carrier does not care which one was meant. The justification that aged worst
is the old entry's own closing line — *"nothing in the test suite fails because of this, which is
exactly why it is written down here"*. In hindsight that is the warning, not the defence: a scope
decision that survives only because nothing tests it is one to re-read periodically.

### The six us-pack sign-offs are decided — and the product does not move

Open since 2026-06-23, they kept the `us` pack from being recommended for production. Five confirm
what already ships; one is a deliberate **non**-change. Full reasoning:
[`docs/proposals/us-pack-signoffs.md`](docs/proposals/us-pack-signoffs.md).

- **Account numbers** — approved as shipped. `1xxx`–`6xxx` is *the* US block convention, so a US
  accountant reads the chart without being taught it.
- **`USETAX`** — approved. Cost plus liability is right rather than a compromise: US use tax carries
  no input credit, so the tax **is** a cost. No dedicated `use_tax` mechanism — identical code under
  a nicer label, and the repertoire is closed for cross-language reasons.
- **`accrual` default** — confirmed, and the reason is the sales tax, not GAAP: the liability
  generally arises on the date of the sale. Where a state allows cash-basis reporting it is an
  election, which is exactly what a per-tenant `taxationMethod` is.
- **Multi-state** — one rate per tenant stays the scope; nexus and rate lookup are the application's.
  Now stated where somebody choosing the pack will hit it (`pack-library/us-pack/README.md`) instead
  of only in a decisions memo.
- **`EXEMPT`** — closed, and it had been **built since 0.5.0**. The memo asked for a decision that
  had already been made and shipped, and nothing noticed for two months.
- **Naming** — `us` and `us-accounts-2026` confirmed. **`SALETAX` stays**, and this is the
  interesting one: US usage is "sales tax" without exception, so it reads like a typo, and this
  sign-off item existed to catch exactly that. Nine conformance fixtures *drive* the code as input
  while proving the shipped pack, so renaming it would leave nine behaviour-pinning fixtures
  describing a product that no longer exists — and those are argued with, never retired. **The lesson
  is the keeper:** an identifier under an open sign-off should not be pinned by a fixture until it is
  signed off, or the sign-off is theatre.

`knowledge/99-pack-docs/us-pack/offene-entscheidungen.md` was wrong about its own product — it
proposed six account numbers the pack never shipped and described `EXEMPT` emitting a `0.00` line and
the pack having no fixtures. Pre-build notes nobody reconciled afterwards; corrected.

### The audit trail is now *checkable*, not merely append-only (F-CORE-043, format 0.8)

Until now the trail was append-only **because no code path updates or deletes it** — the port offers
`append` and `all` and nothing else. That is a property of the procedure, not of the data: an auditor
could read the source, or trust the deployment, and `docs/gobd-conformance.md` §13 said plainly that
a direct `UPDATE` against a `summae_*` table left no trace at all.

Every audit record now carries `previousRecordHash` and `recordHash` (SHA-256 over canonical JSON,
RFC 8785), and the new projection **`auditTrailIntegrity`** walks the chain. Both languages compute
the same bytes; `make cross` proves it rather than assuming it.

Read the four counts apart, because collapsing them is how a check like this becomes useless:

- **`chained`** — verified: hashes to its own value and links to its predecessor.
- **`unchained`** — written before 0.8, so it has no hash. Explicitly **not** a break: a library that
  cried tampering over its own upgrade would be ignored within a week. They can only sit at the
  front; one appearing after a chained record is an insertion and *is* reported.
- **`redacted`** — see below.
- **`breaks[]`** — the rest, each with a named reason.

**Two limits are published rather than papered over.** No chain notices records dropped from the
**end** — hence `head`, to be kept outside summae and compared. And two concurrent appends can read
the same head and both link to it; that fork is reported as a break, truthfully, because from the
data alone a fork and a removal are the same picture.

### An erased audit record leaves a shell, not a hole

The trail has one deliberate erasure hole (`erasePartner`, F-CORE-040). A naive chain would make a
**lawful erasure indistinguishable from a manipulation** — and worse, break the chain there for good,
so every later verification would report tampering that never happened. A warning that is always on
is a warning nobody reads.

An erased record therefore keeps its row and both hashes and loses everything else: `actor`,
`objectType` and `action` carry the reserved value `redacted`, `objectId` points at the record
itself, `changes` is empty. Linkage stays provable; the shell's own content does not — which is
exactly what an erasure means, and why shells are counted separately instead of folded into
`chained`.

### Fixed: neither persistence adapter chained the trail

`knex` and `laravel` inserted records unchained and **deleted** rows on erasure, so an in-memory
trail and a persisted one disagreed while the whole suite stayed green — no fixture reached the
chain. `core/audit-hash-chain` now does, in both subjects, and it goes red without the adapter change
(checked by reverting it). Same shape as the missing `AuditWriter` of 0.12.0: what the adapters leave
out is invisible to tests that run against fakes.

### SPEC-022 is resolved by correcting its premise

It recorded the chain as blocked by a normative rule and offered two product decisions. The rule
reserves `previousEntryHash` on the **posting**; the obligation it was meant to serve asks for
tamper evidence on the **audit trail**. Two different chains had collapsed into one word. Nothing had
to be amended: the trail's chain is built, and the posting's field stays reserved until format 1.0 —
a chain every conforming reader is *instructed to ignore* would be evidence for nobody, and 1.0 is a
statement about stability that a hardening has no business forcing.

`datenformat.md` also gains its **0.7** section, which was never written: the schema shipped that
version while the normative document still described 0.6.

### The `de` pack now enforces the VAT correction on a reduced consideration (A-13, `de@2026.8`)

§ 17 Abs. 1 UStG says a reduced consideration corrects the tax owed, and sentence 2 says the input-tax
deduction is corrected with it. summae could not hold either, and the gap sat on the embedding
application's obligation list as **A-13**. `accountCombinationRules` gave it a shape three days ago;
what was still missing is that **no shipped pack declared such a rule**, and a capability nobody
ships is not a guarantee.

What made this worth building is that the incomplete entry is otherwise flawless: it balances, it has
a voucher, it is in the right period, and every account and the trial balance read correctly. The
only wrong figure is the one that gets filed — the one place nobody checks twice.

- `4020` (granted discount) must be met by an output-VAT account, `5010` by an input-VAT account,
  else `E_COMBINATION_REQUIRED`. Fixture `pack/de-pack/de-entgeltminderung-erzwungen` proves both
  refusals, both correct postings, and — the edge that matters — that a discount on a **tax-free**
  intra-community supply still goes through untouched.
- The chart gained `5010 Erhaltene Skonti und Nachlässe (vorsteuerpflichtig)`: the input side had no
  reduction account at all, so there was nothing for the obligation to hang on. `4020` was renamed to
  say that it is the *taxable* one. **Migration:** a tax-free reduction booked on `4020` is now
  refused and belongs on the revenue or expense account it reduces. The predicate sees one entry and
  can never ask whether the original sale carried tax, so the account name is what makes the question
  answerable — which is also why German charts have kept discount accounts per rate all along.

### Fixed: the pack format rejected a constraint module carrying only the second predicate

`format.schema.json` required `dimensionRules`, so a module with only `accountCombinationRules` did
not validate — an oversight from adding the second predicate. The first pack that needed exactly that
found it on the first run. A module now carries **at least one** of the two.

### The GoBD census stops being able to describe a product that moved

`docs/gobd-conformance.md` claimed the `de` pack lacked codes for the intra-community acquisition and
the exempt export. Both were built on 2026-08-23 — *hours after the row was written* — and the row
went on saying otherwise through five days of green builds, because nothing compared prose to
product.

The rows stay prose on purpose; a census reduced to a machine-readable list stops being readable by
whoever has to defend it. But the **facts quoted inside them** move into a table (§15) that
`GobdConformanceDocTest` / `gobd-conformance-doc.test.ts` holds against its sources: each pack's
shipped tax codes, the engine's registered mechanisms and tax-base kinds, and the account-combination
rule behind the A-13 ✅. Writing the table found the first defect immediately — the `us` code is
`SALETAX`, not `SALESTAX`.

`allTaxMechanisms()` / `allTaxBaseKinds()` are exported from the core for this; the PHP twins were
always reachable, so this is symmetry rather than new surface.

### Fixed: both CLIs reported the version they had at 0.1.0 (IMPL-035)

`summae --version` answered `0.1.0` in Node and `0.1.0-dev` in PHP. Both literals were written at the
first release and never again — the number had been wrong since 0.2.0 and stayed wrong through
fifteen releases, on a disk whose `package.json` said 0.15.0.

The stale number is the smaller half. The two implementations were stale *differently*, so the same
question put to the two CLIs got two different answers — the equivalence policy broken on the first
surface anybody touches. No cross-test could reach it: that test drives `journalExport` over a shared
database, and `--version` never opens a book.

Both constants are now asserted against the newest **dated** heading in this file
(`## X.Y.Z — YYYY-MM-DD`). Dating a section is what makes a release — `release-notes.yml` refuses to
publish without it — so the guard goes red inside the release commit, when the bumps are due. An
undated `unreleased` section deliberately does not move the anchor: between releases both CLIs keep
naming the last version that shipped, exactly as the published `package.json` does.

`ReleaseVersionTest` / `release-version.test.ts` pin it in both languages, and they went red on the
real values before the fix.

**It was not unguarded — it was guarded by the wrong test.** `SmokeTest::testAllPackagesAutoload`,
written at JOB-000 to prove the three packages autoload, reached the package markers through
`assertSame('0.1.0-dev', …)`. So the constants could not be bumped without turning red a test whose
stated subject is autoloading, and the red would have looked like a regression rather than a job.
That is the more useful half of this finding: a stale value survived fifteen releases *because*
something asserted it. The smoke test now asserts that the classes load, which is what it is for.

`CorePackage::VERSION` was stale the same way and had no reader at all besides that smoke test. It
is bumped and held to the same anchor rather than left standing as the stale twin of the constant
next to it. Nothing prints it; it is the declared version of a published package all the same.

### The tax expansion gets its second seam (F-TAX-010)

`core/src/CLAUDE.md` has carried this since the mechanism repertoire was closed on 2026-08-16, and it
was written as a diagnosis rather than a plan: the mechanism seam covers only *line assembly* — it
receives an already-computed, already-rounded tax amount — while **the variance that actually differs
between jurisdictions sits before it and had no socket at all**. `base × rate / 100` was written twice
inside `TaxService`, once per rounding granularity, and no pack could reach it. Every tax system that
quotes prices with the tax already inside was therefore inexpressible, and that is most of them.

A tax code version now carries `taxBase`:

- **`net`** — the amount handed in is the base. What every shipped pack means, and the default when a
  code says nothing, so nothing in `de`, `us` or `default` changes. All 183 existing fixtures stayed
  green through the change.
- **`inclusive`** — the amount handed in is the **gross**. `tax = amount × rate / (100 + rate)`.

**What makes it more than a division.** With an inclusive base the **net line moves too**: the split
is precisely what the caller could not do for themselves, and handing their gross back as a net line
would post the tax twice. Rounding happens **once**, on the tax, and the base is what remains — the
other order lets base and tax fail to add up to the amount actually posted, and in an inclusive
régime the gross is the fact while the split is arithmetic. Across several lines of one code the
group base is **allocated by largest remainder** rather than recomputed per line: two lines each
rounding half a cent up would otherwise produce a group a cent too large.

Unlike `mechanism`, which is deliberately an open string, `taxBase` is a **closed enum**. An unknown
value is refused with `E_TAXCODE_INVALID` rather than falling back to `net` — a misspelled base is a
wrong number in the books, not a missing feature.

**This does not reopen the closed-repertoire decision, and the reason is the useful part.** What
remains is not a base *function* at all: a **compound** base (Canadian PST on a GST-inclusive amount)
needs another code's result and therefore an ordering between codes, which a function handed one
amount and one rate cannot see; **tax at payment time** (withholding, split payment) is a timing
question; a **margin scheme** needs the purchase price of the thing sold, which is not in the posting.
A mechanism is still not describable as pure data, so `core/src/CLAUDE.md` keeps its "settled" — with
the paragraph rewritten to say which half moved.

`xx-9-tax-base-inclusive` pins all of it in both languages, including the rounding case that
distinguishes allocation from recomputation.

### The constraint socket gets a second word (F-CORE-042)

`docs/gobd-conformance.md` §14 item 6 has carried a ⚠️ since the constraint socket was built, and the
wording was exact: *the shape of the socket is settled, its vocabulary is not*. A pack could express
one thought — this account may not be posted without that dimension — and nothing else a jurisdiction
forbids. A socket with a single predicate is not visibly a socket; it might be a feature with a data
file beside it.

`accountCombinationRules` is the second word: which accounts must, or must not, appear in **one entry**
together. Per rule, `whenAccountIn` plus exactly one of `requireAccountIn` / `forbidAccountIn` — a rule
saying both would be two rules under one name. Violations are `E_COMBINATION_REQUIRED` and
`E_COMBINATION_FORBIDDEN`, two codes rather than one with a flag, because a script branching on the
exit code has to take opposite corrective actions for *you are missing a line* and *you have one line
too many*.

**The require case is A-13**, the obligation that has sat on the embedding application's list because
summae could not express it: a granted discount reduces the consideration and must carry its tax
correction. It is now declarable in a pack.

**Why "the entry" and not "the other side", which is the part worth keeping.** In the correct booking
the discount is a debit and so is the VAT correction, with the receivable on the credit — both required
lines sit on the *same* side. A predicate about sides would have missed the very case it was built for.
"Somewhere in the same entry" is also the weaker claim, and the weaker claim is the one a pack can
reason about without knowing how an application splits its lines.

**What it deliberately still cannot do**, stated because a socket's limits are part of its contract: it
sees one entry. No deadlines (§5 keeps those ➖ on purpose — they are German rules with exceptions, and
a hard block would make late but honest bookkeeping impossible). No reach across entries. And no rule
about a *settlement*: `settle` records an allocation and posts nothing, so there is no entry there to
constrain. A-13 is reached through the posting instead, which is where the books actually change — and
the GoBD row moves from ➖ to ⚠️ rather than to ✅, because the shipped `de` pack does not declare such a
rule yet. That is a product decision about accounts and reporting consequences, not a missing mechanism.

`tenantConfiguration` reports the rules in force, for the same reason it reports the dimension rules:
an embedding that offers a booking screen has to know which combinations will be refused.

### Added: `personalDataDescription`, and the address shape it needed (F-CORE-041)

The remaining two GDPR rows, closed together — and the second one not the way it was written.

`personalDataDescription` is the counterpart to `systemDescription`: that projection answers *what
does this system record, and about what*; this one answers *where can operator-supplied free text
come to rest in these books, and how much of it is actually here*. `fields[]` names every holder and
field with `freeText`/`required`/`present`, `addressKeys[]` says which address keys the tenant really
uses, `counts` gives partners, vouchers and distinct actors. It is **generated**, which is the whole
argument for it — §1 of the census is hand-written, and a hand-written inventory is the kind of list
that goes stale in silence.

**The axis held, and that is the reason this belongs in summae at all.** *Where* identifying fields
sit is mechanism: the partner has a name, the trail records an actor, a posting carries text, and
that is as true in the `us` pack as in the `de` pack. *Whether* a field counts as personal data is
not mechanism — a company identifier is personal data for a sole trader and not for a corporation —
so the projection **never classifies**, and says so in a `classification` field rather than letting a
reader assume. It also reports shape and never content: `present` counts partners with an address,
`addressKeys` names keys and not values. A tool built for a privacy obligation must not become the
convenient way to read everybody's data out.

**The address declaration went the other way from what the census proposed.** That row asked for the
fields to be declared so the format could support data minimisation. They are — `line1`, `line2`,
`postalCode`, `city`, `region`, `country` as ISO 3166-1 alpha-2 — but `additionalProperties` stays
**open**, deliberately. Closing it would make an export of lawful data invalid: books written before
the shape existed carry whatever they carry, and a schema that rejects them turns a privacy
improvement into a data-loss event. So the declaration says what to *write* and `addressKeys` says
what is *there*, which is the half a declaration can never supply and the half an inventory needs.
No street/houseNumber split — it does not survive contact with addresses outside the German-speaking
world, and `line1`/`line2` do.

### Not done: the audit hash chain, and why (SPEC-022)

`docs/gobd-conformance.md` §14 item 5c records the per-entry hash chain as *deferred, not rejected*.
Picking it up ran into something no amount of effort gets past: `datenformat.md` says of the reserved
fields, normatively, that **readers must ignore them and writers must not populate them in v0.x**.
`previousEntryHash` is one of them, and we are on format 0.7.

Writing the chain anyway would break the rule in both halves, and the second is the one that counts:
a conforming reader has been *instructed to ignore* the field, so a chain written today is tamper
evidence for nobody but us — an auditor's tool, another implementation and a future reader would all
be conforming precisely by ignoring it. Bumping the format to 0.8 does not help; 0.8 is still v0.x.

So nothing was built, on purpose: a chain that exists but is ignorable is worse than none, because it
reads like protection. The way out is a decision — amend the reserved-field rule for format 0.8, or
take it to 1.0 where the reserved fields were always meant to go live — and that is a product call,
not an engineering one. Recorded in full as SPEC-022, which is the first entry in the open register
since it was emptied. Manifest-level hashing (SHA-256 per stream, RFC 8785) is untouched and still
does what it always did.

### The Node persistence suite caught up with the PHP one (IMPL-036)

`implementations/node/CLAUDE.md` said the knex suite is the twin of `packages/laravel/tests` and
that the two are to be kept in step. They were not, and the uneven part is the finding: the
**hydrator and the schema installer had no Node tests at all** while having seven on the PHP side.
That is the worst place for a gap to sit — the hydrator is where the *shared* data format is
produced and consumed (PHP writes these documents, Node reads them, SF-15), and its defensive
branches never fire on the happy path the conformance runner walks. A wrong default there does not
crash; it silently drops data.

`hydrator-and-schema.test.ts` closes it, named after its PHP twin on purpose so that "are these in
step?" can be answered by listing both directories. Twelve cases: the money fallback, dimensions
and tax tags surviving a line (including an incomplete dimension being dropped rather than guessed),
the date half of a timestamp column, UTF-8 round-tripping unescaped, broken JSON refused instead of
read as empty, the schema created and dropped and dropped again, and the account number unique per
tenant rather than globally. Two more went into `adapter.test.ts`: `byId` refusing another tenant's
row — checked for listings and writes but never for the single most likely call — and the stored
JSON being the aggregate's own serialization rather than a shape the repository invented.

Building it surfaced a real API gap: **PHP had `SchemaInstaller::drop` and Node had no way to drop
the schema at all.** `dropSchema` now exists, idempotent, in reverse creation order.

**A correction worth publishing, because the number was in a report.** This gap was first sized as
"32 test methods against 15" by comparing PHP methods to a grep for `it(`. The real figures were 60
against 76, and the first pair overstated it about fourfold: PHPUnit data providers turn one method
into many cases, so counting across two frameworks measures the frameworks. Node is at 74 now.
Counting was the wrong instrument; *which modules have no test at all* was the right one, and it is
what found the hydrator.

### Added: `erasePartner` — the one place summae was doing something wrong (F-CORE-040)

The GDPR census listed three open rows; this closes the first, and it is the only one where summae
did not merely fail to *offer* something but actively kept data it had no basis to keep. A partner
that no voucher and no open item has ever named — one created by a typo — is outside every retention
duty, and the right to erasure applies to it undiminished. There was no way to remove it.

```
summae op erasePartner --input '{"partnerId":"…","actor":"datenschutz@example.test"}'
→ { "id": "…", "erasedAuditRecords": 1 }
```

**The scoping in the census row was wrong, and the way it was wrong is the interesting part.** It
proposed refusing when *any* record referenced the id, audit records included — and `createPartner`
always writes one, so the operation could never have succeeded. Worse, the naive version would have
erased nothing: that creation record holds the name and, if given, the address in `changes`, so
removing the partner row alone moves personal data to where nobody looks. The operation therefore
takes the trail's records **about that partner** with it, reports how many in `erasedAuditRecords`,
and appends a single record in their place — id, actor, moment, and `existed: true → false` as its
diff, with no personal payload at all. The trail keeps the fact that an erasure happened, which is
what an audit asks of it.

That last shape was **forced by a test rather than chosen**: the audit-trail contract requires every
record to carry a before/after diff, so an empty `changes` failed. The refusal was right — a diff
about existence is truthful, costs nothing and reveals nothing, while an exception would have been
the first record in the system exempt from a published invariant.

`E_PARTNER_IN_USE` when the books do reference the partner — deliberately not `E_PARTNER_UNKNOWN`,
because the partner exists and is kept on purpose — and `details` carries `vouchers`/`openItems` so
an application can give a data subject a reason rather than a bare no.

**This is the one hole in "the trail is append-only because no code path deletes from it",** and it
is reachable from this operation and nowhere else. `AuditTrail.eraseFor` and `PartnerRepository.remove`
are the only removing methods in the core; the journal, the entries and the trail's records about
them cannot be touched by any API summae offers.

**The jurisdiction guard caught the first draft.** The core comments cited § 147 AO and Art. 17(3)(b)
GDPR, and `NoJurisdictionTextTest` / `no-jurisdiction-text` went red — correctly. The line the core
knows is *referenced by the books or not*; which rule puts a record on which side of it, and under
what name, is documented in `docs/gdpr-conformance.md` and asserted nowhere in the substrate. The
litmus test worked on code written the same day by someone who had just re-read it.

Fixture `partner-erasure` in both languages, plus `PartnerErasureTest` / `partner-erasure.test.ts`
for what a fixture cannot reach: that `eraseFor` takes the records about one object and leaves every
other record standing, and that the refusal's details name what keeps the record.

### Also, found while building it

- **`--strict` never checked what the Makefile said it checked.** The target's comment claimed a
  newly green fixture without an entry in `runner/expected-green.txt` is an error. It is not:
  `--strict` means every fixture green plus a byte-identical double run, and the expected-green list
  is a regression guard consumed by a unit test, which nothing fails for omitting. Comment corrected;
  the new fixture added to both lists.
- **There is no way to read the partner list.** `partners` is not a projection, so an Art. 15 access
  request cannot be answered from the published surface without going through `journalExport`. Noted
  in the GDPR census rather than fixed here.

### Added: a GDPR census, because there was not one word

`docs/gdpr-conformance.md`, the twin of the GoBD document, with the same three statuses. It exists
because a search of this repository for *DSGVO*, *GDPR*, *Datenschutz*, *personal data* returned
nothing at all — in a library that stores `partner.name`, `partner.address` and `vatId`, records
which person performed every state change in `auditRecord.actor`, and exports all of it in
`journalExport`. The legal position is comfortable; the silence was not. A library whose selling
point is a written account of what it does and does not do had a census for one regulation and no
sentence for the other.

What it says, in short: **you are the controller and summae is not a processor** — it runs inside
your process, opens no connection, and nobody here ever holds your data, so no DPA is owed to us.
The right to erasure loses against the retention duty (Art. 17(3)(b) GDPR against § 147 AO), which
means the append-only journal is the technical form of a legal obligation rather than a problem to
solve. §1 inventories every field where personal data can end up, which is the part an Art. 30
record needs and the part most likely to be read in a hurry.

**Three open rows, honestly stated.** There is no `deletePartner` at all — right for a partner the
books reference, wrong for one created by a typo that no entry ever touched, where no retention duty
applies and the right to erasure is undiminished. `partner.address` is declared `{"type": "object"}`
with no properties, so the format cannot support data minimisation for a field whose contents it
does not know. And there is no generated Art. 30 building block, though `systemDescription` shows
the shape one would take — with a clean split along summae's own axis: *where* identifying fields
sit is jurisdiction-free mechanism, *whether* a field counts as personal data is answered
differently by the GDPR and the CCPA, so it is pack data.

**The document is gated like every other.** `GdprConformanceDocTest` / `gdpr-conformance-doc.test.ts`
check that every fixture it cites exists and still runs, that every requirement it names is really
covered, and that its status markers stay the three defined ones. One check the GoBD twin does not
need: every `record.field` in the §1 inventory is resolved against `format.schema.json`, so a
renamed field turns the build red instead of leaving a row that still reads correctly and is quietly
wrong. Both guards went red on a deliberately mistyped field name before being trusted.

### The Node gate reached the Makefile, and stopped being shorter than CI

`make check` ran the whole PHP gate in one word; the Node gate lived as five commands in a
CLAUDE.md — and that list was short by exactly one run. CI has always executed
`pnpm fixtures --subject=database --strict`; the documented local gate did not mention it, so
anybody following the file checked less than CI and found out after pushing. The run is not
redundant: adapters build the tenant themselves, and what they leave out is invisible to a test
against fakes, which is how a missing `AuditWriter` once reached CI green from a green desk.

`make check-node` now runs the Node gate, `make check-all` runs both plus the cross-test, and the
Definition of Green in `implementations/node/CLAUDE.md` names the database subject. A gate that is
easier to run on one side gets run more on that side.

### Also

- **`docs/proposals/` says on line one whether a memo is still a question.** Three decision memos
  lived on unmerged tracking branches since June and are now in the repository. Two of them were
  long since decided — the `ledger.ts` split was executed in both languages, the spec-vs-fixture
  audit was run and came back clean — and both still read as open questions, which is how a settled
  decision gets re-asked. The state is in each title, the differences between what was proposed and
  what was built are recorded, and `docs/proposals/README.md` indexes all three. Only the us-pack
  sign-offs are genuinely open.
- **The last German in the Node build config is gone.** `eslint.config.js` (including the message a
  developer sees when they breach the substrate boundary) and `packages/core/tsup.config.ts`, the
  one of three tsup configs still written in German. The Makefile came along with them. The
  remaining German comment in the suite quotes `api.md`, which is a German document, and stays.
- **The `branch-alias` trap is caught now.** RELEASING.md recorded that
  `extra.branch-alias.dev-main` must be bumped by hand in all three `composer.json`, that it was
  missed at 0.8.0, and that "nothing catches it". Same class of defect — a version string with no
  reader — so the same guard closes it: the PHP test derives `X.Y.x-dev` from the changelog and
  compares all three. The Node half does the same for the three published `package.json` versions,
  which `pnpm -r publish` all ships. The note in RELEASING.md was corrected.

## 0.15.0 — 2026-08-27

**The number you could only get by doing it wrong.** `appropriateResult` has known since v0.4 how
much of a result is still unappropriated — it refuses against that figure on every call — and there
was no way to ask for it. It left the library as the `available` detail of an
`E_APPROPRIATION_EXCEEDS_RESULT`, so an application pre-filling a resolution dialog had two routes:
provoke the error on purpose, or read the balance-sheet position carrying `includesNetIncome`, which
presupposes a mapping and knowing which position that is.

`unappropriatedResult` publishes it. The top-level figures describe the pot as a whole — the
`result_allocation` accounts say what was appropriated and never which year's profit it consumed —
and `byFiscalYear` says where the pot came from:

```json
{ "cumulativeResult": "1400.00", "appropriated": "900.00", "unappropriated": "500.00",
  "legalForm": "gmbh", "resolutionRequired": true, "resolutionBasis": "§ 42a Abs. 2 GmbHG",
  "byFiscalYear": [ { "fiscalYear": 2026, "result": "900.00", "available": "0.00",
                      "resolutionDueBy": "2027-11-30" }, … ] }
```

`available` is not a copy of the cap — the operation now asks the same projection, so the number on
a screen and the number in a refusal cannot drift apart.

### And when the resolution is due: `setEntityProfile` + the `legalForms` module kind

A resolution has a deadline, and neither half of it is the core's business: *whether* one is owed and
*by when* depend on the legal form as much as on the jurisdiction. So the pack carries a catalogue
and the core carries the arithmetic.

```
summae op setEntityProfile --input '{"legalForm":"gmbh","sizeClass":"small"}'
```

- **`de` ships `de-rechtsformen`** — eight forms. GmbH and UG owe a resolution within eight months of
  the year end and eleven when the entity is small (§ 42a Abs. 2 GmbHG), an AG within eight
  (§ 175 Abs. 1 AktG), a registered cooperative within six (§ 48 Abs. 1 GenG); sole proprietorships
  and partnerships owe none. The citation comes back untouched in `resolutionBasis`; nothing in the
  core knows a statute, and `NoJurisdictionTextTest` / `no-jurisdiction-text` keeps it that way.
- **`us` ships `us-legal-forms`** — five forms, none of which owes a statutory resolution, which is
  precisely why the module is shipped rather than left out. `false` ("this form resolves nothing")
  and `null` ("nobody has said what this company is") are different answers, and an application needs
  the difference to decide between showing a deadline, showing nothing, and asking a question.
- **`default` ships neither**, because a jurisdiction-free pack has no company law to ship, and says
  so: `setEntityProfile` refuses with *this pack declares none* and `tenantConfiguration` answers the
  same question with empty lists and no error at all.

**The size class is declared, not computed.** § 267 measures balance-sheet total, revenue *and*
average headcount — and no ledger holds a headcount. Say nothing and you get the regular deadline,
which is the conservative one. **Watching the date stays yours**: "the data must…" is the package,
"the user must by X…" is the app.

The profile is **stored** with the tenant, unlike `actorAuthentication`: it describes the company
whose books these are, not the installation running them, and the change is an audited event
(`entityProfile/changed`) with a date. Reopening reads it leniently — a pack that drops a form makes
the rule stop applying, never the tenant stop opening. `tenantConfiguration` reports it together with
the catalogue, so a "Rechtsform" field can be built from the tenant instead of a hard-coded list.

### Fixed: an appropriated year kept offering a phantom loss (IMPL-033)

Found while building the projection, and it could not have been found otherwise — the number it got
wrong was the one nobody could read. What a year may still appropriate was "the result earned through
it, minus everything appropriated", which is right until a resolution reaches a later year's profit:
appropriate 1200 of a 1400 profit naming 2027, and 2026's figure comes out at −300. The operation read
a negative figure as an unappropriated **loss**, flipped direction and would book up to 300 more
against a pot that held 200 — one profit of 1400, appropriated 1500.

The pot now decides the direction and the ceiling; the year figure only sizes it. Every case that does
not run past the pot is unchanged, and the three shipped appropriation fixtures were green before and
after. `appropriation-pot-direction` pins it in both languages.

### Fixed: the pack manifests were documented as products that do not exist (IMPL-034)

`manifest-de-complete.md` and `manifest-us-complete.md` described packs called `de-complete` and
`us-complete` at version 2026.1, bundling eight modules under ids renamed months ago. Copy from
either and you called `createTenant(de-complete)` and got `E_PROFILE_UNKNOWN`. Exactly IMPL-031's
defect in the half its guard did not reach — the guard walks the manifest's *modules*, the manifest
documents are not modules, and the repair pass followed the guard's shape rather than the folder's.
A guard does not only prove what it checks; it marks where the next reader stops looking.

All three are regenerated from the real manifests, `default` has the one it never had, and the guard
grew a fourth rule over manifest documents. It went red on all three packs before the documents were
written.

### Also

- `CalendarDate.plusMonths` in the substrate, clamping to the target month's last day. Written out
  rather than handed to `DateTime::modify('+n months')`, which overflows 31 January into 3 March in
  PHP and would have disagreed with Node.
- **`io/system-description-invariants` is superseded** by `io/system-description-claims`. It pinned
  the complete list of audited object types, so `systemDescription` could not admit to auditing a
  sixteenth — the same census its own predecessor retired one level up for the capability list. What
  it guarded is guarded better: the audit-trail contract test runs every state-changing operation and
  compares what it writes against what the description claims, in both directions and both languages.
- Four new fixtures for the legal form, one per pack plus a fictional one that pins the mechanism
  (a fiscal year ending 30 November, where plain month arithmetic is a day short of the deadline).
- `knowledge/50-spezifikation/datenformat.md` had drifted four module kinds behind the schema; the
  row now names all ten and says which file is authoritative.

## 0.14.0 — 2026-08-26

> Ships the 0.13.1 section below as well: the pack repair was written, gated and released-noted as
> its own change, and the work that followed reached the registry first. The section stays where it
> is rather than being folded in — what was fixed and why is a separate story from what was built.

**The pack learns a resolution.** Appropriating a profit was the last part of the bookkeeping where
an embedding still had to know account numbers. Everything else has long been a named operation —
an invoice with VAT names no tax account, an asset acquisition names no depreciation account,
because the pack supplies them and the core expands. Carrying a result forward did not: you booked
`2300 an 2100` by hand, and 0.13.1 is the release that showed what that costs, because the shipped
mapping quietly made those two lines cancel each other out.

`appropriateResult` closes it. You state the decision, the pack states the accounts:

```json
{ "fiscalYear": 2026, "entryDate": "2027-05-20", "voucherId": "…",
  "appropriations": [ { "target": "distribution", "money": { "amount": "400.00", "currency": "EUR" } },
                      { "target": "carryForward", "money": { "amount": "600.00", "currency": "EUR" } } ] }
```

**Why it is an operation and not something `closeFiscalYear` does**, which is the part worth keeping:
appropriating a result is a *resolution* — § 29 GmbHG, § 174 AktG and their equivalents — and which
part is distributed, reserved or carried forward is not derivable from the books. It is also dated
when the resolution is passed, normally in the *following* fiscal year, so a close that booked it
would have to invent a date it does not have. summae does not decide; it expands.

### New: the `resultAppropriation` module kind

A pack declares the account a resolution books against and the targets it offers:

```json
{ "kind": "resultAppropriation",
  "data": { "allocationAccount": "2300",
            "targets": { "carryForward": { "account": "2100", … },
                         "distribution":  { "account": "3500", … } } } }
```

Which targets exist is the **pack's** answer, not the core's — `de` offers both, `us` and `default`
offer `carryForward` only, because a jurisdiction that closes its income summary straight into
retained earnings has no second target to offer. Silence is a valid answer; half an answer is not,
which is why the resolver refuses a module whose target names an account that does not exist (I8).

`tenantConfiguration` reports `appropriationTargets[]` so a screen can build its menu from data
instead of provoking an error to find out. Same reason `dimensionRules` is reported there.

### ⚠ What changes

- **Two new error codes**, appended to the exit-code tables: `E_APPROPRIATION_UNSUPPORTED` (the pack
  offers no appropriation, or not this target — the error names what it *does* offer) and
  `E_APPROPRIATION_EXCEEDS_RESULT` (more than the books carry, or nothing to appropriate). Neither is
  `E_INPUT_INVALID`: the input is well-formed, it is simply not satisfiable, and a caller that cannot
  tell the two apart cannot tell a misconfigured pack from a mistyped amount.
- **`tenantConfiguration` gains `appropriationTargets[]`.** Additive.
- **All three packs move**: `de@2026.6`, `us@2026.5`, `default@2026.2`. Old versions stay resolvable
  under `versions/`.
- **`TenantConfigurationProjection` takes a fifth constructor argument** — relevant only to code
  constructing it directly. It has deliberately **no default**: an optional argument is exactly what
  hid a missing wiring during this work, where the projection answered "no targets" instead of
  failing to compile. Same lesson the audit writer taught in 0.12.0.

**What may be appropriated** is the result *not yet appropriated* — the cumulative result up to and
including the named year, minus what the `result_allocation` accounts already carry. That is the same
figure `balanceSheet` publishes, on purpose: the number on the screen and the number the operation
refuses against cannot drift apart. Appropriating less is fine; `remaining` says what is left. A loss
books the other way round and amounts stay positive either way — the direction follows the books, not
a sign the caller has to get right.

The entry is an ordinary posting: correctable, reversible, audited like any other, and — unlike
machine entries — **not** finalized on the spot, because it is a user's input rather than a run.

### The three open reports from the embedding app are closed

**`accountSheet` lines reach their own entry** (SPEC-021). Each line now carries `entryId` — the
identity `journal` already publishes — and `contraAccounts[]`, the accounts on the other side of the
same entry as `{account, name}`, deduplicated and sorted. A list rather than a field, because a tax
code puts two or more there and naming "the" counter account would invent a fact; the side is decided
**per line**, so one entry reads differently from the two sheets it appears on. Additive: the runner
compares subsets, so no existing fixture changed.

**The `default` pack says it ships no statements** (IMPL-032) instead of answering `balanceSheet
requires the parameter "mapping"`, which reads as *you forgot something*. It ships no mapping module
on purpose — a jurisdiction-free chart has no lawful gliederung to bring — and the refusal now says
so and carries `available`, the mapping ids this tenant could pass. `tenantConfiguration.mappings`
answers the same question without an error at all.

**The embedding can declare who is behind `actor`** (SPEC-020), and this is the one where the
library was right and the answer was still unusable. `actorIsAuthenticated: false` correctly says
summae authenticates nobody; an application putting it into a generated Verfahrensdokumentation
printed "Urheber geprüft: **nein**" about an installation that had since grown a login.

```json
// summae.json — read on every open
"actorAuthentication": { "declared": true, "method": "scrypt password login, signed session cookie" }
// → systemDescription.auditTrail
"actorAuthentication": { "byLibrary": false, "declaredByEmbedding": true, "method": "…" }
```

`byLibrary` can never go stale whatever an embedding does; `declaredByEmbedding` is the embedding's
own sentence, quoted and never endorsed. **`null` is not a "no"** — nothing declared and a denial
read differently to an auditor, and a malformed declaration is ignored rather than half-read into a
claim nobody made. Deliberately **not stored** with the tenant: it describes the running
installation, not the books, so dropping a login tomorrow must not leave yesterday's claim in a
record. `actorIsAuthenticated` is unchanged — it was never wrong, only easy to misread.

**The findings register is empty.** All five entries were closed in this release, and four of them
came from outside.

### The pack documentation describes the packs again

`knowledge/99-pack-docs/` is the reference work for building and auditing a pack, and nothing held
it against the modules. The drift was total rather than detailed: every one of the eight `de`
module documents named a module id the pack does not have, the `de` balance sheet listed positions
from a draft that predates the module, and the `us` balance sheet had the two sides of the chart
**swapped** — equity documented at 2000–2499, payables at 3000–3099, eleven of eleven rows wrong.
Five modules had no document at all and the `default` pack had no folder.

Headers, position tables and the folder indexes now come from the modules; the prose was kept.
`PackDocsTest` / `pack-docs.test.ts` hold it there in both languages — one document per shipped
module, the header stating the module's real kind, id and version (so a version bump cannot land
without the document being opened), and every mapping row naming the accounts its position really
claims. IMPL-031, closed.

### One fixture retired

`xx-6-pack-version-pinning` pinned both `contentDigest` values literally, so every field the resolved
bundle gains changed the hash — including one that is `null` for a pack declaring no such module.
That welds the engine's internal shape to the contract, the way a literal `formatVersion` once did.
The successor pins the mechanism with placeholders: a pinned version resolves to the *same* digest
every time, across `resolvePack` and `createTenant` alike. Register: `superseded.json`.

## 0.13.1 — 2026-08-26 (never tagged on its own; shipped inside 0.14.0)

**A mechanism nobody could reach, because the product data hid it.** One fix, and the report that
led to it is the kind worth keeping: an embedding application said "the result of a year is never
carried forward, and the next year's balance sheet says it was". Measured against 0.12.0 on books
with a result of 900.00, the 2027 income statement said `netIncome 0.00` while the 2027 balance
sheet reported a `Jahresergebnis` of 900.00 — the 2026 result, still sitting in the position that
promises "this year".

The engine was right and had been right since v0.3: a balance sheet is a snapshot, the position with
`includesNetIncome` reports the **cumulative** result, and that is what keeps it balancing without
closing entries. Carrying the result into equity is not something a close can do on its own —
appropriating profit is a resolution (§ 29 GmbHG, § 174 AktG), and which part is distributed, put
into reserves or carried forward is not something a library can know. So it arrives as an ordinary
entry, `result_allocation` account against retained earnings, and the balance sheet moves the amount
out of the result position. That has existed since v0.4 (F-CORE-024/SF-25).

**Both shipped packs made that entry invisible.** `de-bilanz` claimed 2000–2499 wholesale, which
swallowed the appropriation account 2300 right next to retained earnings 2100; `us-gaap-balance-sheet`
claimed 3000–3999, swallowing Income Summary 3300 next to Retained Earnings 3100. The two lines of a
correctly booked resolution then cancelled each other *inside one position*: the balance sheet did
not move, the only visible effect was a new zero row, and every following year kept reporting the
prior year's result as its own. Whoever followed the documented path saw nothing happen.

### ⚠ What changes

- **`de` is now `2026.5`, `us` is `2026.4`** (mappings `de-bilanz@2026.3`,
  `us-gaap-balance-sheet@2026.2`). The old module and manifest versions are kept under
  `versions/`, so a pinned `de@2026.4` or `us@2026.3` still resolves exactly what it always did.
- **The equity range is cut around the appropriation account.** `E_MAPPING_OVERLAP` forbids a number
  in two positions, which is presumably how this was missed in the first place: adding the account to
  the result position without cutting the range is an error, not a fix.
- **The result position is relabelled.** `Jahresergebnis` → `Jahresergebnis / nicht verwendete
  Ergebnisse`, `Net Income` → `Net Income (not yet closed to Retained Earnings)`. The label is part of
  the defect, not cosmetics: the position reports the result *not yet appropriated*, cumulative until
  somebody books the resolution, and a label promising "this year" is the sentence the report tripped
  over. A caller reading `label` sees new text; `key` is unchanged.
- **Books that already carry an appropriation entry will show different figures** — the ones they
  should have shown. Books that never booked one are unaffected in every figure.

No API change, no format change: the data format stays at **0.7** and the cross-test compares 107
byte-identical exports.

### Two guards, because neither alone would have caught it

`PackCompletenessTest` / `pack-completeness.test.ts` now require every `result_allocation` account to
sit in the position that carries the result — and in no other. Verified red against the old mapping in
both languages, with the identical message.

`de-profit-appropriation` drives the whole path over the **shipped** pack: two fiscal years, the
resolution booked with the date it is actually passed (which falls in the *following* year, and is why
`closeFiscalYear` could never do this on anyone's behalf). Every fixture covering SF-25 until now
brought a mapping of its own that got it right — which is exactly why a broken pack stayed green. The
lesson generalises: mechanism proven on inline data says nothing about the product data shipped with
it.

The manual gained the paragraph that was missing at `balanceSheet`. Before this release
`result_allocation` appeared in `docs/` not once, so the path existed and could not be found.

### The last mirror is retired

`pack-library/` was authored outside the repository and copied in by `make sync` (`rsync --delete`) —
the one mirror left after the conformance suite stopped being one on 2026-08-23. It cost exactly what
a mirror costs, and this release is the invoice: the defect above could not be repaired where it is
read. Source and repo copy were verified byte-identical before the switch;
`bin/sync-pack-library.sh` and the `sync` make target are gone. Several docs still claimed
`testing/testsuite/` was mirrored too, untrue since August 23 — corrected in the same pass.

## 0.13.0 — 2026-08-25

**What the library says about itself — and the guards that keep it honest.** The release began as
an embedding application's list and ended with the findings register empty: every open `SPEC-`
entry is decided. Two threads run through it. One is the **read side**: state summae held and did
not report — a tenant's whole configuration, who recorded a posting, an intra-community supply it
could not file. The other is the **contracts** that keep a published surface from lying: the input
contract now reaches *into* structures, the manual gate reaches the *vocabulary* and not just the
names, the audit trail is queried instead of materialised, and which VAT filing windows exist is
the pack's answer rather than the core's.

Three of those guards found defects the moment they were switched on — three silent inputs, 46
undocumented keys, and a capability an embedding had written off as impossible because one field
was published and never explained. That is the argument for guards, made by the guards.

### ⚠ What breaks

- **An undeclared key *inside* a structure is now `E_INPUT_INVALID`.** `netLines`, `lines`,
  `allocations`, `steps`, `rates` and the `voucher` object declare what is inside them, and
  `dimension` instead of `dimensions` on a posting line is refused rather than accepted-and-dropped.
  Same call the outer level made in 0.11.0: the ignoring was the defect. Errors name the path.
- **The `AuditTrail` port gains `find(criteria)`.** Only code that implements the port itself is
  affected — both shipped adapters and every projection are unchanged in behaviour.
- **`DatabaseTenantFactory` rehydrates a stored tax profile with `TaxProfile::restore`** instead of
  `fromData`. Relevant only to a caller that built one by hand.

**The data format stays at 0.7**, deliberately: no record gained a field and no export changed
shape. `packPolicy.vatPeriods` is pack *input*, whose modules declare their own version, and the
exported streams are byte-identical to 0.12.0 — the cross-test compares 107 of them.

### The tenant's configuration is readable (F-CORE-035)

0.12.0 gave the library a place to keep what a tenant is set up as — tax profile, dimension
master data, allocation scheme, imported mappings — and reported exactly one of the four back:
the tax profile, through `systemDescription`. The other three could be written and never read.

Storing them was half the fix. The seed rule from the same release is what makes the other half
matter: before it, an embedding passed its cost centres in on every open, so *its* copy was the
truth by construction. Since 0.12.0 the stored record wins and what a caller passes is ignored
from the second open on — summae's copy is the truth, the embedding's is a guess, and nothing let
it check. A screen with a cost-centre field could learn the accepted values only by posting and
reading `E_DIMENSION_INVALID`.

- **New projection `tenantConfiguration`.** No parameters: a tenant has exactly one configuration.
  Reports `taxProfile`, `dimensionTypes`, `dimensionValues`, `dimensionRules`, `allocationScheme`
  (raw, exactly as `setAllocationScheme` accepts it) and `mappings`.
- **It reports what is in force, not what is stored**, and the two places those differ are the
  point. `dimensionRules` — which accounts may not be posted without which dimension — are the
  pack's and stand in no record at all, so nothing could tell a form which field it must not leave
  empty. `mappings` lists the pack's alongside the imported ones; mirroring the record would
  answer "none" for a `de` tenant whose `balanceSheet`, `incomeStatement` and `cashBasisReport` all
  work.
- **Mapping identity only** (`id`, `kind`, `version`), never the positions. Those are the pack's,
  which the embedding pins and ships, and summae keeps no copy of it on purpose: two answers to
  "which rules is this tenant on" is one answer too many.
- **Identity is not repeated** — id, name, base currency and pack are `systemDescription`'s blocks
  and it already reports all four.
- A stored allocation scheme is reported **unapplied** when it has not been used yet. It may name
  production-cost treatments only the current pack answers, which is why `restoreAllocationScheme`
  defers applying it to first use; a projection is the wrong place to fail on that.
- New fixture `core/tenant-configuration`, red before the change in both languages, and the
  `tenant-configuration` regression scenario gained a final CLI invocation that reads the whole
  configuration back — the cross-process case no fixture can reach.

### The audit trail is audit-capable (F-CORE-014, F-CORE-036)

Four findings of one shape, from a pass over everything that touches the trail: it was written
well and could not be read, and two of its published claims were not held against reality by
anything.

**The completeness guard now runs against real persistence.** `AuditTrailContractTest` enumerates
all 32 state-changing operations from the *published* API surface and runs each for real — a strong
guard that was bound to `Tenant::inMemory` only, which is the one construction summae does not
ship. It therefore could not see the defect class that actually occurred: `DatabaseTenantFactory`
takes the `AuditWriter` as an **optional** argument and once left it off for three services, so the
tax profile, the asset events and the costing runs wrote no record at all behind a database while
every in-memory test stayed green (fixed in 0.12.0, unguarded until now). The enumeration moved to
one place per language (`audit-cases.ts`, an overridable `buildTenant`) and is bound twice:
`AuditTrailPersistedTest` and `packages/knex/test/audit-trail-contract.test.ts`. Wiring a new
service without its writer is red now — and every record those bindings assert has been through a
JSON column and come back.

**`auditLog` answers the question an auditor asks.** It took `from`/`to` and nothing else, so *what
happened to this posting*, *who touched this account* and *what did this user do* were not askable
through the API: the caller fetched the whole trail and filtered outside. New parameters
`objectType`, `objectId`, `actor`, `action` (AND-combined, absent filters nothing) and
`offset`/`limit` with `count` = matches **before** paging — the same contract `journal` already
publishes, in deliberately the same words. An absent `limit` still means everything from the offset
on; no default page size is invented. Additive: existing callers keep the shape they had.

**Creations record what was created.** The trail was inconsistent and nothing noticed: vouchers,
fiscal years, dimension types/values and costing runs wrote `{from: null, to: …}`, while accounts,
postings and partners wrote an empty diff — so the invariant `systemDescription` publishes ("actor,
timestamp, object *and before/after values*") was true of most records and not of all. All three now
record their identifying fields, and a guard asserts that **no** record carries an empty diff. The
diff is never a copy of the object: a posting's lines stay in the append-only journal, and
duplicating them would double the largest table in the system to create a second answer to what the
posting says.

**The published event list is guarded.** `systemDescription.auditTrail.events` is what an auditor
reads as "this is what the trail records" — a hand-kept literal that nothing compared to reality,
and one that had already fallen behind once (0.11.0). It is now held against the events the
operations actually write, in both directions and in both bindings. Closing it surfaced
`openItem/cancelled`: published, produced by `reverse` on a settled entry, and observed by no test.

New fixtures `core/audit-trail-creations` and `core/audit-log-filtered`, red before the change in
both languages. `docs/gobd-conformance.md` gains three rows and corrects a stale count.

### Four findings from the embedding application (F-TAX-014, F-CORE-037, F-IO-011)

The app's `FINDINGS.md` had four entries open. One closed with the tenant-configuration read side
earlier in this release; the other three are here, and the first of them was not the wall it looked
like.

**A consideration reduction reaches the VAT return through the ordinary API** (F-TAX-014).
`postVoucher`/`expandTax` take **`reduction: true`**: every line swaps sides — net, tax and the
gross contra line — and the tag carries a negative base, so the reporting key the supply was
reported under goes *down*. Same code, same rule version, same key; a reduction of a taxed supply
belongs in that key rather than in one of its own. A flag rather than a mechanism in the closed
repertoire, and deliberately: *whether* a cash discount, a credit note or a price reduction changes
the taxable base is jurisdiction law and the caller's decision (§ 17 UStG under the DE pack), while
*mirroring a taxed posting* is mechanism and identical everywhere.

The case was **already reachable**, which is the part worth stating plainly: a plain `post` with a
hand-written `taxTag` carrying a negative `baseMoney` does it, and `core/settlement-discount` has
pinned exactly that since v0.4. The application reported it as unbuildable because nothing said so —
`taxTag` appeared in the manual as "(object, no)", with no shape, no explanation that `vatReturn`
counts only tagged lines, and no word about the sign convention. That is a documentation defect
wearing a missing-feature costume. The manual now documents the field, and `reduction: true` exists
so nobody needs it: the old path asks the caller for the tag shape, the applied rule version and the
reporting key — library internals plus a German form number on an application's screen.

**`ecSalesList` reports what it cannot report** (F-IO-011). A supply whose partner has no VAT ID
produced no row and no warning: two postings, one with a VAT ID and one without, and the answer was
one row and nothing else. That is the dangerous direction — without the recipient's VAT ID the
supply is not exempt in the first place, so what dropped out was exactly the wrong case. New
`gapWarnings[]`, same shape as `vatReturn`'s, with `partner_without_vat_id` and
`supply_without_partner` told apart because the fix differs. The decision now happens on the
*line* — is this tagged as an intra-community supply — before the partner is looked at; deciding it
afterwards is what made a missing VAT ID indistinguishable from a posting that never was one.

**`journal` and `unfinalizedEntries` carry `actor`** (F-CORE-037) — who recorded the posting. The
entry has no author; the fact lives in the audit trail and nowhere else, so an application checking
**separation of duties** ("nobody may finalize a batch containing their own postings") read the
entire trail on every finalization and rebuilt the mapping itself: a check that scales with the age
of the books rather than the size of the batch. The trail stays the single source — the author is
deliberately *not* copied onto the append-only entry, where it could never be corrected. Honest
limit: building the map still reads the whole trail, because the audit port answers `all()` and the
store keeps `objectType`/`action` inside a JSON payload. The walk moved into the library, where one
map serves the whole projection; it did not become a database query, and making it one needs
indexed columns the idempotent installer cannot yet add.

New fixtures `tax/consideration-reduction`, `core/ec-sales-list-gap-warnings` and
`core/entry-author`, red before the change in both languages.

### The findings register is one file (docs)

`implementations/php/SPEC-FINDINGS.md` and `implementations/node/SPEC-FINDINGS.md` are now thin
pointers to a single [`SPEC-FINDINGS.md`](SPEC-FINDINGS.md) at the repository root. The
language-neutral findings had been living in both: seven `SPEC-` entries were byte-identical copies
and **SPEC-014 had already drifted** — the PHP copy carried the decision, its reasoning and a
related finding, the Node copy had shrunk to a summary ending in "full write-up on the PHP side".
A copy nothing compares drifts; that is the rule this project applies to every table and every
constant, and the file that records such failures had quietly become one. Nothing was lost in the
merge (verified by ID and by block), and which language a finding was found in is now a sentence in
the entry rather than a directory — most of them concern both.

Two findings from this release are recorded there:

- **SPEC-018 — the audit trail can only be read whole.** `auditLog`'s new filters and the author map
  behind `journal.actor` both run *after* `AuditTrail::all()`, because `objectType`/`action` live
  inside a JSON payload and nothing can be pushed down. The walk moved out of the embedding and into
  the library, which was the correctness half; the cost half needs indexed columns, and SPEC-014's
  idempotent install covers new *tables*, not new columns.
- **SPEC-019 — the documentation gate reaches names, not meanings.** `HandbookCoversTheApiTest`
  proves every published operation has a section; nothing proves a documented *field* says anything.
  `taxTag` stood in the manual as "(object, no)" and an embedding concluded that a capability
  working since v0.4 was impossible.

`docs/gobd-conformance.md` gains a row for **tamper evidence on the trail** — weighed and deferred,
with the reason — and loses a stale count that had claimed 25 audited operations where there are 32.

The register is also **split by state**: `SPEC-FINDINGS.md` holds the four open findings and
nothing else — 198 lines, short enough to read whole — while `SPEC-FINDINGS-RESOLVED.md` holds the
21 decided ones in full, with the status table over both. The reason is what happens when somebody
is told "we have open findings": reading the register should not mean carrying 1,600 lines of
settled history into the work. Closing a finding means moving its block across, which is the whole
bookkeeping — unlike the old split by *language*, a split by state cannot duplicate, because a state
changes once.

Resolved entries are kept rather than pruned, and the resolved file says why: 112 comments in the
source cite these IDs, the root `CLAUDE.md` uses `IMPL-025` as its worked example of the
jurisdiction litmus test, and `NoJurisdictionTextTest` explains itself with it. A resolved entry is
what "see IMPL-025" resolves to; delete it and the comment points at nothing. Keeping the text is
not the same as keeping it in the reader's way.

### The input contract reaches into structures (SPEC-017)

`api-parameters.json` declared every accepted key and checked it before routing — one level deep.
`netLines`, `lines`, `allocations`, `steps`, `rates` and the rest were declared `array` and nothing
looked inside the elements, so:

```json
{ "netLines": [ { "account": "6040", "money": {…}, "dimension": [ … ] } ] }
```

was accepted, booked correctly, and dropped the cost centre. Same defect as the one the contract was
built to end, one level in.

- **`element` and `fields`** on any declaration, checked by the same two rules as before: an
  undeclared key is `E_INPUT_INVALID`, a declared key of the wrong type is rejected rather than
  coerced. Requiredness stays with the operation, whose own error says more. Errors name the path —
  `post: unknown input "lines[0].dimension"`.
- **Recursive, not "one level deeper".** A fixed depth is a number somebody re-decides later; a
  recursion that stops where the *declaration* stops is a visible choice each time. `opaque` says
  the same thing with a reason, for a structure another schema owns (`importMapping.mapping` belongs
  to `format.schema.json`; a partner's `address` is free-form master data stored whole).
- **A guard makes it stay closed:** an `array` or `object` declaration with neither an inner shape
  nor an `opaque` reason fails the contract test, in both languages. Declaring today's inputs fixes
  today; without the guard the next array added would be structural and silent again.

**It found three silent inputs on its first run** — which is the argument for it, made by itself:
`lines[].openItem`, passed by fixtures since v0.2 and read by nobody (now declared without effect,
IMPL-029); a regression scenario declaring an overhead rate's `accounts` at rate level where the
parser reads `base.accounts`, so the rate had no base and the scenario still passed; and
`receivers[].costCenter` in both adapter suites where the parser reads `code`, so the receiver was
dropped and both tests stayed green.

**Breaking, deliberately:** a caller passing an undeclared key *inside* a structure now gets
`E_INPUT_INVALID` where it used to be ignored. Same call as F-9 made for the outer level — the
ignoring was the defect. New fixture `core/input-structure-contract`.

### The documentation gate reaches the vocabulary (SPEC-019)

The manual gate proved every published operation and projection had a section. Nothing proved a
documented **field** said anything — and `taxTag` stood in the manual as "(object, no)" for a year,
which is how an embedding concluded that a capability working since v0.4 was impossible and shipped
a screen without a discount field.

SPEC-017 closing made the fix possible: the contract now declares every input key at every depth, so
the manual is held against **the same list the dispatcher validates**. A declared key that is not
named in the section of the operation accepting it fails the build, in both languages. Still
deliberately weak in the same way the name check always was — appearing in prose is not being
explained well; it catches *declared, published, meaningless*, and leaves quality to review. One
exemption, kept as a list: `actor`, documented once for the whole section.

**It found 46 undocumented keys**, which answers whether `taxTag` was an accident: the entire
`voucher` vocabulary on `postVoucher` (`due`, `economicYear`, `issuer`, `kind`, `recurring`,
`serviceDate`, `servicePeriod`), every element key on `correct.lines`, the row fields of
`importChartOfAccounts`, `acquireAsset`'s `specialDepreciation` and `totalUnits`, `setTaxProfile`'s
`reason`, and the whole rate / production-cost vocabulary of `setAllocationScheme` — where
`base.accounts` lives, the key a regression scenario had been getting wrong for as long as nothing
documented it. All written now.

### The audit trail is queried, not materialised (SPEC-018) — **breaking for custom `AuditTrail` adapters**

`auditLog`'s filters and the author map behind `journal.actor` both ran *after* `AuditTrail::all()`,
so a question about one posting still read ten years of history. The port gains
**`find(criteria)`** — `objectType`, `objectId`, `objectIds`, `actor`, `action`, `from`/`to`,
`offset`/`limit`, returning the page and the count *before* paging — and the database adapters push
it into SQL.

**No column, no migration.** The four fields live inside the JSON payload and are read there
(`json_extract` on SQLite, `->>` on Postgres). Promoting them to columns is easy; *filling* them for
rows that already exist is a data migration, and neither language has a runner — an unfilled column
would make the filter miss exactly the history an audit is about. Extraction costs the index and
keeps correctness; an expression index is what remains, and it is now separable.

`EntryAuthors` asks for the ids on the page, so a journal view of forty postings reads forty
records. That was the half 0.13.0's first pass missed: it moved the embedding's walk into the
library without making it smaller.

**Two implementations of one rule, held together by a test.** SQL in the adapters, `AuditFilter` /
`applyAuditCriteria` in memory; `AuditQueryEquivalenceTest` and its Node twin drive every declared
filter — alone, combined, and at the paging edges — through both and compare. The empty id set is in
there deliberately: "these entries" with none of them is not "all of them", which is what a naive
`IN ()` answers.

**Breaking** only for code that implements the `AuditTrail` port itself: it needs a `find`. Every
projection, every fixture and both shipped adapters are unchanged in behaviour — 168 fixtures green
against the in-memory core, against SQLite and against Postgres, and the cross-test unmoved.

### The pack declares its VAT filing windows (SPEC-016)

Refusing an unknown `vatPeriod` — the right fix for the coercion defect — needed a list, and the
list was three constants in the jurisdiction-free core: `monthly`, `quarterly`, `yearly`. That is
the core saying *these are the filing periods there are*, and they are not. Ireland files
bi-monthly; several jurisdictions have four-monthly or half-yearly windows; some have no VAT at all.

**`packPolicy.vatPeriods`** now declares them, and **replaces** the substrate's list rather than
extending it — a pack whose jurisdiction has no quarterly filing does not get quarterly quietly
available. The substrate's three stay as a **default, not a definition**: they answer for a pack
that says nothing, which is what makes the change additive. No shipped pack had to change, no
inline bundle moves, and every tenant in the field behaves exactly as before.

The fallback for an absent `vatPeriod` follows whoever owns the list — the substrate default keeps
its documented `quarterly`, a declaring pack gets its own first window.

**`TaxProfile::restore` stops re-judging stored profiles.** The database factories rebuild a stored
profile instead of re-validating it: validation belongs at the boundary, and re-checking on the way
*out* of our own store would mean a tenant whose pack later drops a window can no longer be opened —
a rule change reaching backwards into books kept correctly under the old one. That hazard only
existed once the list became changeable.

Fixture `pack/conformance-xx/xx-7-pack-declares-filing-periods` (a fictional jurisdiction filing
bi-monthly, red before the change) plus `VatPeriodsFromPackTest` / `vat-periods-from-pack.test.ts`
for the half no fixture can express: a pack that *excludes* a window.

## 0.12.0 — 2026-08-25

**The second package an embedding application wrote** — and the round where our own tests were
found to have a blind spot they could not have covered. Eight of the nine entries on the app's
list are closed; the ninth is answered in the manual rather than in code, because a tenant
register is the embedding's and saying so is the deliverable.

Two of the defects were ours to find and had shipped: `postVoucher` dropped the cost centre of
every taxed line, and the CLI carried four configuration operations that reported success and
changed nothing that outlived the process. Neither could fail a fixture — a fixture builds one
tenant in one process, where an in-memory registry and a stored one are indistinguishable, and a
posting that loses a dimension still balances. That is a category gap, not a coverage one, and
closing it is why this release adds a scenario guard and two adapter suites rather than only
fixtures.

**Breaking:** `DatabaseTenantFactory.build` in Node takes `(db, clock, ids, options)`; `name` and
`baseCurrency` moved into `options` and became optional. In PHP they became optional leading
parameters, so existing calls keep working.


### The read side publishes what the write side owns

Four gaps of one shape, closed in one pass: state the library held and did not report, so an
embedding either could not build the screen or had to keep its own copy beside the books.

- **`costingRuns` — a new projection.** `costAllocationSheet`, `overheadRates` and `productionCost`
  all require a `runId` and nothing said where one comes from, so the only way to hold a valid id
  was to have kept the one `runCosting` returned. Reports what a run *is* (`runId`, `fiscalYear`,
  `period`, `version`, `status`, `method`), filterable by year and period, never what it computed.
  Reported as F-24.
- **`assetRegister` rows carry `specialDepreciation`** — `elected`, `allowance`, `remaining`. A
  screen can now tell which assets took the additional allowance instead of offering the form on
  every row and letting the engine refuse the rest. Reported as F-25.
- **`cashBasisReport` publishes `surplus`**, with `totalIncome`/`totalExpenses` beside it. The EÜR
  returned every figure it is built from and not the one it exists to produce, while
  `incomeStatement` hands out `netIncome` and `balanceSheet` both totals. Reported as F-20.
- **`systemDescription` reports the tax profile** the engine is running on. Second half of F-16.

All four are additive fields or a new name, so no existing expectation moves.

**One fixture was retired on the way** (`testing/testsuite/superseded.json`):
`system-description-current` pinned the full capability list, so summae could not gain a projection
without it going red — the same weld a shipped pack's version and account count were retired for,
one layer up. Completeness is guarded where it belongs, by the contract test that holds the
published list against the dispatcher's own routing table in both directions. The successor
`system-description-invariants` pins everything the description must be right about and leaves the
length of the catalogue to the product. The supersession guard now follows chains, since this is
the second retirement of the same ground.


### Two inputs that were accepted and quietly reinterpreted are now refused

Same class, one pass: a value the library could not make sense of used to become a plausible
default instead of an error.

**`TaxProfile` no longer coerces (F-TAX-003).** `taxationMethod` was `=== 'cash' ? 'cash' :
'accrual'` and `vatPeriod` was `=== 'monthly' ? 'monthly' : 'quarterly'`, so a typo, a `null` or an
object all arrived as a valid-looking profile that books differently. A misspelt taxation method
decided whether VAT falls due on invoice or on payment, silently. Both are now checked against
their documented values (`E_INPUT_INVALID`); an absent field still gives the documented default.
`vatPeriod` gains **`yearly`** — a caller who wrote it got quarterly. The field stays descriptive:
it names the window a tenant files in and selects none (`vatReturn` takes its own).
Reported as F-19 by an embedding application. The closed list of filing periods is itself a
jurisdictional claim in the substrate — deliberate for now, reasoned in **SPEC-016**.

**A partner's `accountNumbers` are checked against the chart (F-CORE-032).** A partner could be
linked to account 9999 in books whose chart stops at 3110: the operation succeeded, the link was
stored, and nothing ever reported it. Now `E_ACCOUNT_UNKNOWN` on `createPartner` and
`updatePartner` alike. Whole-list semantics unchanged — an empty list still clears the link. This
takes a refusal out of the embedding that had to write it in its own route with the chart already
in hand (its F-17), the same way `name` and `kind` moved here.

New fixtures `tax/tax-profile-input-validation` and `core/partner-account-link-validated`, red
before the change in both languages.


### `postVoucher` keeps the dimensions of its net lines (F-CORE-006)

The tax expansion rebuilt every input line as account/money/code, so anything else on it —
`dimensions` above all — was gone before the line reached the ledger. The posting succeeded, the
figures were right, the entry reached the journal with `dimensions: []`, and the cost centre had
disappeared with no error and no warning.

The case it broke is the ordinary one: **an operating expense with input tax is exactly how a cost
lands on a cost centre.** Only an untaxed posting — rent, wages, a bank charge — kept its
attribution, so cost accounting worked on the minority of postings. Reported by an embedding
application as its F-23.

- A net line's `dimensions` are carried onto the resulting posting line. The **derived tax line and
  the gross contra-account get none** — input tax belongs to the tax account and a payable to the
  creditor, not to a department.
- Passthrough, not interpretation: the ledger validates the values as it always has, so an
  undeclared cost centre is still `E_DIMENSION_INVALID` — now that the line arrives whole.
- Fixture `tax/voucher-net-line-dimensions` in both languages, covering all three: dimensions kept,
  absent dimensions not invented, unknown value refused.


### Tenant configuration is persisted (SPEC-015) — **breaking for direct factory callers**

Five operations changed configuration that no store kept: `setTaxProfile`,
`defineDimensionType`, `defineDimensionValue`, `setAllocationScheme` and `importMapping` returned
success, wrote a durable audit record, and lost the change with the process. Our own CLI shipped
with four of the five silently ineffective — it carried a hand-rolled write-back for
`importMapping` alone.

- **New table `summae_tenants`**, in both languages: the tenant's identity (id, name, base
  currency, pack provenance) plus its configuration. Added by the idempotent installer, so an
  existing workspace gains it without being recreated.
- **The stored record wins; what a caller passes is a seed** — written on the first open of a
  tenant with no row, ignored afterwards. An embedding no longer has to pass its cost centres in
  *and* declare them through `defineDimensionType`, which used to answer `E_DIMENSION_INVALID` for
  a code it was declaring for the first time.
- **`listTenants`** (Node: `@superheld/summae-knex`; PHP:
  `DatabaseTenantRecordRepository::listTenants`) answers which tenants a store holds, so an
  unknown `tenantId` is distinguishable from a new one. Not a projection — a projection is computed
  *on* a tenant, and this question has none to run on.
- **Breaking:** `DatabaseTenantFactory.build` takes `(db, clock, ids, options)` in Node; `name` and
  `baseCurrency` moved into `options` and are optional. In PHP they became optional leading
  parameters, so existing calls keep working.
- A stored allocation scheme is replayed **on first use**, not while the tenant is built: it may
  name production-cost treatments only the pack answers, and the pack arrives after construction.
- New guard `testing/scenarios/regression/tenant-configuration.json` — one CLI invocation per step,
  which is the only way this class of defect is visible at all. No fixture could ever have caught
  it: they build one tenant in one process, where an in-memory registry and a stored one are
  indistinguishable.
- Handbook: a new section states what summae stores and what the embedding stores.


## 0.11.0 — 2026-08-24

**The release an embedding application wrote.** Every item below started as a finding in the
`FINDINGS.md` of an app that builds screens on summae — fifteen of them, worked through in one
pass. That is the return on dogfooding, and it is also a verdict on our own tests: the worst of
the fifteen (**F-15**, below) left a credit balance standing on an asset account while every
invariant held, twelve asset fixtures stayed green, and nobody here had run a yearly depreciation
before a mid-year disposal.

Four more defects came out of the work that nobody had reported at all. summae's own audit-trail
test had been passing `usefulLifeYears` and `role: 'customer'` for months — neither key exists;
`systemDescription` under-reported what the audit trail records; the partner stream could not
validate against its own schema; and the cross-test compared against stale artifacts. Each of them
was invisible for the same reason: a contract surface without a guard of its own. Most of what this
release adds is that guard.

### ⚠ What breaks

- **The data format is 0.7.** The partner record gained `status` (`active`/`inactive`), so the
  schema moved and the version with it — `journalExport.manifest.formatVersion` and
  `systemDescription.formatVersion` now read `"0.7"`. An application that pins the format version
  has to follow, deliberately and visibly: that is what the field is for. Two long-standing schema
  defects in the same record are fixed on the way (see below), and older stored partners rehydrate
  as `active`, which is what they were.

- **`createPartner` requires a name.** It defaulted to `""`, so a request that forgot the name
  created a partner indistinguishable from the next one and impossible to pick out of a list. An
  empty or whitespace-only name is now `E_INPUT_INVALID`, and `kind` must be one of
  `customer`/`supplier`/`both` instead of any string. A caller that relied on either has to change;
  everything that already sent proper master data is unaffected.

### Changed

- **The DE pack can be extended without a statement losing the accounts** (`de@2026.4`). The trap
  was the finding, not the missing accounts: `de-guv` mapped position 6 by the **range** 6000–6099
  while `de-euer` position A5 listed explicit `numbers: ["6000","6700"]`, so an account added at
  6035 flowed into the income statement and vanished from the EÜR — same chart, same posting, two
  statements disagreeing, and nothing saying so. `de-euer` now maps by range like `de-guv`, carved
  around the three numbers the law treats individually (entertainment deductible / non-deductible,
  carrying amount on disposal). Seven operating-expense accounts follow (6030–6090: rooms, energy,
  telephone, vehicles, tools, insurance, bank charges), which is what an embedding app asked for and
  what is only safe *after* the mappings agree. Fixture `de-aufwandskonten-erweiterbar` pins the
  property rather than the account list. Superseded module files stay in `de-pack/versions/`, so
  `de@2026.3` keeps resolving to exactly what it always did.

- **The `de-pack` README stops overpromising SKR03/04.** "Remain loadable via
  `importChartOfAccounts`" was true in the narrow sense — the accounts get created — and false in
  the sense a reader takes: all three mappings reference *this* chart's numbers, so an SKR03 tenant
  gets a balance sheet, an income statement and an EÜR that find almost nothing. The README now says
  what the limitation is, and the extendable bands are documented alongside it.

- **The cross-test no longer compares against stale artifacts** (IMPL-028). `cross-export` wrote
  into `.cross-dbs/` without clearing it, so a fixture that stopped being exported left its database
  and its oracle behind and the read side kept comparing against them. It surfaced when the format
  moved to 0.7: three retired fixtures' old oracles still said 0.6 and failed a run that no longer
  happens. The export starts from an empty directory now.

- **Three fixtures superseded, none edited.** `de-jahresgang` and `de-wertpapierdepot` pinned
  `accountCount: 41` in passing — the same weld `de-pack-resolves` was retired for, and the reason
  the chart could not grow. `de-euer-mapping-gap` is the rarer case: the mechanism it pins (an
  account the mapping does not know is *reported*, not filed silently) still holds, but its example
  account 6050 became a mapped account when the pack grew. Its successor pins the same mechanism on
  a number that lies outside every DE mapping by design. Reasons in
  `testing/testsuite/superseded.json`.

### Added

- **Costing runs are persisted** (F-KLR-001/004). `CostingService` kept its runs in a private `Map`,
  so a run created in one process was gone in the next — and an application that builds a tenant per
  request could release a run and never read it again, while `costAllocationSheet` needs the runId.
  The requirements had said otherwise all along: runs are versioned per period, and the BAB and the
  rates are a projection *of a released run*. A run no later process can read satisfies neither.
  There is now a `CostingRunRepository` port with an in-memory and a database implementation in both
  languages, a `summae_costing_runs` table, and the per-period version comes from the store — it used
  to restart at 1 after every restart, so the second run of a period claimed to be its first.

- **The schema install is idempotent** (SPEC-014, decided). Both adapters created their tables
  exactly once at workspace initialisation and nothing upgraded an existing database — the first
  change that added a table would have meant recreating every workspace. `create` is now "ensure",
  guarded per table, and the costing table reached existing workspaces without one. The limit is
  stated rather than hidden: it covers **additive** changes and nothing else; a column that changes
  its type still needs a real migration, which neither language has.

- **A partner can be marked as no longer in use** (`deactivatePartner` / `reactivatePartner`,
  F-CORE-034). There is no `deletePartner` and there should not be — the books keep what they
  referenced — but a partner nobody trades with any more had nowhere to be recorded, so every
  application invented its own inactive-list beside the ledger. It is a **state, not a control**:
  an inactive partner refuses nothing, its open items still settle and a posting that names it is
  not rejected. Whether a picker still offers it is the application's workflow, which is also why
  the word is not "lock" — `lockAccount` does refuse postings, and the difference is deliberate.
  Both directions are audited with the status diff.

- **Two schema defects in the partner record, both latent, both fixed** (IMPL-027). The format
  declared `accountIds` (uuids) while the engine writes `accountNumbers` (strings), and
  `journalExport` wrote partners **without** stripping nulls, unlike accounts and vouchers — so a
  partner with no VAT id exported a `null` where the schema demands a string. Either one would have
  made a GoBD Z3 export fail its own schema; neither ever did, because no schema test had exported
  a partner. One does now, in both languages, and it exports the leanest partner there is.

- **`journal` — a journal read that is both cheap and lossless** (F-CORE-031). The plainest view a
  bookkeeping application has had two bad ways to be filled: `journalExport` is lossless and builds
  five streams with a SHA-256 each, with no date window and no paging — an archive format paid for
  on every page load; `datevExport` has the window and the weight but is DATEV-shaped and therefore
  **lossy for split entries**, so an expense with its input tax collapses into one row and the tax
  line disappears. The new projection takes `fiscalYear` with an optional `fromDate`/`toDate` window
  and `offset`/`limit`, and returns every line of every entry with account number *and* name.
  Paging counts **entries**, not lines — a page boundary inside a split entry would reproduce the
  very defect it exists to avoid — and `count` names the total in the window before paging, so a
  page header no longer costs an export.

- **`vatReturn.gapWarnings` — the silent tax gap says something now** (F-TAX-013). The return is
  built from tax-*coded* postings, so a hand-written `expense / input tax / bank` entry balances,
  satisfies every invariant, shows correct figures on the accounts and in the trial balance, and
  contributes nothing to the filing. The books looked right everywhere except the one place that
  decides what is filed — an embedding application's seed script fell into it on the first attempt.
  Every line in the filing window that touches a `tax_in`/`tax_out` account without a tax code is
  now listed with its posting, account, side and amount. Reported, never blocked: a correction
  posting legitimately touches those accounts, and refusing it would stop the repair along with the
  mistake.

- **`unlockAccount`** (F-CORE-033) — `lockAccount` had no counterpart, so a mis-clicked lock could
  only be repaired by abandoning the account and opening a second one under a new number, leaving
  the old one in the chart forever and moving nothing that was posted on it. The question that
  decided this was whether the irreversibility was *law*: it is not. What the German rules protect
  against unrecognisable change are **postings**; for master data they ask that the change be
  *logged* — which the audit trail does, in both directions, and no other jurisdiction answers it
  differently either, so nothing about it belongs in a pack. The lock keeps its teeth while it
  lasts, and unlocking changes nothing about the past. Both directions are audited (`locked` /
  `unlocked` with the status diff).

  Found on the way, and fixed with it: `systemDescription` under-reported what the audit trail
  records — five asset actions and both dimension object types were missing from
  `auditTrail.events`, so the description a Verfahrensdokumentation quotes claimed less than the
  software does. And the audit-completeness guard kept its own hand-written list of mutating
  operations, which had fallen behind by the same seven names as the published surface; it now
  reads `API_OPERATIONS` and covers all 32.

- **Partner master data can be corrected, not only entered** (F-CORE-032). Three gaps that only add
  up once a screen maintains partners: `accountNumbers` and `address` were create-only, so a wrong
  account link was permanent — the partner had to be abandoned and recreated under a new id while
  every open item stayed on the old one; `paymentTermsDays` could be set and never cleared, because
  it was read with a number check while `vatId` had always accepted `null` to clear — two fields,
  two behaviours, nothing saying so. `updatePartner` now takes `accountNumbers` and `address`
  (replacing wholesale, not merging) and `paymentTermsDays: null` clears the term. Still no
  `deletePartner`, deliberately: books keep what they referenced.

- **Operations declare their inputs, and an undeclared one is refused.** `PROJECTION_PARAMETERS`
  had declared every projection parameter since 0.7 while `execute()` read what it recognised out
  of an operation's input and ignored the rest. The asymmetry was the wrong way round: a typo in a
  read failed loudly, a typo in a **write** was silence plus a default — and the write is the one
  that ends up in the books. `testing/testsuite/schema/api-parameters.json` now carries an
  `operations` block next to `projections`, both languages hold it as a constant, a drift test per
  language asserts the two are equal, and `TenantOperations::execute` validates before routing. An
  undeclared input is `E_INPUT_INVALID`; a declared one of the wrong type is rejected rather than
  coerced; absent keeps its documented default and `null` counts as absent.

  Three defects had already come out of that silence, and the contract catches all three shapes:
  `usefulLifeYears` instead of `usefulLifeMonths` (accepted, ignored, the pack's lookup stayed in
  charge), `"30"` instead of `30` (not rejected but *dropped*, the default stood), and
  `proceeds: 2000` instead of Money (read with an is-object check, so a sale booked as a
  scrapping). Two of them were live **in summae's own audit-trail test**, which had been passing
  `usefulLifeYears: 5` and `role: 'customer'` for months; the new contract turned it red on the
  first run.

  Requiredness is deliberately not enforced here: an operation missing its subject already answers
  with `E_VOUCHER_UNKNOWN` / `E_ASSET_UNKNOWN` / `E_ENTRY_NO_VOUCHER`, which says more than a
  central `E_INPUT_INVALID` would.

  Two things the declaration itself uncovered: `reverse` accepts a `voucherId` that the manual
  never mentioned (documented now), and `setTaxProfile` accepts a `reason` that nothing records —
  declared as accepted-without-effect rather than quietly dropped, the way
  `importChartOfAccounts.format` already is.

- **Three reads the write side already owned.** An embedding application kept arriving at the same
  wall: it could change something and could not show it. Each time the only honest test left was to
  trigger a refusal and read the error code.
  - **`accounts`** (F-CORE-028) — the chart of accounts as a screen can afford to read it: number,
    name, type, `subtype`, `status`, ordered by number, no balances and no hashes. `subtype` is what
    identifies an account's role (bank, cash, receivable, payable) and lived only in
    `journalExport`, behind five streams with a SHA-256 each; `datevExport({kind:'accounts'})` is
    cheap but DATEV-shaped and stops at number, name and type. Applications were reading the
    **pack** instead — the chart the tenant started from, which one `createAccount` makes wrong.
    `status` is the read side of `lockAccount`, which had none.
  - **`fiscalYears`** (F-CORE-029) — years and their periods with `start`, `end` and `status`, the
    read side of `closePeriod` / `reopenPeriod` / `closeFiscalYear`. `auditLog` recorded every
    close, but a trail is not a state: replaying it makes an application rebuild library state from
    a log, and it is wrong the moment a period is closed elsewhere. The dates are what make a period
    *list* possible — a fiscal year running July to June has no twelve calendar months to invent.
  - **`openItems.partnerName`** (F-CORE-030) — the list could name the invoice and not the customer,
    and resolving the id meant a second projection inside one view. It is the name the partner has
    *now*, read from the master record: a renamed customer must not be dunned under its old name.

- **Cost allocation can solve mutual services (`method: "simultaneous"`).** The step ladder
  allocates in one pass and therefore cannot describe cost centres that serve each other — the power
  plant heats the workshop, the workshop maintains the power plant. Ordering the two is not a
  modelling choice but a wrong answer, which is why the step ladder refuses a cycle outright. The
  simultaneous-equation method solves all centres at once instead (x = p + Aᵀx), so a cycle is the
  ordinary case; only a *closed* one, where cost never reaches a centre that keeps it, is refused
  (`E_COSTING_UNSOLVABLE`). This closes the part of F-KLR-003 that was never built.

  Two details it needed and did not get for free. The elimination runs on exact fractions
  (`Rational`, new in the substrate), not on decimals: a solved share is routinely a fraction with
  no decimal form, and a solver that rounds mid-computation gives an answer that depends on where it
  rounded — something two implementations cannot agree on by construction. And turning the solution
  back into money is one largest-remainder step over the whole vector rather than one rounding per
  centre, so the sheet still says that allocation distributes and never creates.

  The direct method (*Anbauverfahren*) needs no mechanism of its own: it is the step ladder with a
  scheme that leaves the auxiliary-to-auxiliary edges out.

- **The cost allocation sheet says which method produced it.** Two procedures answer the same
  question differently; a sheet that does not name one cannot be checked against anything.

- **`overheadRates` — the calculation rates of a costing run.** F-KLR-004 asks for the allocation
  sheet *and* the calculation rates; only the sheet existed. Where the sheet says what a cost centre
  ended up carrying, a rate says how that attaches to a product. The numerator is the centre after
  allocation, the denominator is declared per rate as direct-cost `accounts`, other `costCenters`, or
  both — one primitive that covers the classic set without a special case, since cost of production
  is simply "the direct-cost accounts plus the two production centres". Direct costs are read per
  account rather than through the `costCenter` dimension, because that is what they are: costs of the
  product, not of a department.

  Rates are frozen into the run, so changing the scheme afterwards cannot change what a released run
  says. A rate whose base came out zero is `null` and the centre is named in `warnings` — undefined
  is not `0.0000`, and a zero returned there would be applied to products as though it meant
  something.

- **Declining-balance depreciation can now tell asset classes apart.** A pack may have two
  declining-balance regimes in force at once — Germany runs one for movables and another for new
  residential buildings over overlapping windows — and the core took whichever entry came first
  in the file. A building therefore inherited the movables entry, and the damage is not what the
  headline rates suggest: at a 400-month life the movables cap of 30 % never binds, so the rate
  came out at 3 × 2.9412 % = 8.8235 % and the first year booked 35,294.00 where 20,000.00 was
  due. No error, no crash, 76 % too much depreciation.

  A `decliningBalance` entry may now name the `assetClasses` it covers, and **a class-specific
  entry wins over a general one regardless of file order** — order-independence being the point,
  since a rule that changed meaning when someone appended a line above it would be a trap. An
  entry without the field still covers every class, so every pack written before this keeps
  computing exactly what it did.

- **The `de` pack carries § 7 Abs. 5a EStG**: 5 % of the residual value for new residential
  buildings acquired between 1 October 2023 and 30 September 2029. The useful life comes with the
  acquisition rather than from the pack's table, deliberately: for a residential building the
  straight-line rate depends on the year of completion, and the table has no date dimension, so
  one tabled figure would be wrong for every older building.

- **`productionCost` — what inventory may be carried at.** The one cost-accounting figure with
  balance-sheet effect, and the first module kind that is purely a legal table: the core adds
  components up, the new `productionCost` pack module says which ones **must** be capitalised, which
  **may** be (the preparer's election), and which **must not**. The `de` and `us` packs agree on full
  absorption of production cost and disagree about general administration — so identical books value
  at 126,000.00 under one and 114,000.00 under the other, with no branch anywhere in the core.

  Every configured component comes back, including the excluded ones, with its treatment and whether
  it was counted: a valuation that shows only its own total cannot be checked against the rule it
  claims to follow. Three refusals replace silent answers — an undeclared component
  (`E_PACK_INCOHERENT`), electing a forbidden one (`E_INPUT_INVALID`), and asking for the figure
  without configuring it. What it deliberately does not do is divide by a quantity: per-unit cost
  needs produced quantities, and the core carries none.

- **The `de` pack can record an exempt export (`AUSFUHR`).** The `exempt` mechanism has existed since
  0.5.0 and the German pack had no code using it: a business selling outside the EU had the
  intra-community supply and nothing for Switzerland or the United States — a different exemption on a
  different line. It reports under Kz 43 rather than Kz 41 and, unlike `igL`, stays out of the EC
  sales list, where an entry would be a false statement rather than a cosmetic error.

  Recorded, not resolved: SPEC-013. The German chart has an account for exempt intra-community
  supplies and none for exempt exports, and the pack cannot simply gain one — `de-pack-resolves` pins
  the number of accounts the shipped chart has. The fixture shows the `createAccount` a user has to
  do today.

- **The `de` pack can record an intra-community acquisition (`IGE19`, `IGE7`).** It shipped `igL` for
  the selling side and nothing for the buying side — the more common case for most businesses — so a
  German company buying goods from another member state could not record the transaction at all.

  The engine never needed anything: an acquisition is structurally § 13b, tax and input tax arising
  together and cancelling, and `reverse_charge` has expressed that since 0.5.0. What held it up was a
  misreading recorded in the backlog — that the acquisition has no separate figure for the *tax* (Kz
  89 carries the base and ELSTER computes 19 % of it) while the model appeared to require one. It does
  not, and the pack already proved it: Kz 81 for ordinary sales is likewise a base-only figure used as
  `reportingKey`. Kz 89 for 19 %, Kz 93 for 7 %, input tax on Kz 61, and the return comes out
  payload-neutral, which is what an acquisition with full deduction is.

- **`reportAssetUsage` — depreciation by output (`units_of_production`).** An asset that wears by use
  rather than by time — a lorry, a press, a copier — may in some jurisdictions be written off along
  its actual output, and that changes what a plan can be: the number comes from goods movements and
  meter readings that are not in the books. Such an asset has no schedule and `runDepreciation` passes
  it by; the caller reports the meter instead.

  The arithmetic is cumulative, which is the part that matters. Each report splits the cost between
  what the asset has now given and what it has not, and books the difference against what is already
  written off — so the report that reaches the total output lands on the cost exactly, where
  period-by-period rounding would drift. Outliving the estimate is not an error: the booking is capped
  at the book value and says `capped`. Once written off, further output is refused rather than booked
  as a silent `0.00`.

- **`bookSpecialDepreciation` — an additional allowance next to the plan.** Some jurisdictions let a
  business deduct an extra share of an asset's cost within its first few years, freely distributed
  over them (Germany: § 7g Abs. 5 EStG, 20 % until 2023 and 40 % from 2024, over five years — now in
  the `de` pack). It is not a depreciation method, which is why it could not be expressed before: the
  ordinary plan runs on unchanged on the original basis while the window is open. It is a budget, and
  the split is the taxpayer's, so it is an operation rather than a schedule.

  The part that had to come with it is the re-basing. When the window closes, part of the cost has
  left the plan and the plan would keep asking for its original yearly amount — removing that step
  turns the fixture's asset account to −12,000.00. The remaining book value is spread over the plan
  months still open, using the same code a write-down uses; two spreadings that drifted apart would
  be two answers to one question.

  What the core does not do is check entitlement. A profit limit and a share of business use are
  facts about the business, not about the books; it enforces the budget and the window, which is what
  it can actually know.

- **`writeDownAsset` — unplanned write-downs (impairment).** The planned schedule answers wear and
  tear and has nothing to say about a machine damaged in March. Where the loss is expected to last,
  writing the asset down is an obligation, not an option, and the only ways to express it were
  disposing of an asset that still exists or posting by hand past the asset register — after which
  the register and the ledger disagree about what the asset is worth.

  The part that is easy to leave out is what happens next: **the remaining plan is rewritten**, so
  what is left is spread over the plan months still open. Leaving the plan alone depreciates past
  zero (removing that re-spread turns the fixture's asset account to −1,800.00); stopping it finishes
  the asset early. A `reason` is required — an unplanned write-down that does not say why is not
  auditable.

- **The constraint policy kind has a pack socket (`constraint` module).** summae has always described
  itself as substrate plus three policy kinds — constraint, projection, expansion — and only two of
  them could be plugged from a pack. A jurisdiction could contribute rules and views but never a
  *prohibition*, so any rule that is a constraint had to go into the core, against the whole split.

  The mechanism existed: `DimensionRegistry` enforces mandatory dimensions per account range, and it
  was reachable only by constructing a tenant in memory — a tenant built from a pack got a registry
  with nothing in it. A `constraint` module now carries `dimensionRules`, and several such modules add
  up rather than replace, so module order in a manifest stays meaningless.

  One predicate is not a general socket, and the GoBD census says so: the shape is settled, the
  vocabulary is not. A pack still cannot express a rule about a settlement or a deadline.

- **`defineDimensionType` / `defineDimensionValue`.** Dimension master data was declarable only
  through the in-memory construction path, so **every tenant created from a pack started with an
  empty registry and rejected any posting carrying a cost centre** — cost accounting was unreachable
  on `de`, `us` and `default` alike, and nothing in the packs said so. Cost centres are the tenant's
  master data, not a jurisdiction's, so they are declared like accounts rather than shipped in a pack.

- **`contentDigest` on `resolvePack` and on a tenant's `pack`.** A SHA-256 over the canonical JSON of
  the whole resolution, byte-identical in both implementations. Two tenants carrying the same digest
  run on the same rules whatever their labels claim; the same version showing two digests means a
  published version changed underneath somebody. It is derived, so unlike a hand-written version
  number it cannot be forgotten — which is the whole reason it exists (see *Fixed* below).

### Fixed

- **Seven routed capabilities were not published, and nothing asked.** The dispatcher answered
  `writeDownAsset`, `bookSpecialDepreciation`, `reportAssetUsage`, `defineDimensionType`,
  `defineDimensionValue`, `overheadRates` and `productionCost` — all finished, documented and
  fixture-covered — while `systemDescription` named none of them. The contract test only ever asked
  one direction (every published name resolves to a handler), so a surface larger than its
  declaration passed a green suite in both languages. For an embedding application that validates
  its calls against the published list, an unpublished operation does not exist: the app that
  reported this had no außerplanmäßige Abschreibung, no Sonderabschreibung and no Leistungs-AfA on
  its fixed-asset screen, because the three operations were unreachable by contract.

  All seven are now published, and the contract test compares **both** directions in both
  languages — it reads the dispatcher's own source for the routed names, because a `switch`/`match`
  has no runtime shape to enumerate. The `system-description` fixture carries the larger surface, as
  it does whenever the API grows: a description that does not mention a capability the software has
  lies by omission.

- **The manual was missing four published names.** `cashJournal`, `unfinalizedEntries`,
  `systemDescription` and the `allocate` operation had no section in `docs/handbuch/README.md` —
  published, tested, and undiscoverable. All four are documented now, and a new guard in both
  languages fails the build when a published name has no heading in the manual. Coverage of the
  documentation, next to the walkthrough scenarios that already gate its correctness.

- **A yearly depreciation run before a mid-year disposal left the asset account below zero.**
  `runDepreciation({ fiscalYear })` books the whole year in one entry dated 31 December. A disposal
  on 30 September then read the carrying amount *as of the disposal date*, treated that entry as
  later than itself, and wrote off the full acquisition cost — on top of what the run had already
  written off. The books balanced, every invariant held, and the asset account carried a credit
  balance that nothing ever cleared. The disposal now reads the carrying amount from the whole
  ledger, like every other caller in the service: what leaves the account equals what stands on it
  (F-AST-004, IMPL-026). Found by an app embedding summae, not by our own suite — twelve asset
  fixtures, and none of them had ever run the two in this order. Fixture
  `disposal-after-yearly-depreciation`.

  The disposal year's depreciation is deliberately **not** re-apportioned to the disposal month: the
  year keeps its twelve months and the disposal takes the difference into its result. The income
  statement carries the same total either way, and whether the year of departure grants a full year,
  a half year or nine months is a jurisdiction's answer that belongs in a pack (same reasoning as
  IMPL-022).

- **A published pack version was not immutable.** `de@2026.2` named at least three different bundles:
  the manifest kept its version while `de-ust` moved 2026.2 → 2026.4, `de-afa` 2026.5 → 2026.7 and a
  whole module joined, and the old module files were overwritten rather than kept alongside. Whoever
  pinned that version got different books depending on the day they installed. `us@2026.2` had the
  same defect.

  The version could not move because three conformance fixtures pinned it — and pinned the account
  count with it, so the shipped charts could not grow either (SPEC-012 and SPEC-013, which turned out
  to be one defect). Those fixtures are **superseded**, not edited: the files stay byte-identical,
  `testing/testsuite/superseded.json` names the successor and the reason, and both runners skip them.
  Their successors pin the behaviour — the pack resolves, a tenant is built, the posting comes out
  right — and leave the product's numbers to the product. What they pinned about the *mechanism* moved
  to `xx-6-pack-version-pinning`, which brings its own pack and is frozen for good.

  Selecting a manifest is now one function in the core (`PackResolver::findManifest`), called by the
  runner and by `summae init` alike, and a request without a version resolves to the **highest**
  version rather than the first match — so old versions can live in the library beside new ones, and
  the answer does not depend on directory order. `de` and `us` are now `2026.3`.

  **Breaking for anyone pinning a version:** `resolvePack({ manifest: "de", version: "2026.2" })` now
  fails with `E_PACK_UNRESOLVED_REF`. That version was never reconstructed from today's modules — a
  frozen file claiming to be the old bundle would be a second lie on top of the first. Immutability
  starts here.

- **A database-backed asset never saw a change to its own master data.** `save()` wrote the state
  and never the payload, which was safe exactly as long as nothing in the payload could change. The
  depreciation schedule lives there, so the first operation that rewrites it — the write-down above —
  would have left a database tenant booking the old plan while the in-memory one booked the new: same
  input, two sets of books. Caught by `make fixtures-db`, which exists because of the audit-writer
  defect in 0.10.1 and has now paid for itself twice.

- **`setAllocationScheme` refused to admit it could not do what was asked.** It read `method`,
  echoed it back in its answer and then ignored it entirely, so asking for anything other than the
  step ladder returned step-ladder numbers labelled with the name of a different procedure. That is
  the worst shape a defect can take, because the reply asserts it did what was asked. An unrecognised
  method is now `E_INPUT_INVALID`, with the ones this core performs named in the message.

- **The v0.10.0 release notes now carry a warning.** That version's PHP `summae-laravel` package
  builds a database-backed tenant without an audit writer. Packagist has no per-version
  deprecation, so the warning sits where someone landing on that version actually reads it. The
  npm packages of 0.10.0 are byte-identical to 0.10.1 and are not affected.

## 0.10.1 — 2026-08-23

One fix, and it is the kind that only shows up where it matters: **with a real database, three
services wrote no audit records at all.**

### Fixed

- **`DatabaseTenantFactory` built the tax, asset and costing services without an audit writer.**
  All three take it as an *optional* argument, so leaving it off compiled, ran, and produced a
  tenant whose tax-profile changes, asset events and costing runs left no trace — while the
  in-memory tenant, which every unit test and every default fixture run uses, recorded all of
  them. The audit trail was thinner in exactly the setup you run in production, and nothing said
  so. `auditLog` returned 4 records where 6 were expected.

  This is GoBD-relevant: the trail is the centre of the immutability requirement, and "every
  state-changing operation leaves a trace" is a ✅ row in the conformance document. It held for
  the in-memory core and not for the adapter.

- **`taxRoundingGranularity` could not be passed to the database factory at all.** A pack asking
  for per-line rounding got per-voucher rounding as soon as it ran against a database — the two
  setups would have computed different tax from the same input. The default is unchanged, so
  nothing moves for a caller who does not pass it; what changes is that passing it is now
  possible.

### Changed

- **`make check` now runs what CI runs.** It claimed to and did not: the conformance suite ran
  in-memory only, and without `--strict`. The database run existed solely in the CI workflow, so
  a defect living in an adapter's own wiring could not be seen locally — which is exactly how the
  bug above survived. `make fixtures-db` is available on its own; `make fixtures` stays the quick
  loop for development.

  The lesson generalises beyond this bug: a fake-backed test cannot check what an adapter does
  when it builds its own object graph.

## 0.10.0 — 2026-08-23

A legal-conformance review against case law and current tax rules, then everything it found.
Six defects, and every one of them the quiet kind again: no crash, no error, just wrong numbers
in a direction that flatters the result. Depreciation carries most of it, the shipped `de` pack
carries the rest — and the pack turned out to be the more embarrassing half, because the engine
was right and the product data was not.

Each fix was verified by removing it again: 15 mutations, 15 red tests. A fix nobody can break
on purpose is not protected.

### ⚠ What breaks

- **A pack with a pool range must now declare `poolProRataInFirstYear`** next to `poolYears` and
  `poolReducedOnDisposal`, or you get `E_PACK_INCOHERENT`. The shipped `de` pack says `false`.
  Required rather than defaulted, for the third time in the same file and for the same reason:
  whether a pool's first year is shortened by the acquisition month is one jurisdiction's answer,
  not a property of pooling.
- **Pooled low-value assets acquired mid-year depreciate differently.** They used to be spread
  pro rata from the acquisition month; they are now dissolved in equal fiscal-year fractions.
  An asset bought in November moves from 2/60 of its cost in the first year to a full fifth, and
  the pool no longer runs into a sixth fiscal year. The old numbers were wrong, but they were
  your numbers — re-check any year in which you pooled an asset outside January.
- **All depreciation changes where the fiscal year is not the calendar year.** See *Fixed*
  below; if your fiscal year runs January to December, nothing here moves.
- **The DATEV batch marks reversals.** Rows gain `generalReversal`, and a reversal is no longer
  byte-identical to the posting it cancels. If you import our batches, re-import any period
  containing a reversal — the old file doubled the turnover instead of clearing it.
- **`reverse` now uses a `voucherId` you pass it.** It used to discard it and inherit the
  reversed entry's voucher. Passing none still inherits, so only callers who were already
  sending one are affected — and they were sending it because they wanted it used.
- **The `de` cash-basis mapping gained positions.** Small-business revenue, non-deductible
  expenses and the carrying amount on disposal now have their own lines (E5, A7, A8) instead of
  appearing under raw account names. Totals are unchanged; the grouping is not.

### Fixed

- **A pooled asset was dissolved pro rata instead of by fiscal year.** The core laid out
  `poolYears × 12` plan months starting in the month of acquisition, which is right for ordinary
  linear depreciation and wrong for a pool: where a jurisdiction dissolves it in equal
  fiscal-year fractions, the first year is not shortened and the term ends after `poolYears`
  years. Acquired 15 November, an asset wrote off **30.00 instead of 180.00** in its first year
  and carried the remainder into a sixth year that does not exist. Directional and silent: too
  little expense, too much profit, for the whole term. Every existing pool fixture acquires on
  1 January, where both models agree — `gwg-pool-period` even says so in its own description,
  having put the date there deliberately to isolate a different question. Fixture
  `gwg-pool-fiscal-year-fraction`.
- **Depreciation followed the calendar year, not the fiscal year.** The yearly run grouped plan
  months by calendar year and then matched that against the requested fiscal year. With a fiscal
  year 07/2026–06/2027 and an asset acquired in September 2026, the 2027 run booked the eight
  months of calendar 2027 — two of which belong to the *next* fiscal year — and the four months
  September to December 2026 **were booked by no run at all**, because no fiscal year is labelled
  2026. The asset kept a carrying amount permanently and profit was overstated for as long as it
  did. `deviating-fiscal-year` existed but only posts; it never ran depreciation. Fixture
  `depreciation-deviating-fiscal-year`.
- **A reversal was invisible as a reversal in the sheets people read.** `cashJournal` and
  `accountSheet` showed it as an ordinary opposite movement, so a reader saw +100.00 and −100.00
  and could not tell whether a mistake was corrected or a genuine receipt removed. That
  distinction alone decides whether books are formally sound, with no proof of manipulation
  required (BFH 29.07.2025, X R 23/21 and X R 24/21). Worse than it sounds, because this core
  reverses by *general reversal*: the cash book showed a negative amount on the debit side with
  nothing explaining it. Both views now carry `reversesEntry` / `reversedByEntry` as journal
  numbers. Fixture `reversal-visible-in-sheets`.
- **A reversal in the DATEV batch was a second copy of the original.** The row took its amount
  through `abs()` — correct, the batch carries "Umsatz ohne Soll/Haben-Kz" — and read the
  indicator from the unchanged lead line. Against general reversal the two cancelled out, so an
  import **doubled the turnover instead of clearing it**. Fixture `datev-general-reversal`.
- **`reverse` discarded a `voucherId` given to it**, silently: no error, no hint, and a posting
  pointing at the wrong document. Inheriting remains the default. Fixture `reverse-own-voucher`.
- **Three gaps in the shipped packs, none of them visible to any test.** `de-euer` assigned no
  position to four of its own accounts — including **4040, small-business revenue**, which is the
  most likely account a cash-basis filer uses, so the combination the pack exists for was the one
  it got wrong. `us-schedule-c` left one. And **not one `de-ust` tax code carried a `datevBu`**,
  so every exported batch line lost its tax entirely. Three fixtures existed around the first
  one and none could see it: `de-kleinunternehmer` posts to 4040 but checks only the trial
  balance, `de-euer` checks the statement but never touches 4040.
- **The cash-basis statement reported its gaps silently.** An account with no position falls back
  to its own name, which keeps the money visible — but this is the statement that gets copied onto
  an official form, and the income statement had warned about its gaps for ages while this one did
  not. The statement *without* diagnostics was the one that goes to the tax office. It now returns
  `gapWarnings`; nothing moves and totals are unchanged. Fixture `de-euer-mapping-gap`.
- **`openItems` published neither the partner nor the due date.** `partnerId` was accepted as a
  *filter* and dropped from the result, so a list could be narrowed to one debtor and then could
  not say which; the due date sits on the voucher, which the projection already loads to read the
  voucher number off it. Without it no maturity schedule can be built — and remaining terms are a
  disclosure obligation, not a convenience. Fixture `open-items-partner-and-due`.

### Added

- **Declining-balance depreciation, with the switch to straight line.** One calculation serves
  several rules, so the mechanism is in the core once and the numbers are pack data: factor, cap,
  and above all the validity window, which is the shortest-lived number a pack holds. Each year
  takes a percentage of what is left; the switch is taken automatically at the first year where
  straight line over the remaining life yields more, and the final year takes the remainder so the
  schedule sums to the cost exactly. `de` ships factor 3 capped at 30 %, for acquisitions from
  01.07.2025 to 31.12.2027. Asking for the method outside every declared window is
  `E_PACK_INCOHERENT` rather than a rate the library made up.
- **`acquireAsset` takes `usefulLifeMonths`.** A table of class averages cannot express a life
  proven for an individual asset, however complete it is — and without this an asset class the
  pack does not know was simply unusable. Refused, not ignored, on the routes that have no
  schedule of their own.
- **`vatReturn` takes `month`.** The projection served quarterly filers and not monthly ones,
  and monthly filing is not an edge case: it is mandatory above a turnover threshold that any
  mid-sized business passes. The workaround an app would have had to invent — two cumulative
  calls and subtract — is exactly the arithmetic it must not do, because that difference is not
  the period's figure once cash-basis taxation or a reversal is involved. Either `quarter` or
  `month`, never both; neither still means the whole year.
- **A completeness guard for shipped packs** (`PackCompletenessTest` in both languages). Every
  revenue and expense account must find a position in every statement that presents profit and
  loss — not every mapping, since a balance sheet legitimately touches none, a distinction the
  format already made through `mapping.kind`. And once a pack declares any `datevBu` it must
  declare them all: DATEV is a German format, so no pack is obliged to support it, but half an
  answer is not an answer. This is the guard the three pack gaps above needed; the schema check
  proved the packs parse, never that they were complete.
- **The `de` pack carries the official useful-life table** — ten classes instead of one, so a
  car, a lorry or office furniture can be capitalised at all.

### Changed

- `de-afa` 2026.5, `de-euer` 2026.3, `de-ust` 2026.2, `us-schedule-c` 2026.2.
- `docs/gobd-conformance.md` gains a row for reversal visibility, a minus row for the VAT
  correction on a discount (the app's, collected as A-13 in the app repo), and one **correction**:
  "costing runs are traceable" was ✅ and is now ⚠. The audit records are persisted, the runs
  themselves are not — there is no costing repository among the ports, so after a restart a
  durable record points at nothing. No books are wrong, but a green row that overstates is worse
  than an honest amber one.

### Known limits, stated rather than hidden

- **Intra-community acquisition has no tax code in the `de` pack.** The mechanism exists —
  an acquisition is structurally § 13b and `reverse_charge` expresses it — but unlike § 13b it has
  no separate reporting key for the tax amount, and that could not be settled with confidence
  here. A wrongly configured tax code produces silently wrong returns, which is worse than a
  missing one. Same reasoning for the reverse-charge and intra-community DATEV keys, which map
  onto several keys depending on the transaction and were left unset.
- **Costing runs still live in memory** (see above).
- **`due` on an open item comes from the voucher**, so all items from one voucher share it. An
  instalment plan with a date per part has nowhere to record that yet.
- **Depreciation falls back to the calendar year** for plan months beyond every fiscal year that
  has been set up — not a second opinion about the boundary, but so the weighting stays complete
  and the asset is not written off too fast. Set up the fiscal years an asset runs through.

## 0.9.2 — 2026-08-17

Release infrastructure only — no change to any package's behaviour, and nothing to re-check in
your books. It is tagged rather than left on the branch because the mechanism it adds can only be
proven by a real tag push.

### Added

- **The GitHub release writes itself from the CHANGELOG.** A `v*` tag now runs
  [`release-notes.yml`](.github/workflows/release-notes.yml), which extracts the matching
  `## X.Y.Z — <date>` section via `bin/changelog-section.sh` and publishes it as the release body.
  A missing section fails the workflow instead of publishing an empty release, and notes that
  already have content are never overwritten — improving them by hand afterwards stays safe.
  This exists because the step was reliably forgotten: **v0.3.0 through v0.8.1 shipped to npm and
  Packagist with no notes on GitHub at all**, for months, with nothing pointing it out. The notes
  now come from the file that has to be written anyway.

### Fixed

- **The "Latest" badge no longer follows publication order.** Backfilling the missing notes walked
  the tags oldest-to-newest, and `gh release create` marks whatever it published last as latest —
  so the front page announced 0.8.1 while 0.9.1 was already out. The workflow now compares the tag
  against the newest one in the repository and sets the flag explicitly; a manual run for an older
  tag can no longer take the badge off the current release.

## 0.9.1 — 2026-08-17

The dimension round trip in `packages/knex` had no test — the NF-023 hydration parser was
unwatched, and 0.9.0's CI said so after the tag had already shipped. Two tests now drive an asset
with and without dimensions through a real column and back; the knex coverage floors rose with
them (branches 56 → 60, lines 88 → 92). No behaviour change: the code was right, nothing proved it.

## 0.9.0 — 2026-08-17

Closing the gate gaps — the requirements that had no test. Six of them turned out to be defects
rather than missing tests, and all were the quiet kind: no crash, no error, just wrong numbers.
Fixed assets carry most of this release, because that is where the untested requirements sat.

### ⚠ Two things break

- **A pack with a pool range must now declare `poolReducedOnDisposal`** next to `poolYears`,
  or the resolver answers `E_PACK_INCOHERENT`. The shipped `de` pack says `false`; a pack of your
  own needs the line added. It is required rather than defaulted on purpose — see NF-025 below.
- **`disposeAsset` books different entries than it did.** It now writes off the carrying amount
  and books gain or loss, where before it booked only the proceeds. Balance sheet and income
  statement change for anyone who disposes of assets — the old numbers were wrong, but they were
  your numbers, so re-check any reports built on them.

### Fixed

- **A pooled asset kept its depreciation when it was disposed of (NF-019).** `runDepreciation`
  skipped every disposed asset. That is right for a single asset and wrong for a pooled one:
  F-AST-006 requires the pool to be written off on its fixed schedule *unaffected by disposals* —
  the jurisdiction behind the rule says the pool is not reduced when an item leaves. The error was
  directional and silent: **too little depreciation and too much profit, for every remaining year
  of the term.** Fixed in both languages; the disposal still books its proceeds. Fixture
  `pool-unaffected-by-disposal`, which also carries the counter-case (a single asset does stop).
- **`supplierTaxationMethod` could never be set (NF-020).** The field is declared in the data
  format (`enum ["accrual","cash"]`), documented against F-TAX-007, and carried by both `Voucher`
  classes — but nothing ever read it out of the input: PHP passed a literal `null`, Node left it
  out. It decides whether input tax is deductible on invoice or only on payment. It is now
  accepted on `createVoucher`/`postVoucher` and validated — an unknown value is `E_INPUT_INVALID`,
  because storing null silently reads as "supplier taxes on accrual", the answer that permits the
  earlier deduction.

- **An asset disposal now writes off the carrying amount (NF-021).** `dispose` booked only
  `bank → proceedsAccount`: the asset account was never relieved, so a disposed asset **stayed in
  the balance sheet at its carrying amount** and the proceeds counted as income in full instead of
  as a gain against book value — profit overstated by exactly the carrying amount. It now books the
  write-off plus the difference to the pack's `disposalProceedsAccount` (gain) or
  `disposalLossAccount` (loss) — two accounts the pack resolver had been *requiring* while nothing
  booked either. Pooled assets stay exempt (see NF-019 above). A fully depreciated asset scrapped
  for nothing books no entry rather than an empty one.

  ⚠ **Known limit (NF-022):** the write-off uses what has been *booked*. The yearly depreciation
  run books on 31 December, so disposing mid-year without running depreciation first writes off a
  stale carrying amount and overstates the loss by the pro-rata share. Run depreciation up to the
  disposal period first. Making `dispose` catch up on its own is a separate decision — it would
  make one operation write two economically different entries.

- **The pool-disposal rule left the core (NF-025).** Fixing NF-019 put § 6 Abs. 2a EStG straight
  into `runDepreciation` as `route !== 'pool'` — „a disposal does not reduce the pool" is not a
  property of pooling but **one jurisdiction's answer**, and the UK and Australia give the
  opposite one. It is now `poolReducedOnDisposal` in the depreciation module, conditionally
  required next to `poolMax` and refused rather than defaulted — the same treatment `poolYears`
  got for the pool period (F-004), one line further down in the same file. Two fixtures drive the
  identical sequence through both answers.
- **The disposal books the depreciation it owes first (NF-022).** Otherwise it wrote off a stale
  carrying amount, and the asset's last months of depreciation never happened at all —
  `runDepreciation` skips disposed assets. The expense landed as an inflated disposal loss instead
  of as depreciation: the income statement total was right, the split was not, and the fixed-asset
  schedule reported too little depreciation. Which months are due follows the schedule's existing
  convention, so no new rule enters the core. Still open by design: whether the month of departure
  counts as a whole month is a pack question.
- **A pooled asset no longer reports a carrying amount of zero (NF-024).** `bookValueAt`
  short-circuited for every route except `capitalize`. Correct for an immediately expensed asset,
  wrong for a pooled one — it sits on the pool account with a real book value, and the fixed-asset
  schedule (F-AST-005) understated the balance sheet it is supposed to explain.
- **An asset carries its dimensions, so depreciation can run at all (NF-023).** A tenant with a
  mandatory dimension on the depreciation account could not depreciate: `postMachineEntry` builds
  its own lines and had no dimension to give, so every run failed with `E_DIMENSION_INVALID`.
  `acquireAsset` now takes `dimensions`, the asset stores them, and acquisition, the depreciation
  run, the disposal catch-up and the disposal itself all book with them — both persistence
  adapters included, so a restart does not undo it. Exempting machine entries from the constraint
  would have been the easy fix and the wrong one: depreciation per cost centre is what cost
  accounting is for.
- **An unknown tax mechanism is refused instead of quietly booked as standard.** `mechanismFor`
  fell back to the standard mechanism for any unregistered name. Since the repertoire is closed —
  a pack picks one of the four and carries no code — an unlisted name is a typo or a pack built
  against a newer core, and both booked plain VAT without a word: `reverse-charge` instead of
  `reverse_charge` produced a normal tax line, on the normal account, in the normal VAT return
  box. It is now `E_PACK_INCOHERENT`, and because the resolver calls the same function, a composed
  pack fails at `resolvePack`/`init` rather than at the first posting.

### Added

- **Three fixtures for requirements that were built but unwatched.** `E_POLICY_INVALID` had no
  fixture although the resolver throws it in four places (`resolver-policy-invalid` covers all
  four); the trial balance's `openingBalance`/`debitTotal`/`creditTotal` columns had none although
  both languages emit them (`trial-balance-columns`, over two fiscal years — the only way the
  carry-forward is distinguishable from the period turnover); and F-TAX-007 got
  `supplier-taxation-method`. Plus `resolver-unknown-mechanism` for the hardening above and
  `pool-unaffected-by-disposal` for NF-019/NF-021. 105 fixtures now, cross-test 61/61 each way.

## 0.8.1 — 2026-08-16

Backlog cleanup — no behaviour change, no API change. It ships as a release of its own rather
than waiting, because one of the fixes is only effective once it is on a tag: `branch-alias`
went out wrong with 0.8.0, and the split repos Packagist reads are only updated by the tag
workflow. Nothing about the 0.8.0 *packages* was wrong — Composer takes the version from the
tag — but `dev-main` announced itself as `0.7.x-dev` while 0.8.0 was current.

### Added

- **The Laravel service provider has a test** (`ServiceProviderTest`, on `orchestra/testbench`).
  It was the last file in `packages/laravel` with no coverage, and the only one a Laravel user
  cannot avoid: auto-discovery, `artisan migrate`, `app(DatabaseTenantFactory::class)`. What it
  pins is the part that fails quietly rather than loudly — migrations that never register (a user
  migrates and gets no `summae_*` tables), a factory bound to the default connection while
  `summae.connection` names another, a config that stops being publishable. The package's
  coverage floor rose 95 → 98 with it (measured 99.11).

### Changed

- **The pack version is decoupled from 28 fixtures.** It was expected in 32 fixtures but is the
  actual subject in only four of them, so raising a pack version turned 17 unrelated fixtures red
  — ones about balance sheets, discounts, EÜR. Now only the fixtures that call `resolvePack`
  assert it; everywhere else the expectation is `"pack": { "id": "de" }`. Verified by raising the
  `de` version for real: one fixture red instead of seventeen, and that one is `de-pack-resolves`.
  Nothing about the checked behaviour changed — `expect` is a subset comparison.
- **`branch-alias` is part of releasing.** All three `composer.json` files still read
  `0.7.x-dev` after 0.8.0 shipped; it does not follow the tag and nothing catches it. Corrected
  to `0.8.x-dev`, and `RELEASING.md` now names the step.
- **The testsuite README stopped carrying frozen numbers.** Its status line claimed „58 fixtures,
  38 error codes" long after both had moved; it now points at the tools that know (`make
  fixtures`, `validate.py`) instead of a count that ages.

## 0.8.0 — 2026-08-16

A release about the seams between the pieces rather than about new capability. Nothing here
adds an operation or a report; what changes is that three contracts which had been maintained
by hand are now compared by a test, and that the largest class in the core stopped being one
class.

**Why this is a minor and not a patch:** five error codes that used to exit `1` now exit
49–53. A script that branches on the exit code will see different numbers for the same
failures — a correction, but a visible one. `ExitCodes::all()` / `allExitCodes()` are new;
nothing was removed or renamed.

Both languages stay byte-identical, and every gate (conformance `--strict` against both
subjects, cross-test, PHPStan max, typecheck/lint, coverage floors) is green.

### Fixed

- **Five error codes no longer exit `1` (NF-018).** `E_SETTLEMENT_EXCEEDS_ENTRY`,
  `E_PACK_UNRESOLVED_REF`, `E_PACK_INCOHERENT`, `E_POLICY_INVALID` and
  `E_AMOUNT_SCALE_MISMATCH` were in the error catalogue but not in the CLI's exit-code table, so
  they fell through to `1` — the code that means *unknown error*. A script branching on the exit
  could not tell a bad `summae init --pack …` or an over-claiming settlement from a summae crash;
  the JSON on stderr always named the code correctly. They are **appended** at 49–53, so no
  existing number moves. A new guard test in both languages (`ExitCodesTest`,
  `exit-codes.test.ts`) reads the catalogue and fails when a code in it has no exit code of its
  own — the comparison that was missing.
- **`E_NOT_IMPLEMENTED` reaches the error catalogue.** It was thrown, numbered (44) and
  documented in the handbook, but had no catalogue row, which made it invisible to every
  machine check. The catalogue's line is *everything a caller can rely on* — the reason the
  pure CLI code `E_WORKSPACE_INVALID` is in it — so the row was missing, not withheld. Both
  guards now compare catalogue and exit codes **as sets**, in both directions: 44 codes that
  cover each other exactly. `ExitCodes::all()` / `allExitCodes()` are new (additive) so the
  test can read the mapped list.

### Changed

- **The ledger orchestrator is split.** `Ledger` keeps the operations that write postings
  (`post`, `correct`, `finalize`, `reverse`) plus the line parsing they share, and is now a thin
  facade over `SettlementService`, `ChartAdminService` and `FiscalPeriodService`, with
  `AuditWriter` and `Lookups` carrying what all of them need. The public surface is unchanged —
  `TenantOperations`, the CLI and both persistence adapters see the same object as before.
  879 → 520 lines in Node, 1126 → 671 in PHP.
- **The handbook was brought level with what the code does**, and the status claims in the
  READMEs and CLAUDE files with it — dead job references, stale counts, and two release traps
  are gone. NF-018 was found during exactly that pass.
- **`make sync` refuses to leave a gate file behind.** Three files the gate tests read
  (`api-parameters.json`, `format.schema.json`, `fehlerkatalog.md`) come from the spec folder,
  not from the testsuite folder the mirror is named after. If that source ever fails to
  resolve, the sync used to drop them silently and five tests across both languages would stop
  checking; it now exits non-zero naming the files and where they come from.

### Decided

- **The tax-mechanism repertoire is closed.** New mechanisms are registered inside the core, in
  both languages, with a fixture; a pack selects one per tax code and never carries code. The
  reason is cross-language equivalence: a mechanism plugged in from outside would be different
  code in PHP than in Node, and the shared fixtures could not check it. What would reopen the
  question — the *base computation* becoming its own socket — is recorded in
  `implementations/<language>/packages/core/src/CLAUDE.md`.

## 0.7.0 — 2026-08-16

The release that closes the findings list. Every open item from 0.6.0 — F-004, NF-008,
NF-014, NF-017, NF-015 and the NF-005 remainder — is resolved, together with the twelve-item
backlog two adversarial review passes produced. **100 conformance fixtures green in both
languages** (87 at 0.6.0), byte-identical double run, PHPStan level max, and for the first
time a coverage floor on every package that ships.

Two of the fixes below stop the library from producing quietly wrong output rather than from
crashing, which is the failure shape this project cares about most: the persistence adapters
were handing out other tenants' rows, and a reversal left the invoice it cancelled standing in
the open-item list.

### Fixed — data safety

- **Both persistence adapters ignored `tenant_id` on every by-key path.** `byId`,
  `byOriginEntry` and `save` filtered by primary key alone, so a repository built for tenant A
  returned — and wrote over — tenant B's rows. Identical defect in PHP's `packages/laravel` and
  Node's `packages/knex`, identical fix. Nothing could have caught it before: the conformance
  runner builds one tenant per fixture and the cross test one per database, so an adapter that
  ignores `tenant_id` entirely passes both suites at 100 %. It surfaced the moment the adapters
  got tests of their own (see *Added*), with seven red tests per language on the first run.
- **A reversal left its open items standing** (NF-008). The trial balance showed the receivable
  at `0.00` while `openItems` still reported the same invoice as open — and settleable, so a
  payment could be booked against an invoice that no longer existed. `reverse` now clears them;
  the treatment follows established practice rather than invention, see *Changed*.

### Changed — breaking for callers who relied on lenient behaviour

Four operations that used to accept an instruction and produce an inconsistent result now
refuse it. In each case the ledger and the subledger had drifted apart silently.

- **`reverse` clears the open items of the reversed entry** and **refuses once one of them
  carries a settlement** (`E_ENTRY_HAS_SETTLED_ITEMS`). This is the line SAP draws with message
  F5308: a reversal clears the items it finds, unless one has already been cleared some other
  way. Cancelling a settled item would drop money that actually moved out of the open-item
  history while the ledger kept it; the correction goes through a credit note or refund
  instead. That also answers the NF-005 remainder — "settled, then reversed" can no longer
  occur, so the tax stays declared and the correction is its own cash-effective posting with
  its own date, which is what § 17 Abs. 1 UStG asks for.
- **`correct` refuses to change the *lines* of an entry that produced open items**
  (`E_ENTRY_HAS_OPEN_ITEMS`). The subledger used to keep naming an amount, an account and a due
  date from a posting that no longer existed. Correcting the *text* stays allowed; for amounts
  the GoBD-conform route is reversal and a new posting, which keeps both books together.
- **A settlement cannot claim more than the settling entry actually moves on that account**
  (`E_SETTLEMENT_EXCEEDS_ENTRY`). A partial payment of 500.00 could close an item of 1,190.00
  in full: the ledger then carried a receivable the open-item list no longer knew about, and
  under cash-basis taxation VAT was declared as received that never arrived. The bound is the
  account's *net* movement, so discounts and bad-debt cases stay valid.
- **A pack that opens a pool range without saying how long** is refused
  (`E_PACK_INCOHERENT`) rather than silently inheriting one jurisdiction's period — see *Added*.

### Changed — data format (additive)

- **`openItem.status` gains `cancelled`**, and settlements gain **`cause`** (`payment` |
  `cancellation`, absent means `payment`). `cancelled` and not `settled` on purpose: no money
  arrived. The marker is load-bearing rather than cosmetic — cash-basis VAT follows an item's
  settlements, so without it the reversal of a never-paid 1,190.00 invoice would have declared
  190.00 of VAT out of thin air. `vatReturn` skips cancellation settlements.
- **`$defs/openItem` now declares what the engine has always written** — `remaining`, `status`
  and the settlement `difference` were missing under `additionalProperties: false`. Latent,
  because nothing validated a stored open item against the schema.
- **`$defs/depreciationData`** is a real per-kind schema for `depreciation` modules, and it makes
  `poolYears` conditionally required wherever a `poolMax` is declared.

### Fixed — reports

- **An unmapped account no longer vanishes** (NF-014/NF-017). It used to disappear from
  `incomeStatement` while `balanceSheet` kept counting it, so the two reports disagreed about
  the same money and neither said so; on the balance sheet an unmapped balance account made the
  sheet stop balancing outright. Both now use the `_unassigned` catch-all plus `gapWarnings[]`,
  the treatment the error catalogue already prescribed and `importMapping` already applied.
- **`auditDataExport` starts income accounts at zero** for the fiscal year (R-2). They carried
  their lifetime balance as the opening figure, so a US audit-data export showed revenue
  brought forward into a year it did not belong to.
- **A one-cent invoice is bookable again** (R-11). Tax rounding to `0.00` produced a zero line,
  which the ledger rejects — a valid small invoice failed with a message about invalid amounts.
  Zero tax lines are dropped instead of posted.
- **`journalExport.format` and `costAllocationSheet.fiscalYear`/`period` have an effect.** They
  were declared, accepted and read by nobody.
- **The export manifest states the current format version** (0.6) instead of a hard-coded 0.4,
  guarded against drifting back by a test against the schema `$id`.
- **The pack resolver says which mapping, which position, which selector** when a reference
  goes nowhere, instead of naming only the module.

### Fixed — CLI

- `init` validates before it writes: `--pack` and `--rules` together are refused, the first
  fiscal year must be a plausible year, and a workspace whose creation fails is removed rather
  than left half-built where `init` refuses to run again (R-8/R-10).
- A workspace file with a missing or unusable field says so (`E_WORKSPACE_INVALID`). Every
  field used to fall back to a default and a missing `tenantId` was regenerated, so a damaged
  `summae.json` opened the same database under a different identity and reported empty books —
  indistinguishable from books never written (R-9).
- **An imported mapping outlives the process that imported it** (R-4). `importMapping` only
  touched the in-memory registry, so it answered `imported: true` and the next command behaved
  as though nothing had been imported.

### Added

- **The low-value-asset pool period is pack data** (F-004). `poolYears` sits on the
  depreciation module; `de-afa` declares 5, `us-macrs` `null`. A fixed five years used to be
  compiled into the core — one jurisdiction's rule in the law-free substrate, which every
  other jurisdiction with a pooled regime would have inherited without ever saying so.
- **The persistence adapters have their own test suites** (NF-015), in both languages: a
  round-trip written by one tenant instance and read back by a second one on the same
  connection, so every assertion has genuinely been through a column; the stored JSON checked
  against the aggregate's own serialization; tenant scoping with two tenants on one database;
  the hydrator's defensive branches, where a wrong default drops data instead of crashing.
  `packages/laravel` joins the coverage gate at a 95 % floor, `packages/knex` rises 84 → 88.
- **Four new error codes**, all appended: `E_ENTRY_HAS_OPEN_ITEMS`, `E_ENTRY_HAS_SETTLED_ITEMS`,
  `E_SETTLEMENT_EXCEEDS_ENTRY`, `E_WORKSPACE_INVALID`. No existing code shifted, so exit codes
  stay stable.
- **Thirteen conformance fixtures** (87 → 100), each pinning one of the defects above.
- **`de` and `us` packs move to `2026.2`.** Both had changed content several times while still
  claiming `2026.1` — and a tenant records the pack version it was built from, so an unmoved
  version means the books name a rule set that no longer exists. Modules version independently;
  only the six that actually changed moved.
- **Securities are their own balance-sheet item in the de pack**, per HGB § 266 Abs. 2 (A.III),
  with account 1250; the liquidity position no longer swallows two entire decades of account
  numbers.

### Dependencies

- **`brick/math` 0.13 → 0.18** — the money library was six minors behind, pinned there by
  `illuminate/database ^12`. `illuminate/*` now allows `^11|^12|^13`, which unblocked it.
  `RoundingMode` became an enum in that range, so every call site moved. Both ends of the
  declared range were tested: `--prefer-lowest` (brick 0.14.2 + Laravel 11) and `--latest`
  (0.18 + Laravel 13) produce 100 green fixtures with byte-identical output.
- `Currency` rejects a negative scale, which the stricter `BigDecimal::toScale` signature
  surfaced — it used to travel straight into the decimal library.
- Node: eslint, vitest, tsx, typescript-eslint, knex, `@types/node` current;
  `@types/better-sqlite3` was five majors behind; `better-sqlite3` 12 → 13, `commander` 12 → 15.
- Composer manifests: `branch-alias` still said `0.1.x-dev`, and the sibling constraints were a
  bare `*` that would have accepted any future major of our own core — now `self.version`.

### Findings

**None open.** Two things are deliberately parked rather than found:

- **TypeScript stays on 6.** `tsc`, `vitest` and `tsup` all pass on 7.0, but
  `typescript-eslint` refuses to load against the TS 7 API and lint is part of the green gate.
- **`SummaeServiceProvider` is the one uncovered file** in the Laravel adapter — framework glue
  that needs a booted application to exercise. The coverage floor is set with that hole
  included, so covering it later can only push the floor up.
- Every fixture that creates a tenant from a shipped pack asserts the pack *version*, which
  makes a pack content change a 27-file edit. Pinning it in the two `*-pack-resolves` fixtures
  and asserting only the id elsewhere would make it a two-file edit — a change to the oracle's
  shape, not a fix, so it waits for a decision.

## 0.6.0 — 2026-08-15

A correctness release, and the first one that **rejects input earlier versions accepted**.
Every fix below was found by building tests rather than by a bug report, and every one is
pinned by a fixture or a scenario that fails loudly if it comes back. 88 conformance
fixtures green in both languages, byte-identical double run, PHPStan level max.

### Changed — breaking for callers who relied on lenient behaviour

Until now a parameter that was present but not a valid value was quietly replaced by a
default. That is the worst failure shape a reporting library can have: the answer looks
authoritative and is wrong. All of it now raises `E_INPUT_INVALID` (exit code 45).

- **A numeric parameter must be a whole number.** `{"year": 2026.4}` is a caller mistake,
  not a value to round into shape — whoever meant "year 2026, period 4" has to say which.
  `2026` and `2026.0` remain the same number: JSON draws no int/float distinction that
  survives parsing, so a rule separating them could not be implemented identically in both
  languages, which is the whole point.
- **An undeclared parameter is rejected**, not ignored. A misspelled `fiscalYear` on
  `vatReturn` used to return a plausible **annual** figure where a quarter was asked for;
  `includeZeroBalance` (singular) was a flag that did nothing.
- **A required parameter must be present.** `trialBalance` without `fiscalYear` returned
  `{"rows":[]}` — the same shape empty books produce.
- `createFiscalYear` requires a positive whole year; a quoted `"2027"` used to create year
  0, addressable by nothing, so every later report for 2027 came back empty and plausible.
- `correct` must say what it changes: an unrecognized field (`txt` for `text`) was a silent
  no-op that still returned a **success** payload for a correction that never happened.
- `openItems`/`datevExport` reject an unknown `kind` instead of falling back. The first
  widened a filter instead of narrowing it — a payment run asking for payables got
  receivables mixed in.

Absent still means absent throughout: an optional parameter that is missing keeps its
documented default. Only *present-and-wrong* is an error.

### Fixed — cross-language divergences

The shared oracle compares the error code and, until this release, nothing else. These
slipped through 87 green fixtures in both languages simultaneously.

- **The same JSON produced different reports.** `{"year": 2026.0, "quarter": 2.0}` — what a
  serializer writes once a value has passed through a float type — gave Node a correct VAT
  return and PHP an empty one, because every numeric parameter was read as
  `typeof x === 'number'` there and `is_int()` here. 18 read sites per language, now one
  check at the dispatcher. `throughPeriod` was worse than empty: Node limited the report to
  the period asked for, PHP fell back to no limit, so the two printed different numbers
  under the same heading.
- **A fiscal year of `1e21` was created by Node and rejected by PHP** (`Number.isInteger`
  accepts it, PHP's int does not reach that far). Both now bound at 2^53-1.
- **Error `details` rendered differently per language** — `true` as `"1"` against `"true"`,
  an object as `null` against `"[object Object]"`. Both now echo back only strings and safe
  integers, the same line canonical JSON already draws, and drop the rest rather than
  guessing at it.
- **NF-009** `CalendarDate` disagreed on years 0000–0099 (host `Date` remaps two-digit
  years); the substrate no longer touches the host date type at all.
- **NF-010** `Money.of` enforces the data format instead of accepting whatever the decimal
  library would parse.
- **NF-011** a forged `taxTag` naming an unknown tax code was posted without complaint.
- **NF-012** `balanceSheet` ignored `fiscalYear` and always reported everything.
- **NF-013** any `direction` other than exactly `"input"` fell through to `"output"` and
  posted the mirror image — expense credited, liability debited.

### Fixed — model

- **NF-005** cash-basis VAT: reversing an entry whose open items are still outstanding is not
  a cash movement. An unpaid, cancelled invoice used to yield an input-tax refund for money
  that never moved.
- **NF-006 / NF-007** `cashBasisReport` without `year` raised an uncaught `InvalidValue`
  (breaking the CLI's JSON contract), and a missing mapping reported `E_MAPPING_OVERLAP` — a
  code stating the opposite of what happened. Both are `E_INPUT_INVALID` now.
- Both CLIs have a JSON error boundary: an unexpected exception leaves as
  `{"error":"E_UNEXPECTED",…}` instead of a stack trace on stdout.

### Added

- **`E_INPUT_INVALID`** (exit 45) — the caller-error code the cases above needed. Appended to
  the catalogue; no existing code shifted.
- **The projection parameter contract as data** — `testing/testsuite/schema/api-parameters.json`
  declares 39 parameters over 14 projections with their types. The core reads no files by
  design, so each language carries the table as a constant and a test per language asserts
  the constant equals the file: drift is mechanically impossible rather than reviewed for.
- **Per-package coverage floors** in both languages, replacing a single floor over the domain
  core while four other packages went unmeasured. Floors ratchet upward only.
- **One home for tests**: `testing/{testsuite,scenarios}` plus `testing/README.md`, which
  answers where each kind of test lives and which kind to write for a given change.
- Two conformance fixtures (`input-invalid`, `parameter-contract`) and two regression
  scenarios covering the input-validation and reversal defects.

### Findings (recorded, deliberately not fixed)

- **NF-014** an account outside a mapping's ranges vanishes from `incomeStatement` while
  `balanceSheet` still counts it. `gapWarnings[]` and the `_unassigned` catch-all exist at
  mapping *import* and are missing in the projections themselves. Next in line.
- **NF-015** `packages/laravel` has no tests of its own and is excluded from the coverage
  gate; it is reached only end-to-end. A green `make test` proves nothing about it.
- Three parameters are accepted and read by nobody (`journalExport.format`,
  `balanceSheet.incomeMapping`, `costAllocationSheet.fiscalYear/period`). Declared as
  `acceptedWithoutEffect` so the gap is visible instead of hiding behind a tolerant reader.
- The **NF-005 remainder**: an item settled and *then* reversed still needs a spec decision —
  leave the tax declared until a refund, or correct it at the reversal date.

## 0.5.1 — 2026-08-15

Documentation release: no API change, no behaviour change. The user documentation gained a
task-oriented half and — more importantly — stopped being able to rot: the walkthrough now runs
in both implementations' green gates, one scenario per shipped configuration.

### Added — CLI walkthrough (`docs/handbuch/cli-walkthrough.md`)
- Task-first companion to the handbook reference: empty directory to closed fiscal year —
  workspace and pack choice, outgoing invoice with tax expansion, payment and settlement,
  reversal, every report shape, `finalize`/`closePeriod`/`closeFiscalYear`, the three exports,
  error handling with exit codes, and a parameter cheat sheet. Every output in it is real CLI
  output. Written for developers **and** for AI agents driving the CLI, which is the surface
  with the smallest automation footprint (three commands, JSON in, JSON out).
- A copy-pasteable companion script, `docs/handbuch/examples/cli-walkthrough.sh`.

### Added — the documentation is gated
- **Walkthrough scenarios** (`docs/handbuch/examples/scenarios/*.json`, moved to `testing/scenarios/` after 0.5.1): one complete lifecycle
  per configuration we ship — `de`, `us`, `default`, and a free `rules.json` — including the
  error paths (unbalanced, already reversed, period out of order, locked account, closed period,
  settlement exceeding the item) with their exit codes.
- Both implementations read the **same** scenario files and pin the **same** expectations
  (`walkthrough.test.ts` / `WalkthroughTest.php`) — the shared-oracle mechanism applied to the
  CLI. Covers what the conformance fixtures cannot reach: the CLI surface, the workspace, the
  pack library, the documented parameter names.
- Two guards: every shipped pack must have a scenario, and every operation the `de` scenario
  pins must appear in the example script. **Shipping a pack now means shipping a scenario.**

### Changed
- Handbook caught up to 0.5.0: `auditDataExport` (AICPA ADS, three GL streams, signed amounts),
  the four tax mechanisms as a table with the rationale for `exempt`, the `us` pack, pack-format
  schema validation, and a warning that period parameters are not uniform across projections.
- `createVoucher` documented for the first time (§ 6.2) — the operation a plain `post` needs.
- CI actions bumped off the deprecated Node 20 runtime (checkout v7, setup-node v7,
  pnpm/action-setup v6); workflow step names and comments translated to English.

### Findings (documented, deliberately not fixed)
- **NF-005** — cash-basis VAT: reversing an *unsettled* open item counts immediately while the
  original still waits for a payment that will never come, so an unpaid-then-cancelled invoice
  yields an input-tax refund. Identical in PHP and Node, so a model question, not a parity
  defect; the accrual path has an explicit rule (F-011), the cash path has no counterpart.
- **NF-006** — `cashBasisReport` without `year` raises an uncaught `InvalidValue` instead of a
  `DomainError`, breaking the CLI's own JSON-output contract. The trigger is realistic: every
  other projection except `vatReturn` takes `fiscalYear`.
- **NF-007** — a missing or unknown mapping reports `E_MAPPING_OVERLAP`, a code that says the
  opposite of what happened. Current behaviour pinned in `default.json` so a fix is deliberate.

Each needs a spec decision (or an append to the error catalogue) before either language moves.

## 0.5.0 — 2026-06-24

US reach and a hardened core. A new US export (AICPA Audit Data Standard), an `exempt`
tax mechanism, the tax-mechanism socket realized as a registry, pack-format schema
validation, and a battery of structural guards — all green: PHP + Node `--strict`
(core **and** database subjects), byte-identical double run, SF-15 cross-test both directions.

### Added — US export (`auditDataExport`)
- **AICPA Audit Data Standard (General Ledger)** export — the US counterpart to
  `journalExport` (GoBD-Z3) and `datevExport` (DATEV), both German. The US has no statutory
  GL export format; the ADS is the voluntary standard a US auditor expects. Three streams
  (journals/GLDetail, trialBalance/GLAccountBalance, accounts/chart) with the standard's JSON
  field names; **signed** amounts (debit +, credit −). New requirement F-IO-009, conformance
  fixture, both languages 1:1.

### Added — `exempt` tax mechanism
- A tax-exempt sale is now postable. A plain rate-0 *standard* code expands to a 0.00 tax
  line the ledger rejects; the new `exempt` mechanism emits **no** tax line (tax-free, base
  tagged), so it posts cleanly. The us-pack `EXEMPT` code is wired to it. Resolves NF-004/F-010.

### Changed — tax-mechanism socket → registry (internal, byte-identical)
- The inline tax-mechanism switch in `TaxService` (`reverse_charge` / `intra_community_supply`
  / standard) is now an **addressable registry** of strategy objects in the policy layer — the
  "socket" the architecture calls for. The three projection/resolver sites that hard-coded
  mechanism *names* now query mechanism *metadata*. **No behavior change** (byte-identical,
  conformance + cross-test unchanged). A new mechanism (like `exempt`) is a registered strategy,
  not an edit scattered across sites.

### Added — pack-format schema validation
- Every shipped pack-library module + manifest is validated against `format.schema.json` in
  both languages (Node ajv / PHP opis); the `mapping` and `policy` kinds deeply against their
  `$defs`. The Node runner now also validates journalExport streams (parity with PHP).

### Added — structural guards & contract tests
- Determinism guard (no wall-clock/RNG in the core outside the injected Clock/Id seam),
  no-statute-citation guard, a `TenantOperations` contract test (every API operation/projection
  resolves; unknown → the defined error; identical surface PHP↔Node), and dedicated NF-6
  (sequence integrity) / NF-7 (performance) tests.

### Changed — core comments de-jurisdiction'd
- Statute citations (§ N UStG/EStG/HGB) and German abbreviations removed from the law-free
  core's comments; mechanism identifiers and real feature/format names (DATEV, GoBD-Z3) kept.

### Notes
- **journalExport stays German** (GoBD-Z3 is a German standard; its field descriptions serve a
  German auditor) — the dropped "translate to English" idea became the US export above instead.
- **Deferred** (does not block the green build): the `ledger.ts` orchestrator split (a
  taste/structure decision), per-kind schemas for the remaining pack kinds, and the US
  account-number sign-off.

## 0.4.0 — 2026-06-24

The **us-pack** (United States) — the second complete jurisdiction pack and the first real
paradigm beside Germany — plus a substrate cleanup that pulls the last jurisdiction text out of
the law-free core. Green throughout: PHP + Node `--strict`, core **and** database subject,
byte-identical double run, coverage ~90% both.

### Added — `us` pack (`createTenant(pack: "us")`)
- **Own US chart** (35 accounts, English) in the **common US small-business numbering**
  (1xxx assets · 2xxx liabilities · 3xxx equity · 4xxx revenue · 5xxx COGS · 6xxx expenses) —
  US-GAAP prescribes no statutory chart, so this follows the layout US users expect (distinct from
  the de-pack's class scheme; the two packs are self-contained and share no accounts).
- **Sales & use tax** (`us-salestax`): `SALETAX` (single-stage retail sales tax, no input-tax
  credit), `USETAX` (self-assessed use tax → cost + liability), `EXEMPT` (resale/interstate, rate 0).
- **US-GAAP mappings**: Classified Balance Sheet (by liquidity), Multi-Step Income Statement (by
  function), cash-basis **Schedule C**.
- **MACRS / de-minimis** depreciation (immediate expense ≤ 2,500 USD, no pool) + asset accounts.
- **US policy**: USD, half-up per voucher, scale 2; defaults `accrual` (GAAP) / quarterly.
- **11 conformance fixtures** (resolve, sales tax, use tax, exempt sale, balance/income,
  depreciation, end-to-end fiscal year, **sales-tax return**, **Schedule C cash-basis**,
  **contra-revenue**, **economic nexus / Wayfair**) + a `summae init --pack us` CLI smoke.

### Added — `de` pack
- **EÜR mapping** (`de-euer`, Anlage EÜR §4 Abs. 3 EStG) — the cash-basis profit/loss as a
  projection, the symmetric counterpart to the us-pack's Schedule C (the de manifest gains an
  8th module). Plus a **VSt7** (reduced input tax) conformance fixture.

### Changed — cash-basis tax labels are now pack-driven (core cleanup)
- The cash-basis projection no longer hard-codes German VAT strings (`Vereinnahmte USt` …) or the
  "VAT flows through" treatment in the law-free core. A tax account flows through the cash-basis
  result only where the pack's mapping maps it (label from the mapping leaf); unmapped tax is a
  neutral pass-through. **Behavior note:** running `cashBasisReport` on a de tenant now requires
  passing the `de-euer` mapping to get the VAT lines (previously hard-coded). Resolves NF-003/F-009.

### Quality gate
- **Contract-validation obligation** + **tests-ship-with-the-pack obligation** recorded in
  `CLAUDE.md` / `pack-library/CLAUDE.md`: behavioral fixture coverage isn't enough — contract
  surfaces (data/pack format, the API dispatcher, NF-6/NF-7) each need a guard, and every legally
  expected pack capability ships with its fixture.
- **Structural guard added**: "no hard-coded jurisdiction label text in the core" (PHP
  `SubstrateBoundaryTest` + Node `no-jurisdiction-text` test) — the regression guard for the
  class of bug the cash-basis labels were.

### Schema & docs
- `format.schema.json` `$defs/mappingPosition` now declares `includeNonCash` (NF-002/F-008).
- **Handbook**: documents Node DB persistence (the Knex adapter), parallel to the PHP Laravel
  adapter; stale `Summae\Core\Shared\` namespace fixed.

### Notes
- **Sign-off pending** (does not block the green build): the US account numbers, use-tax naming,
  default taxation method, multi-state strategy — see internal `99-pack-docs/us-pack/`.
- **Open engine items** (documented in both `SPEC-FINDINGS`): `EXEMPT` cannot be posted yet (its
  0.00 tax line is rejected — NF-004/F-010, argues for an `exempt` mechanism).

## 0.3.2 — 2026-06-23

Docs/comments only — **no API/behavior change** (conformance + SF-15 cross-test green, byte parity unchanged).

### Internationalization (English everywhere)
- All **code comments, docblocks, and exception messages** translated to English (PHP + Node, mirrored 1:1).
- All **CLAUDE files**, **package descriptions** (`package.json`/`composer.json`), the **CHANGELOG**,
  **RELEASING**, every **README** (packages, runtimes, pack library), both **SPEC-FINDINGS**, and the
  residual German in the **handbook** are now English. The working language in chat stays German; the
  `EÜR` abbreviation and the German chart-of-accounts data are kept as-is.
- **Self-contained repo:** references to the internal knowledge base (numbered paths) removed from
  tracked docs — the repo now stands on its own; the contract is the fixtures + schema.

### Drift fixed (caught during translation)
- `pack-library/README.md` described a non-existent shared `modules/` layout → corrected to the actual
  self-contained pack structure.
- The handbook documented a stale default reversal text `"Storno <seqNo>"` → corrected to
  `"Reversal <seqNo>"` (the actual code default).
- Package READMEs used the pre-0.3.1 `Summae\Core\Shared\` namespace → updated to `Substrate\`.

## 0.3.1 — 2026-06-23

Internal + docs — **no API/behavior change** (byte parity unchanged, still proven).

### Internal / maintainability
- **`core/src` structured along the architecture**: `substrate/` (substrate) · `ledger/`
  (orchestrator) · `records/` · `policies/{expansion,projection,constraint}/` ·
  `composition/` · `partner/` · ports/adapters. The substrate boundary („imports nothing
  from above") is **mechanically enforced** (Node eslint, PHP arch test).
- **Test coverage** as a metric + floor (core lines ≥ 88 %), **fixed in the test run** of both
  languages. PHP now runs the full conformance suite under PHPUnit too
  (`ConformanceTest`), so it counts toward coverage (pcov in the image).

### Docs
- User **handbook** and **developer docs** of both languages **in English** and brought up to
  date: architecture model **substrate → policy kinds (socket/plug) →
  pack**, dependency inversion (the core never imports a pack), the implemented
  directory structure. Hardcoded fixture counts removed.

## 0.3.0 — 2026-06-22

### Packs (cross-language, byte parity PHP↔Node)
- **New: pack composition.** A `PackResolver` (pure function) resolves a manifest +
  its modules into *one* `ruleModules` bundle that the engine eats. Tenant by
  pack choice, **once at creation, pinned, no override** — `createTenant(pack: "…")`.
- **New: shipped pack library** (`pack-library/`) with a content-based loader.
  Packs are **self-contained** — each holds its own modules (`pack-library/<pack>/`),
  no shared `modules/`, no building on each other.
- **New: `default-pack`** (neutral, account-sparse frame) and **`de-pack`** (Germany):
  own chart of accounts, VAT 19/7 · §13b reverse charge · intra-community supply · deemed
  supply · cash discount, balance sheet (§266) / income statement (§275), depreciation/low-value
  assets, accruals/deferrals, policy. Fully conformance-tested incl. end-to-end yearly cycle and VAT return.
- **`packPolicy`** parametrizes the engine jurisdiction-free: `currencyScale` → `Currency`,
  `taxRoundingGranularity` → `TaxService`.
- **New: `createVoucher` operation** — create a voucher without posting (attachment point e.g. for depreciation).

### CLI
- `summae init --pack <id>` selects a pack from the library (`--pack-library`,
  `--first-fiscal-year`) — pack choice from the frontend.

### Docs
- Language-neutral model **core/substrate → policy kinds → pack** with a clear
  `kind`→policy-kind mapping and a „write a pack by hand" guide; build conventions
  and quality gate in the CLAUDE files; Node `docs/` brought in line.

### CI
- Split-workflow token fix (subtree split runs turnkey via the workflow).

## 0.2.0 — 2026-06-20

### Node (M4)
- **New: `@superheld/summae-knex`** — database adapter (Knex as schema/query builder
  + better-sqlite3 / pg). Matches the shared `summae_*` schema of the PHP reference, so
  PHP and Node packages can **share the same data set**.
- **New: `@superheld/summae-cli`** — terminal tool (`summae init|op|report`),
  JSON input/output, persistent SQLite workspace.
- `@superheld/summae-core`: `Tenant.fromPorts` (tenant from arbitrary ports) +
  `restore` methods for FiscalYear/OpenItem/Asset.

### Cross-language
- **SF-15 cross-test (bidirectional)**: PHP↔Node on shared SQLite; `journalExport`
  **byte-identical in both directions** (`make cross`, enforced in CI).
- **F-CROSS-001 solved**: canonical timestamp format (UTC, RFC 3339, fixed
  milliseconds, `Z`) across all implementations.
- CI now covers **PHP + Node + cross-test** (previously PHP only).

### PHP
- **Breaking** (`superheld/summae-laravel`): adapter classes `Eloquent*` → `Database*`
  (named by role; they never used the Eloquent ORM, only the
  `illuminate/database` query builder). Runner subject `eloquent` → `database`.
- Timestamps in the canonical format (F-CROSS-001).

## 0.1.0 — 2026-06-18

- First public release. PHP reference (`superheld/summae-{core,laravel,cli}`)
  on Packagist + `@superheld/summae-core` (Node) on npm. 45/45 conformance fixtures,
  central handbook (`docs/handbuch`).
