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
data format*; this is verified via a language-neutral conformance suite (`testsuite/`).

Repo layout:
- `testsuite/` — the compatibility contract: `fixtures/**.json` + `schema/`. Shared by all implementations.
- `implementations/php/` — PHP reference (packages `core`, `laravel`, `cli` + `runner/`). Commands/conventions: `implementations/php/CLAUDE.md`, depth in `docs/`.
- `implementations/node/` — Node/TypeScript (packages `core`, `knex`, `cli` + `runner/`). Commands/conventions: `implementations/node/CLAUDE.md`.
- `pack-library/` — shipped **pack library** (product data, *no* tests): **self-contained** packs (`pack-library/<pack>/` with manifest + own modules). Source is the knowledge base, mirrored via `make sync` (`rsync --delete`); **separate from `testsuite/`**. Build a pack: `pack-library/CLAUDE.md`.
- `Makefile`, `compose.yaml`, `docker/` — Docker toolchain (currently drives the PHP side).

## Scope: capabilities, not workflows

summae provides **capabilities** (GoBD-compliant posting, reports, exports); legal
**workflows** are built by the embedding app. Rule of thumb: „*the data must…*" = package · „*the
user must by X…*" = app. Library, not an app: **no UI, no server, no forced DB**
(persistence behind an interface), multi-tenant at the data level. Deliberately **out of scope**
(not „not yet built" — don't start it by accident): UI/frontend · ELSTER / authority submission ·
e-invoice creation/parsing (XRechnung/ZUGFeRD) · banking (FinTS/PSD2/CAMT — `postVoucher`/`settle`
are the attachment points for *parsed* transactions) · POS systems / TSE · payroll *accounting* (only the
*posting* of the payroll voucher is included) · tax determination beyond VAT (income/corporate/trade tax).

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
belongs in the pack as data. Full picture + honest build status: `docs/architektur.md`.

**Pack & modules (brief).** Three layers: **substrate** → **policy kinds** (sockets in the core) → **pack** (on top).
A **module** = a plug for *exactly one* policy kind (usually a data file `kind`+`data`); a **pack**
= self-contained manifest that bundles modules (`pack-library/<pack>/`, do not build on each other). Pack choice
once at creation, pinned. Legacy term „rule module" = pack (avoid); **base** = the core, account-less.

*Built:* `PackResolver` (byte-equal PHP↔Node), loader, `createTenant(pack:"…")`, CLI `summae init --pack …`,
packs `default` + `de`.

> **Deeper (annotated):** `kind`→policy kind + module rules → `pack-library/CLAUDE.md` · engine bundle
> (`ruleModules`/`packPolicy`), target-vs-actual + open *closed/open* question → `core/src/CLAUDE.md` · full model
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

## testsuite/ is read-only

Fixtures are the normative source and live in the **knowledge base** (sister repo
„Rechnungswesen"). They are mirrored here via `make sync` (`rsync --delete` —
whatever is here and not in the source gets deleted; **do not put your own files
in `testsuite/`**) and **never edited here**. Fixtures are append-only:
behavior change = new fixture, never silent editing. Contradiction between
spec/fixture/model → **do not guess, do not bend the fixture**, but document it in the
`SPEC-FINDINGS.md` of the respective implementation and continue building with the
next most plausible behavior.

## Conventions (language-neutral)

- **Everything in English** — the project goes international (OSS) with the us-pack: code comments,
  docs, CHANGELOG/release notes, package descriptions (`package.json`/`composer.json`),
  CLAUDE files. Only the **working language in chat** (human↔AI) stays German. *Legacy German is
  translated to English on contact; the bulk was converted ahead of the us-pack.*
- Doc references always **annotated**: briefly note what's found there.
- Git: **never directly on shared branches** (`main`, `develop`) — one branch per task
  (`job/…`, `chore/…`, `fix/…`); merge via `--no-ff` when green.

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
**coverage floor** (core lines ≥ 88 %, fixed in the test run — may only rise) +
conformance suite `--strict` incl. byte-identical double run — details in the
respective `implementations/<language>/CLAUDE.md`). Across languages additionally:
every capability that exists in ≥ 2 implementations passes the cross-test —
same result across all applicable packages (see quality policy).

**Quality gate: every requirement is tested.** The requirements (functional **F-…** and
non-functional **NF-…**) are the target list. Every requirement is *proven* by a test —
functionally via a fixture (linked in the `covers` field), and where fixtures aren't enough
(concurrency NF-6, performance NF-7) via a **dedicated** test per implementation. A
requirement **without** a test is itself a finding (belongs on the gate-gap list), not „done".
