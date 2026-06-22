# CLAUDE.md — `core/src/` (architecture of the domain core)

Two **axes** — keep both visible here. Structure 1:1 identical in PHP and Node
(lowercase folders there). The big picture + build status: root `CLAUDE.md`.

## Axis 1 — hexagonal (framework/persistence freedom)

```
        ┌──────────── Adapters (outside) ──────────┐
        │   InMemory · [knex] · [laravel]           │
        │   ┌────────── Ports (edge) ───────────┐   │
        │   │   ┌──────── Domain (inside) ────┐  │   │
        │   │   │  Substrate (frozen)          │  │   │
        │   │   │  Policies = SOCKET           │  │   │
        │   │   │  Composition (wiring)        │  │   │
        │   │   └──────────────────────────────┘  │   │
        │   └────────────────────────────────────┘   │
        └────────────────────────────────────────────┘
  PLUGS (data) live in /pack-library/ ──injected──▶ into the sockets
  Dependency points only inward · pack depends on the core, never the reverse.
```

Real persistence (`laravel`/`knex`) are **own packages** outside of `core`; in
`core` only the in-memory adapters live (fakes, `InMemory/`).

## Axis 2 — substrate → policy kinds → pack (jurisdiction freedom)

- **`Substrate/`** — frozen, jurisdiction-free (posting sum 0, account, journal,
  balance, period). Does not grow. **Imports nothing from above.**
- **`Policies/`** — the THREE policy kinds; here only the **socket** (law-free mechanism),
  the **plugs** (data) live in `/pack-library/` and are injected:
  - **`Expansion/`** — intent → balanced postings (Tax · Assets · Costing · settle difference · reverse)
  - **`Projection/`** — journal → view (fold engines + mappings)
  - **`Constraint/`** — predicate gates (still thin; third kind unfinished)
- **`Composition/`** — resolver · factory · tenant · dispatcher (dependency inversion)
- **`Records/`** — vouchers/records (Voucher · OpenItem · Audit), **not** a policy kind
- **`Partner/`** — supporting subdomain (master data), **not** a policy kind
- **`Port/` · `InMemory/`** — hexagon edge / outside

## Structure status: implemented (slices 1–4)

The folders above **are** the structure (no longer just a target): `Shared→Substrate`,
`Tax/Assets/Costing→Policies/Expansion`, `Projection/Mapping→Policies/Projection`; `Ledger/`
split across `Substrate/` (primitives+enums) · `Records/` (Voucher/OpenItem/Audit) ·
`Policies/Constraint/` (DimensionRegistry) · `Policies/Expansion/` (Settlement) — `Ledger.php`
stayed as the **orchestrator** in `Ledger/`. Each slice green (PHPStan/PHPUnit + `fixtures` +
`make cross`), PHP + Node 1:1. `Records/` may reference the substrate (data layer); the
substrate boundary (lint/arch test) forbids `Policies/` + upper layers.

## Gated — not solvable with folders

- **In `Policies/Expansion/` socket and DE paradigm are fused**: `TaxService.php` branches
  on `reverse_charge`/`intra_community_supply`. Separating that hangs on the open
  **closed/open** decision (see „target model vs. status" below). The folder shows the
  layer, **not** the seam within it.
- **`Ledger.php` (orchestrator in `Ledger/`) fuses internally** post (substrate) + settle/reverse
  (expansion) + close (constraint) into *one* class — the **method** disentanglement is the
  closed/open-gated surgery, separate from the (done) directory split.

## Engine bundle & target model vs. status

**Engine bundle:** the engine eats *one* resolved `ruleModules` bundle (`profiles/chartsOfAccounts/taxCodes/
mappings/assetAccounts/depreciation/packPolicy`); reached **inline** (bundle directly) or **composed** (manifest →
`PackResolver`). `packPolicy` parametrizes jurisdiction-free (`currencyScale`→`Currency`, `taxRoundingGranularity`→`TaxService`).

**Target model vs. today's status (honest — otherwise it drifts):** the socket/plug picture is the **target**. Today
only infrastructure ports (Clock/Id/Repositories) + the bundle as *data* are injected; the three policy kinds
are **not yet** built as ports (`TaxService.php`/`AssetService.php` are concrete classes). **Open
decision:** whether the mechanism repertoire is *closed* (core never grows, pack = selection only) or *open*
(grows only law-free + visible). Do not guess.
