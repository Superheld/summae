# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> **Structure of this documentation.** The root holds what applies to *all*
> implementations. The deeper you go, the more language-specific: commands and
> conventions per language in `implementations/<language>/CLAUDE.md`, detail docs in
> their `docs/`. Always **annotate** references to deeper docs — briefly note what is there.

## What this is

**summae** is an embeddable accounting library (GoBD double-entry, cash-basis
accounting (EÜR), VAT, fixed assets, cost accounting (KLR)) — **not an application**.
Multiple language implementations are meant to have an *identical API and identical
data format*; this is verified via a language-neutral conformance suite (`testing/testsuite/`).

Repo layout:
- `testing/` — **one home for every test that is not a unit test.** `testsuite/` = the compatibility contract (`fixtures/**.json` + `schema/` + `fehlerkatalog.md`, shared by all implementations, **append-only**) · `scenarios/` = language-neutral CLI scenarios (`walkthrough/` + `regression/`). Unit tests are the exception and stay next to their code. Which kind to write where: `testing/README.md`.
- `knowledge/` — **the authoring side**: domain knowledge, requirements (`F-…`/`NF-…`/`SF-…`), domain model, the prose spec, build reports, pack docs. For whoever *builds* summae; `docs/` is for whoever *uses* it. Still German, unlike everything shipped — see `knowledge/README.md`. Project/strategy material (roadmaps, decision log, governance) deliberately stays outside this public repo.
- `implementations/php/` — PHP reference (packages `core`, `laravel`, `cli` + `runner/`). Commands/conventions: `implementations/php/CLAUDE.md`, depth in `docs/`.
- `implementations/node/` — Node/TypeScript (packages `core`, `knex`, `cli` + `runner/`). Commands/conventions: `implementations/node/CLAUDE.md`.
- `pack-library/` — shipped **pack library** (product data, *no* tests): **self-contained** packs (`pack-library/<pack>/` with manifest + own modules), authored here since 2026-08-26 (the last mirror, retired); **separate from `testing/testsuite/`**. Build a pack: `pack-library/CLAUDE.md`.
- `Makefile`, `compose.yaml`, `docker/` — the toolchain. `make check` = the PHP gate (in Docker),
  `make check-node` = the Node gate (local pnpm), `make check-all` = both plus the cross-test.
  Node reached parity here on 2026-08-27: its gate used to exist only as a list of commands in
  `implementations/node/CLAUDE.md`, and that list was short by the database-subject run CI does
  perform — a gate that is easier to run on one side gets run more on that side.

## Scope: capabilities, not workflows

summae provides **capabilities** (GoBD-compliant posting, reports, exports); legal
**workflows** are built by the embedding app. Rule of thumb: „*the data must…*" = package · „*the
user must by X…*" = app. Library, not an app: **no UI, no server, no forced DB**
(persistence behind an interface), multi-tenant at the data level. Deliberately **out of scope**
(not „not yet built" — don't start it by accident): UI/frontend · ELSTER / authority submission ·
e-invoice creation/parsing (XRechnung/ZUGFeRD) · banking (FinTS/PSD2/CAMT — `postVoucher`/`settle`
are the attachment points for *parsed* transactions) · POS systems / TSE · payroll *accounting* (only the
*posting* of the payroll voucher is included) · tax determination beyond VAT (income/corporate/trade tax) ·
**cost-accounting steering instruments** (planned-cost/variance, activity-based, contribution-margin — decided
2026-08-23; the *balance-sheet* part of cost accounting, production cost per § 255 Abs. 2 HGB for inventory
valuation, is deliberately **in** scope and simply not built yet).

> **One line left this list on 2026-08-28, and that is worth recording rather than quietly
> deleting.** The **GoBD Z3 `index.xml` mapping** stood here as deliberately out of scope: the
> self-describing data set was `journalExport`, and turning it into the DTD-conforming data carrier
> was said to be the app's. The reasoning was never wrong about the facts — `datenformat.md` still
> says the export is "a mapping, not an invention" — but "we ship the mapping's *input*" and "the
> books are auditable" are not the same claim, and an audit asking for a data carrier does not care
> which of the two we meant. It is now built (`gdpduExport`, F-IO-012, Beschreibungsstandard 1.6).
> A scope decision that survives only because nothing tests it is worth re-reading now and then;
> this one did not survive the re-read.

## Architecture (the big picture)

Language-neutral — the terms apply to every implementation. Paths and details per
language in their `docs/` (PHP: `implementations/php/docs/architektur.md` — packages,
hexagonal, layers, data flow of a posting).

**Hexagonal.** A framework-free domain core (`core`) carries the entire
bookkeeping logic. Persistence and terminal tool are thin adapters *outside* —
**no domain logic in adapters, no framework import in the core.**

**Ports & adapters.** The core defines interfaces (`AccountRepository`,
`JournalRepository`, …). Adapter sets: in-memory (tests/conformance) and real
persistence (e.g. the PHP `laravel` adapter via `illuminate/database`, persists aggregates as JSON in `summae_*` tables —
the shared data format, see quality policy). A tenant (`Tenant`) is built
with one or the other port set.

**One entry point for all operations.** A dispatcher (`TenantOperations`)
runs *all* ops (`post`, `postVoucher`, `settle`, …) and projections
(`trialBalance`, `vatReturn`, `journalExport`, …) — names exactly per the API spec.
CLI and conformance runner use the same dispatcher. New operation → wire it
there.

**Reads never go through stored balances.** Every trial balance / balance sheet / EÜR / VAT return
is recomputed from the journal.

**Jurisdiction-free: substrate → policy kinds → pack.** This is *how summae is
conceived*, across languages — every agent that builds something must know it, not just
PHP. The core is a **jurisdiction-free substrate** (posting, account, journal,
balance, period) — it knows no law and **does not grow per jurisdiction**
(closed under composition, the abelian group of double-entry). Everything above is *exactly one* of three **policy kinds**: **constraint**
(must hold), **projection** (journal → view), **expansion** (intent → balanced
postings). Each kind is **socket** (law-free mechanism = a port *in* the core)
+ **plug** (data/rules from the **pack**). Core defines the socket, pack provides the
plug, composition injects it (dependency inversion) — **the core never imports a
pack** (dependency only pack→core, mechanically enforced, not by review). The pack is the
versioned bundle of a jurisdiction
(„tzdata for accounting"; „Germany" is the *first* pack, not the built-in
assumption). A pack is composable (take it curated / adapt it / build your own à la carte).
**Litmus test when building:** does your code cite a statute → wrong layer, that
belongs in the pack as data. **The stronger test, because a statute rarely arrives quoted:**
*would another jurisdiction answer this differently?* A rule translated into a plain condition
reads like mechanism — `route !== 'pool'` was § 6 Abs. 2a EStG, and neither the code nor its
comment gave it away (IMPL-025). Full picture + honest build status: `docs/architektur.md`.

**Pack & modules (brief).** Three layers: **substrate** → **policy kinds** (sockets in the core) → **pack** (on top).
A **module** = a plug for *exactly one* policy kind (usually a data file `kind`+`data`); a **pack**
= self-contained manifest that bundles modules (`pack-library/<pack>/`, do not build on each other). Pack choice
once at creation, pinned. Legacy term „rule module" = pack (avoid); **base** = the core, account-less.

Shipped packs: `default`, `de`, `us`.

> **Deeper (annotated):** `kind`→policy kind + module rules → `pack-library/CLAUDE.md` · engine bundle
> (`ruleModules`/`packPolicy`), target-vs-actual + the *closed repertoire* decision → `core/src/CLAUDE.md` · full model
> → `docs/architektur.md`.

## Build conventions (principles — patterns & recipes in the `docs/`)

Use proven patterns, **invent no new structures**:

- **Test-driven & walking skeleton (inside-out):** test first, then code; start in the **core** with **fakes**
  (in-memory ports), then move outward. A red test against the in-memory core = domain error, not persistence error.
- **New pack capability = primarily data (plug), never substrate code:** a module/manifest; a new *paradigm*
  (different algorithm) = composable module **behind the socket**, never into the substrate. **Reference** by name instead of copying inline.
- **PHP and Node mirror each other 1:1.** Every core change identical in *both* — byte parity (SF-15) is a contract.
- **Framework-free in the core** (Node: eslint `no-restricted-imports`; PHP: only `brick/math`). Persistence/CLI are adapters outside.

Patterns list (Factory/Registry/Strategy/Dispatcher) → `docs/architektur.md`; „new operation = service + `case` +
fixture in both languages" + spec retrofit → `implementations/<language>/docs/entwicklung.md`.

## Iron invariants (do not violate)

- **Journal append-only; balances are projections.** Never store a balance.
- **Money never as float.** `Money` on a decimal/BigDecimal library (PHP
  `brick/math`, Node `big.js`), commercial half-up (away from zero, *no* banker's
  rounding), `allocate` with largest-remainder.
- **Determinism.** Same input → byte-identical result (rounding, sorting
  by Unicode code points, canonical JSON RFC 8785). `Clock`/`IdGenerator` are
  injectable — tests **never** against `now()`/randomness; the runner uses `FixedClock` +
  `DeterministicIdGenerator`.
- **Posting date zoneless** (`CalendarDate`, no time/UTC shift).

## testing/testsuite/ is append-only

Fixtures are the normative source and are **authored here** (since 2026-08-23; they used to
live in an external knowledge base and be mirrored in by `make sync`, which is why older docs
call this tree read-only — the knowledge base is now `knowledge/` in this repo, so source and
copy collapsed into one). Alongside them live the machine-readable spec parts they exercise:
`schema/format.schema.json`, `schema/api-parameters.json`, `fehlerkatalog.md`.

**Append-only is the rule that matters, and it did not come from the mirror.** A behavior
change is a *new* fixture, never a quiet edit to an existing one — an edited fixture rewrites
what the contract always said, and every implementation that agreed with the old expectation
silently becomes "wrong" retroactively. Contradiction between spec/fixture/model → **do not
guess, do not bend the fixture**, but document it in the repo-root `SPEC-FINDINGS.md` and
continue building with the next most plausible behavior. **One register for both implementations,
split by state:** `SPEC-FINDINGS.md` holds what is *open* and is kept short enough to read whole;
`SPEC-FINDINGS-RESOLVED.md` holds what is decided, in full, plus the status table. Closing a finding
means moving its block across — the per-language files are thin pointers.

**Retiring a fixture is the one exception, and it is narrow.** Append-only stops a fixture from
being *edited*; it does not claim a fixture can never have pinned the wrong thing. The three
`*-pack-resolves` fixtures pinned the version and the account count of a **shipped pack** — so the
`de` pack could neither gain an account nor publish a version without the suite going red, which is
the opposite of what a contract is for. Such a fixture is **superseded**, never edited and never
deleted: the file stays byte-identical, a successor pins the behaviour that was actually meant, and
`testing/testsuite/superseded.json` records which is which and why. The runner skips what is listed
there, and `SupersededFixturesTest`/`superseded-fixtures.test.ts` check the register in both
languages — an entry must name a real fixture and a real successor that really runs. **The test is
whether the expectation was ever a contract at all**, not whether it is inconvenient: a fixture that
pins *behaviour* is never retired, it is argued with. Product data (a pack's version, the size of
its chart) belongs to the product; mechanism (that a pinned version keeps resolving what it always
resolved) belongs in a fixture that owns its own data, like `xx-6-pack-version-pinning`.

**No mirror remains.** `pack-library/` was the last one — authored outside and copied in by
`make sync` (`rsync --delete`) until 2026-08-26. It is now authored **here**, like everything else:
edit the packs in place, the sync script and its make target are gone. What the mirror cost is worth
remembering, because it is the argument against ever adding another one: a folder that a script
overwrites cannot be fixed where the defect is read, so the two shipped packs carried a mapping
defect nobody could repair without leaving the repository first.

## Conventions (language-neutral)

- **Everything in English** — the project goes international (OSS) with the us-pack: code comments,
  docs, CHANGELOG/release notes, package descriptions (`package.json`/`composer.json`),
  CLAUDE files. Only the **working language in chat** (human↔AI) stays German. *Legacy German is
  translated to English on contact; the bulk was converted ahead of the us-pack.*
- Doc references always **annotated**: briefly note what's found there.
- Git: **never directly on shared branches** (`main`, `develop`) — one branch per task
  (`job/…`, `chore/…`, `fix/…`); merge via `--no-ff` when green.
- **ID namespaces are disjoint by prefix — never by padding.** Four families, and a *requirement*
  never shares a prefix with a *finding*: **`F-<AREA>-nnn`** functional requirement
  (`30-anforderungen/funktional.md`, areas CORE/TAX/AST/KLR/IO/**PACK**/**RP**) · **`NF-n[.n]`**
  non-functional requirement (`30-anforderungen/nicht-funktional.md`) · **`SF-nn`** standard case
  (`30-anforderungen/lieferumfang.md`) · **`SPEC-nnn` / `SPEC-Cnn` / `IMPL-nnn`** findings
  (repo-root `FINDINGS-OPEN.md` open / `FINDINGS-CLOSED.md` decided). Until 2026-08-23 the findings
  ran as `F-0xx`/`NF-0xx`, which a reader — and a grep — could tell from the requirements only by a
  leading zero or a missing area word; the mapping is at the top of `FINDINGS-OPEN.md`. A new series
  gets its own word, not a number range.
- **`covers` names requirements — and this line used to claim more than was true.** Until
  2026-08-28 it read "fixtures name only requirements in `covers`" while 21 fixtures cited a
  `F-PACK-*`/`F-RP-*` family that **no requirements file declared**, and the Gate-1 resolver drafts
  cited error codes (`E_PACK_INCOHERENT`), resolver invariants (`I1`–`I4`) and bare words (`cycle`,
  `override`). The families are now declared, because a requirement that 21 fixtures prove exists;
  the rest is legacy and stays, because a fixture is append-only and its `covers` cannot be edited.
  **For anything new: name declared requirement IDs and nothing else.** PACK and RP were added by
  writing down what was already being tested, not by inventing a scope — and the reason the drift
  went unnoticed for a year is that nothing holds `covers` against the requirement lists. That is a
  known gap, deliberately not closed with a test yet (`FINDINGS-OPEN.md`).

Language-specific conventions, build and test commands: in
`implementations/<language>/CLAUDE.md`.

## Top quality policy: cross-language equivalence

**Same input → same result, regardless of which package or which
language.** This is the top rule — across domain core, persistence, export, and
every future jurisdiction. A test that checks only one implementation
misses summae's purpose.

Two mechanisms, **one** principle:

- Tests are language-neutral and run against **all implementations that have the
  tested capability** (a persistence cross-test cannot run against a
  runtime without persistence — „all *applicable* packages").
- **(a) Shared oracle** — the fixtures pin *one* canonical expectation;
  each implementation is checked against it. A == expectation and B == expectation ⇒
  A == B: N-language equivalence without N² comparisons. (Covers the computation axis.)
- **(b) Shared data** — where a capability exists in ≥ 2 implementations,
  the same data set is driven by multiple packages and must come out identical
  (cross-test, SF-15). Proves format parity that (a) alone does not
  show. Goal: *one DB, multiple engines, one truth.*

## Definition of Green

Each implementation is green by **its** rules (linter/typecheck/tests incl.
**coverage floor per package** (fixed in the test run — every package that has tests
carries its own floor, set just below what it measures; floors may only rise) +
conformance suite `--strict` incl. byte-identical double run — details in the
respective `implementations/<language>/CLAUDE.md`). Across languages additionally:
every capability that exists in ≥ 2 implementations passes the cross-test —
same result across all applicable packages (see quality policy).

**Quality gate: every requirement is tested.** The requirements (functional **F-…** and
non-functional **NF-…**) are the target list. Every requirement is *proven* by a test —
functionally via a fixture (linked in the `covers` field), and where fixtures aren't enough
(concurrency NF-6, performance NF-7) via a **dedicated** test per implementation. A
requirement **without** a test is itself a finding (belongs on the gate-gap list), not „done".

**Contracts get their own validating test — nothing is silently swallowed.** Behavioral
fixture coverage is necessary but not sufficient: every *contract surface* must have a test
that fails loudly when the contract is broken, so authoring mistakes can't slip through
unnoticed (a misspelled field, an undeclared key, a routing gap). Five obligations:
1. **Data format / pack format is schema-validated.** Anything the engine reads — journalExport
   streams, the manifest, **and every `pack-library/` module + manifest** — is validated against
   `testing/testsuite/schema/format.schema.json` in both languages. A field the engine reads but the schema
   does not declare is a finding (e.g. IMPL-002/SPEC-008 `includeNonCash`), not a convenience.
2. **The API/dispatcher surface (`TenantOperations`) has a contract test** — every operation/projection
   named in the API spec resolves to a handler, unknown ops map to the defined error, input shape is
   validated. The runner's behavioral fixtures exercise it but do not pin the contract.
3. **NF-6 (concurrency) and NF-7 (performance)** have their dedicated per-implementation tests (above).
4. **The user documentation is gated.** The walkthrough scenarios (`testing/scenarios/walkthrough/*.json` —
   one per *shipped configuration*: each pack plus a free `rules.json`) drive a complete lifecycle
   through the **CLI** in both languages with their numbers pinned; fixed defects are pinned the same
   way in `testing/scenarios/regression/`. They cover what the fixtures cannot reach: the CLI surface,
   the workspace, the pack library, and the documented parameter names. **Ship a new pack ⇒ add a
   scenario** (a guard test fails otherwise). Documentation that stops being true must turn a build
   red, not rot on the page. **Where every kind of test lives and which one to write: `testing/README.md`.**
5. **The parameter contract is data, not code — for projections *and* operations.**
   `testing/testsuite/schema/api-parameters.json` declares every accepted parameter and every
   accepted operation input with its type; the dispatcher validates against it *before* routing. An
   undeclared key is `E_INPUT_INVALID`, never silently ignored; a declared one of the wrong type is
   rejected, never coerced; an absent one keeps its documented default. The core reads no files, so
   each language carries both tables as constants — and a test per language asserts the constants
   equal that file, which is what makes drift impossible. **Adding a parameter means editing
   `testing/testsuite/schema/api-parameters.json` first**, not the constant. The `operations` block
   arrived late (2026-08-24, reported from outside as F-9) and the gap it left is the lesson: for a
   year the *reads* were declared and the *writes* were not, so a mistyped projection parameter
   failed loudly while a mistyped operation input was dropped and the default stood — on the side
   that writes to the books. Requiredness stays with the operation, whose error code says more.
6. **An error code lives in two places at once.** A new code needs its row in
   `testing/testsuite/fehlerkatalog.md` **and** an append to `ExitCodes.php` + `exit-codes.ts` (order identical,
   never reordered). `ExitCodesTest`/`exit-codes.test.ts` compare the two as sets in both
   directions, so half the work fails the build.

A contract surface without its own guard is a gate-gap finding, same as an untested requirement.

**GoBD claims are a census, not a slogan.** `docs/gobd-conformance.md` maps every GoBD obligation to
one of three statuses — verified (naming the fixture/test), open (named and scoped), or not verifiable
in a library (and therefore the embedding app's, collected in the summae-app repo's
`GOBD-APP-OBLIGATIONS.md`). Two rules when touching it: a row only becomes ✅ when a *named* test fails
without it — never because the code looks right; and an open row is deleted only when it is built, never
because it is inconvenient. Most ✅ rows are substrate mechanism that holds in every pack, not German
law — the litmus test applies here too.

**The same census exists for personal data.** `docs/gdpr-conformance.md` is its twin, with the same
three statuses and the same rule about ✅. Its §1 is an inventory of every field that can hold personal
data, and that inventory is machine-checked against `format.schema.json` — a renamed field turns
`GdprConformanceDocTest`/`gdpr-conformance-doc.test.ts` red rather than leaving a row that still reads
correctly. Adding a field that can carry a name, an address or an identifier means adding its row.
