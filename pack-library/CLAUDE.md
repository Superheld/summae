# CLAUDE.md — `pack-library/` (pack authors)

Here live the shipped **packs** — the **plugs** of the three policy kinds, never core code/law.
Product data, **no tests** (conformance fixtures live in `testing/testsuite/`).

> **The source is an internal repository**, mirrored into this repo via `make sync` (`rsync --delete`)
> — **never edit the folder in the repo directly**, the next sync overwrites it.

## Layout

- A **pack** = self-contained folder `pack-library/<pack>/` with manifest + own modules. Packs **do not build
  on each other** (own ids, own chart of accounts, **no shared `modules/`**).
- A **module** = a plug for **exactly one** policy kind, usually a data file (`kind` + `data`).

| `kind` | policy kind |
|---|---|
| `tax` · `depreciation` · `assetAccounts` | **expansion** (plug) |
| `mapping` | **projection** (mappings) |
| `accounts` | fills the (account-less) **substrate** account primitive |
| `policy` | **parameters** (rounding/scale via `packPolicy`) |
| *(`constraint` — still missing)* | constraint (today only generic in the core) |

- The **resolver** (`PackResolver`, byte-equal PHP↔Node) folds manifest + modules into *one* bundle and
  **fails loudly** on missing/incoherent references (`E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT`).
- **Where a jurisdiction has an answer, the pack must give it — no defaults.** A `depreciation`
  module that opens a pool range (`poolMin`/`poolMax`) must declare **both** `poolYears` and
  `poolReducedOnDisposal`; the schema requires them conditionally and the core refuses rather than
  guessing. A `taxCode` version picks its `mechanism` from the closed core list (`standard`,
  `reverse_charge`, `intra_community_supply`, `exempt`) — an unknown name is `E_PACK_INCOHERENT`,
  not a silent fallback to standard VAT.

## Rule

- **No code/law into the substrate.** A pack is data; a new *paradigm* with its own algorithm would be a
  composable module **behind the socket** — never smeared into the core (target model, root `CLAUDE.md`).
- Consumers **reference** a pack by name instead of copying accounts/rules inline.
- **Tests ship with the pack — building a pack means building its fixtures, in the same change.** Every
  capability the pack offers, **especially every legally-expected one** (tax collection, self-assessment,
  exemption/threshold, the tax **return/filing**, depreciation thresholds, cash-basis, balance-sheet &
  income-statement structure), is proven by a conformance fixture under `testing/testsuite/fixtures/pack/<pack>/`
  that drives it through the API and pins the expected result. A shipped-but-untested capability is a
  **gate-gap finding, not "done"** (root `CLAUDE.md`, quality gate). When auditing an existing pack, the
  question is: *is every legally-expected effect proven by a fixture?* — if not, that gap is the work.
- **Shipping a pack also means shipping its walkthrough scenario.** Fixtures prove the *engine*; a
  scenario (`testing/scenarios/walkthrough/<pack>.json`) proves the **CLI** a user actually types — one
  full lifecycle with its numbers pinned. A guard test compares the set of shipped packs against the
  set of scenarios, so a pack without one turns the build red in **both** languages; complete fixtures
  will not save you. Format: `testing/scenarios/README.md`.

Writing a pack by hand (skeletons per `kind`, manifest): handbook `docs/handbuch/README.md`.
