# CLAUDE.md — `pack-library/` (pack authors)

Here live the shipped **packs** — the **plugs** of the three policy kinds, never core code/law.
Product data, **no tests** (conformance fixtures live in `testing/testsuite/`).

> **This folder is the source.** Until 2026-08-26 it was mirrored in from an outside repository by
> `make sync` and editing it here meant losing the work at the next sync; that mirror is retired and
> the script is deleted. Edit the packs here. A pack is *product data* — a version bump is a product
> decision, not a contract change, which is why no fixture may pin one (root `CLAUDE.md`, superseded).

## Layout

- A **pack** = self-contained folder `pack-library/<pack>/` with manifest + own modules. Packs **do not build
  on each other** (own ids, own chart of accounts, **no shared `modules/`**).
- A **module** = a plug for **exactly one** policy kind, usually a data file (`kind` + `data`).

| `kind` | policy kind |
|---|---|
| `tax` · `depreciation` · `assetAccounts` | **expansion** (plug) |
| `productionCost` | **projection** (which cost components may be capitalised into inventory) |
| `mapping` | **projection** (mappings) |
| `accounts` | fills the (account-less) **substrate** account primitive |
| `policy` | **parameters** (rounding/scale via `packPolicy`) |
| `resultAppropriation` | **expansion** — which account a resolution books against and which targets the jurisdiction offers (`appropriateResult`) |
| `legalForms` | **projection** — which legal forms the jurisdiction knows and what each owes in a resolution on the result, with its deadline and citation (`unappropriatedResult`). The only kind with **no** `dependsOn`: it names no accounts |
| `inventory` | **expansion** — which accounts hold stock and where each one's change in inventory is booked (`valuateInventory`). The German module names two different counter-accounts (§ 275 Abs. 2 Nr. 2 for finished goods, material expense for raw materials and merchandise); the US one names the same account four times, because a US income statement has no such line. Same engine, same books, and the whole difference is those rows |
| `provisions` | **expansion** — which accounts hold provisions, what each books its addition and its release to, and from what remaining term a long-dated one is discounted (`recognizeProvision` and its three siblings). Note what is deliberately **not** here: the discount **rate**. Germany's is republished monthly, so the pack carries the rule and its citation while the caller carries the number — a stale legal rate that looks authoritative is worse than an absent one |
| `constraint` | **constraint** — two predicates, and a module carries at least one of them, not necessarily both: `dimensionRules` (which accounts may not be posted without which dimension) and `accountCombinationRules` (which accounts must, or must not, meet in **one** entry — `whenAccountIn` plus exactly one of `requireAccountIn`/`forbidAccountIn`). Both see one entry: no deadlines, no reach across entries, no rule about a settlement. Several constraint modules add up rather than replace, so module order in a manifest carries no meaning |

- The **resolver** (`PackResolver`, byte-equal PHP↔Node) folds manifest + modules into *one* bundle and
  **fails loudly** on missing/incoherent references (`E_PACK_UNRESOLVED_REF` / `E_PACK_INCOHERENT`).

- **What a pack does NOT offer, it should still say.** An omitted module and an empty one read the same
  to the resolver and very differently to a caller: `us-legal-forms` declares five forms that owe no
  resolution, so `unappropriatedResult` answers `false` ("this form resolves nothing") instead of `null`
  ("nobody has said what this company is"). The `default` pack ships neither that module nor a mapping,
  and both absences are then a *reported* absence rather than an error the caller has to interpret
  (IMPL-032).

## Rule

- **No code/law into the substrate.** A pack is data; a new *paradigm* with its own algorithm would be a
  composable module **behind the socket** — never smeared into the core (target model, root `CLAUDE.md`).
- Consumers **reference** a pack by name instead of copying accounts/rules inline.
- **An account's `subtype` is a closed repertoire — thirteen values, nothing else resolves.** `bank`,
  `cash`, `transit`, `ar`, `ap`, `tax_in`, `tax_out`, `result_allocation`, `inventory`, `provision`
  are read by the engine and branch its behaviour; `fixed_asset`, `opening_balance`, `private` are
  annotation nothing consults. `inventory` and `provision` (both 2026-08-29) are the first values
  ever *added*, and both arrived under the condition the repertoire itself named: a pack needing an
  account role the engine genuinely did not have. Each came with its reader — `valuateInventory` and
  `recognizeProvision` refuse an account that is not one — which is the whole difference between a
  registered value and a free string. That two arrived on one day is not the repertoire failing: it
  was closed while the product still had two holes in its balance sheet, and filling them added
  exactly the two roles that were missing.
  An unknown value is `E_PACK_INCOHERENT` at `resolvePack` (since 2026-08-28, F-CORE-046). It used
  to be a free string, and the failure that closed it is the one a pack author will not otherwise
  catch: `tax-out` instead of `tax_out` resolved cleanly and produced a chart with **no output-tax
  account** — the VAT return simply reported nothing on it, and no output said anything was wrong.
  Leaving `subtype` out entirely is always fine; guessing at a value is not.
- **Tests ship with the pack — building a pack means building its fixtures, in the same change.** Every
  capability the pack offers, **especially every legally-expected one** (tax collection, self-assessment,
  exemption/threshold, the tax **return/filing**, depreciation thresholds, cash-basis, balance-sheet &
  income-statement structure), is proven by a conformance fixture under `testing/testsuite/fixtures/pack/<pack>/`
  that drives it through the API and pins the expected result. A shipped-but-untested capability is a
  **gate-gap finding, not "done"** (root `CLAUDE.md`, quality gate). When auditing an existing pack, the
  question is: *is every legally-expected effect proven by a fixture?* — if not, that gap is the work.
- **A published `(id, version)` is frozen — change the content, change the version.** This is the
  rule the library ran without until 2026-08-23, and the cost was concrete: `de` kept the version
  `2026.2` while `de-ust` went 2026.2→2026.4, `de-afa` 2026.5→2026.7 and a whole module joined, and
  the old module files were overwritten — so `de@2026.2` named three different bundles and whoever
  pinned it got different books depending on the day they installed. Every edit to a module or a
  manifest raises **that file's** version; a manifest whose module references move raises its own
  version too, because a bundle is what it references. Keeping the old version resolvable is then a
  matter of leaving the old file in place next to the new one (the loader is content-based and
  recursive, so a `versions/` subfolder needs no code) — a request without a version means *current*,
  which is the **highest** version — compared segment by segment and numerically where both
  segments are numbers, so `2026.10` follows `2026.9` rather than preceding it, which is what a
  plain code-point compare did until 2026-08-28. `PackVersionIdentityTest` / `pack-version-identity`
  refuse two files claiming the same published identity, and `resolvePack` returns a derived
  `contentDigest` that nobody can forget to change.
- **A pack's numbers are not the conformance suite's to pin.** A fixture that pins a shipped pack's
  version or account count welds the product to the contract and the pack stops being able to move —
  which is what happened to `de` and `us`. Prove *mechanism* on a fixture that brings its own pack
  (`xx-6-pack-version-pinning`), and prove a shipped pack by its **behaviour** (it resolves, a tenant
  is built, the posting comes out right), the way `de-pack-resolves-current` does.
- **Shipping a pack also means shipping its walkthrough scenario.** Fixtures prove the *engine*; a
  scenario (`testing/scenarios/walkthrough/<pack>.json`) proves the **CLI** a user actually types — one
  full lifecycle with its numbers pinned. A guard test compares the set of shipped packs against the
  set of scenarios, so a pack without one turns the build red in **both** languages; complete fixtures
  will not save you. Format: `testing/scenarios/README.md`.

Writing a pack by hand (skeletons per `kind`, manifest): handbook `docs/handbuch/README.md`.
