# Architecture — the mental model of summae (language-neutral)

How summae is **conceived**, independent of the language. Applies to every
implementation (PHP, Node, …) and to anyone building a pack or an operation.
Language-specific paths/packages live in `implementations/<language>/docs/`.

> Deeper normative rationale is maintained internally and is **not** shipped — which is
> why this document carries the model self-contained in the repo.

## Two axes

summae is held together by two orthogonal axes. Keep both visible — they answer
different questions.

- **Hexagonal** (axis 1) — *where does the dependency point?* A framework-free
  **core** in the middle, **ports** on the edge, **adapters** outside. Persistence
  and CLI are thin adapters; no business logic in adapters, no framework import in
  the core.
- **substrate → policy kinds → pack** (axis 2) — *what is jurisdiction-free and
  what comes from a jurisdiction?* A frozen, jurisdiction-free **substrate** plus
  three **policy kinds**, each a **socket** in the core fed by a **plug** from the
  **pack**.

Both axes share one direction of dependency: **inward**. The pack depends on the
core; **the core never imports a pack**. Composition injects the plug into the
socket (**dependency inversion**).

## Axis 1 — Hexagonal (framework / persistence freedom)

```
        ┌──────────── adapters (outside) ──────────┐
        │   in-memory · [knex] · [laravel]          │
        │   ┌────────── ports (edge) ───────────┐   │
        │   │   ┌──────── domain (inside) ────┐  │   │
        │   │   │  substrate (frozen)          │  │   │
        │   │   │  policies = SOCKETS          │  │   │
        │   │   │  composition (wiring)        │  │   │
        │   │   └──────────────────────────────┘  │   │
        │   └────────────────────────────────────┘   │
        └────────────────────────────────────────────┘
  PLUGS (data) live in /pack-library/ ──injected──▶ into the sockets
  Dependency points inward only · pack depends on core, never the reverse.
```

Real persistence (`knex`/`laravel`) are **separate packages** outside `core`; only
the in-memory adapters (fakes) live in `core`.

## Axis 2 — substrate → policy kinds → pack (jurisdiction freedom)

### The stack

```
┌─ Configuration / manifest + resolver ───────────────┐  select/compose a pack;
│  pack:"de-complete" | own module list | overrides    │  resolver checks coherence
├─ Pack (= jurisdiction profile) ──────────────────────┤  jurisdiction bundle
│  chart of accounts, tax codes, mappings, depreciation │  ("tzdata for accounting")
│  tables, rounding policy, export adapters … mostly data│
├─ Three policy kinds over the substrate ──────────────┤  Constraint · Projection
│  socket in the core, plug (data) from the pack        │  · Expansion
├─ Substrate (the core) ───────────────────────────────┤  knows no law
│  posting, account, journal, balance, period,          │
│  post/settle/reverse, allocate                        │
└────────────────────────────────────────────────────────┘
```

### 1. Substrate (the core)

Jurisdiction-free: posting (journal entry), account, journal, balance, period,
finalization, reversal, open item, dimension — plus the *mechanics* `post`,
journal append, balance folding, `sequenceNumber` assignment, `correct`. No
statute, no tax rate, no chart of accounts. **It is frozen — it does not grow.**
If a concept would make sense even for a *fictitious* jurisdiction → it belongs here.

### 2. Three policy kinds (everything above the substrate is exactly one of them)

Each policy kind is a **socket** (the law-free mechanism in the core) fed by a
**plug** (rules/data from the pack):

- **Constraint** — a predicate that must hold, enforced on write:
  Σ debit = Σ credit · voucher requirement · period open · finalization immutable ·
  required voucher fields (e.g. VAT ID on intra-community supply) · gapless journal
  numbering.
- **Projection** — journal → view (never from stored balances): `trialBalance`,
  `balanceSheet`, `incomeStatement`, `cashBasisReport`/EÜR, `vatReturn`,
  `ecSalesList`/ZM, `openItems`, `assetRegister`, `auditLog`,
  `costAllocationSheet`/BAB, `journalExport`/Z3, `datevExport`. Mechanism in the
  core, **mapping** (plug) from the pack.
- **Expansion** — intent → balanced postings: `expandTax`, `postVoucher`, `settle`
  with a difference (cash discount/§ 17), `runDepreciation`, the low-value-asset
  branch in `acquireAsset`, `disposeAsset`, `reverse` (general reversal), costing
  allocations. Socket in the core, **plug** (rule data) from the pack.

**Discretion boundary:** only what is *deterministically derivable from rule data +
the posting set* is plug-driven (depreciation = table-driven). Valuation discretion
(provision amounts, receivable write-downs, lower-of-cost-or-market test) is the
**app's** business — the app may pass a discretionary figure as a parameter into an
expansion, never the judgment itself.

### 3. Pack (= jurisdiction profile)

The versioned bundle of all data + rules of a jurisdiction. "Germany" is the
*first* pack, not the built-in assumption. **Almost everything is data** — a pack
needs real *code* in exactly two places: a new tax *paradigm* (US sales tax has no
input-tax deduction → a different algorithm) and one thin export serializer each
(DATEV / SAF-T / FEC …).

### 4. Configuration: module / manifest / resolver

A pack is **not a monolith** but itself a composition.

- **Module** = addressable unit, granularity *coherent rule set* (one chart of
  accounts, one set of tax codes, one mapping, one depreciation rule set, one
  rounding policy). Declares *what it contributes* and *what it depends on*.
- **Pack** = a named, resolved module list (manifest). `de-complete` is *one
  curated* manifest, not the only path.
- **Three ways to use it, one mechanism:** (1) take it curated · (2) curated +
  override/omit · (3) compose à la carte yourself.
- **Resolver** checks dependencies + referential integrity (does a tax code post
  to an account the chart of accounts lacks? does a projection need a `taxTag` no
  module produces?) and **fails loudly** (`E_PACK_UNRESOLVED_REF` /
  `E_PACK_INCOHERENT`) instead of silently miscomputing.

#### Module → policy kind (unambiguous via `kind`)

A **module is not its own layer** — it is the *build unit of the pack layer*. Each
module **serves exactly one policy kind**, determined unambiguously by its `kind`:

| `kind` | serves |
|---|---|
| `tax` · `depreciation` · `assetAccounts` | **Expansion** (the *plugs*) |
| `mapping` | **Projection** (the *mappings*) |
| `accounts` | **Substrate** (the chart of accounts) |
| `policy` | **Parameters** (rounding/scale — cross-cutting) |
| *(`constraint` — no module kind yet)* | **Constraint** (today only generic in the core) |

Read backwards: *building a jurisdiction = supplying, per policy kind, the matching
`kind` module.* A pack "draws on" the generic policy-kind mechanism in the core by
placing data into these slots — it reimplements nothing.

#### Self-contained packs (they do not build on each other)

Each pack holds **its own modules in its own folder** (`pack-library/<pack>/`, e.g.
`de-pack/`, `default-pack/`) — **no shared `modules/`**, unique module IDs per pack.
Packs do not inherit from each other; free à-la-carte composition stays possible,
but the shipped packs are closed.

## The core's directory structure (axis 2, realized)

The `core/src/` layout mirrors the two axes (structure 1:1 in PHP and Node; PHP uses
PascalCase folders):

- **`substrate/`** — frozen, jurisdiction-free (posting sums to zero, account,
  journal, balance, period). Does not grow. **Imports nothing from above.**
- **`ledger/`** — `ledger.ts`, kept as the **orchestrator**.
- **`records/`** — vouchers/records (voucher · open-item · audit). **Not** a policy
  kind; may reference the substrate (data layer).
- **`policies/`** — the three policy kinds; here only the **socket** (law-free
  mechanism), the **plugs** (data) live in `/pack-library/` and are injected:
  - **`expansion/`** — intent → balanced postings (tax · assets · costing ·
    settle-difference · reverse)
  - **`projection/`** — journal → view (folding engines + mappings)
  - **`constraint/`** — predicate gates (still thin; the third kind is unfinished)
- **`composition/`** — resolver · factory · tenant · dispatcher (dependency
  inversion)
- **`partner/`** — supporting subdomain (master data), **not** a policy kind
- **`ports/` · `adapters/`** — hexagon edge / outside

A lint/arch test guards the substrate boundary: it forbids importing `policies/` and
upper layers into the substrate.

## Engine bundle

The engine eats *one* resolved `ruleModules` bundle (`profiles` / `chartsOfAccounts`
/ `taxCodes` / `mappings` / `assetAccounts` / `depreciation` / `packPolicy`); reached
**inline** (the bundle directly) or **composed** (manifest → `PackResolver`).
`packPolicy` parametrizes jurisdiction-free (`currencyScale` → `Currency`,
`taxRoundingGranularity` → `TaxService`).

## Build status — honest (important!)

Most of this is **concept captured, implementation demand-driven** — not finished
code:

- ✅ **The DE pack runs without a core change** — effectively proven by the PHP
  reference.
- ✅ **A fictitious test pack** (3-decimal currency, per-line rounding, no input-tax
  deduction) is built as a fixture set (`testsuite/fixtures/pack/conformance-xx/`) —
  the seam-proof of jurisdiction freedom thus exists as a conformance test.
- 🔧 **Module registry, resolver, `E_PACK_*` codes, manifest format** — specified in
  Gate 1 (`_bauflow-pack-gate01/`, data format v0.6) and present as pack fixtures;
  the runtime resolution (Node/PHP) is in progress (branch
  `job/pack-conformance-runner`).
- 🔧 **Target model vs. current state (honest — otherwise it drifts):** the
  socket/plug picture is the **target**. Today only infrastructure ports
  (Clock/Id/repositories) plus the bundle as *data* are injected; the three policy
  kinds are **not yet** built as ports (`tax-service.ts`/`asset-service.ts` are
  concrete classes).
- ⚠️ **Open decision — do not guess:** whether the mechanism repertoire is *closed*
  (the core never grows, a pack = selection only) or *open* (grows only
  law-free + visibly). The method fusions in `ledger.ts` (post + settle/reverse +
  close in one class) and `tax-service.ts` (socket fused with the DE paradigm,
  branching on `reverse_charge`/`intra_community_supply`) are **gated on this
  decision** — the directory split is done, but the seam *within* those methods
  is not.

The **fictitious test pack is itself a conformance test** and thus falls under the
top quality directive (`CLAUDE.md`: "every future jurisdiction") — the seam-proof of
the decoupling belongs in the same language-neutral suite. Coverage is part of
"green" (floor 88% lines).
