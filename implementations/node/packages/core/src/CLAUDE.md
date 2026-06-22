# CLAUDE.md — `core/src/` (architecture of the domain core)

Two **axes** — keep both visible here. Structure 1:1 identical in PHP and Node
(PascalCase folders there). The big picture + build status: root `CLAUDE.md`.

## Axis 1 — hexagonal (framework/persistence freedom)

```
        ┌──────────── adapters (outside) ──────────┐
        │   in-memory · [knex] · [laravel]          │
        │   ┌────────── ports (edge) ───────────┐   │
        │   │   ┌──────── domain (inside) ────┐  │   │
        │   │   │  substrate (frozen)          │  │   │
        │   │   │  policies = SOCKET           │  │   │
        │   │   │  composition (wiring)        │  │   │
        │   │   └──────────────────────────────┘  │   │
        │   └────────────────────────────────────┘   │
        └────────────────────────────────────────────┘
  PLUGS (data) live in /pack-library/ ──injected──▶ into the sockets
  Dependency points only inward · pack depends on the core, never the reverse.
```

Real persistence (`knex`/`laravel`) are **own packages** outside of `core`; in
`core` only the in-memory adapters live (fakes).

## Axis 2 — substrate → policy kinds → pack (jurisdiction freedom)

- **`substrate/`** — frozen, jurisdiction-free (posting sum 0, account, journal,
  balance, period). Does not grow. **Imports nothing from above.**
- **`policies/`** — the THREE policy kinds; here only the **socket** (law-free mechanism),
  the **plugs** (data) live in `/pack-library/` and are injected:
  - **`expansion/`** — intent → balanced postings (tax · assets · costing · settle difference · reverse)
  - **`projection/`** — journal → view (fold engines + mappings)
  - **`constraint/`** — predicate gates (still thin; third kind unfinished)
- **`composition/`** — resolver · factory · tenant · dispatcher (dependency inversion)
- **`records/`** — vouchers/records (voucher · open-item · audit), **not** a policy kind
- **`partner/`** — supporting subdomain (master data), **not** a policy kind
- **`ports/` · `adapters/`** — hexagon edge / outside

## Structure status: implemented (slices 1–4)

The folders above **are** the structure (no longer just a target): `shared→substrate`,
`tax/assets/costing→policies/expansion`, `projection/mapping→policies/projection`; `ledger/`
split across `substrate/` (primitives+enums) · `records/` (voucher/open-item/audit) ·
`policies/constraint/` (dimension-registry) · `policies/expansion/` (settlement) — `ledger.ts`
stayed as the **orchestrator** in `ledger/`. Each slice green (typecheck/lint/test + `fixtures --strict`
+ `make cross`), PHP + Node 1:1. `records/` may reference the substrate (data layer); the
substrate boundary (lint/arch test) forbids `policies/` + upper layers.

## Gated — not solvable with folders

- **In `policies/expansion/` socket and DE paradigm are fused**: `tax-service.ts` branches
  on `reverse_charge`/`intra_community_supply`. Separating that hangs on the open
  **closed/open** decision (see „target model vs. status" below). The folder shows the
  layer, **not** the seam within it.
- **`ledger.ts` (orchestrator in `ledger/`) fuses internally** post (substrate) + settle/reverse
  (expansion) + close (constraint) into *one* class — the **method** disentanglement is the
  closed/open-gated surgery, separate from the (done) directory split.

## Engine bundle & target model vs. status

**Engine bundle:** the engine eats *one* resolved `ruleModules` bundle (`profiles/chartsOfAccounts/taxCodes/
mappings/assetAccounts/depreciation/packPolicy`); reached **inline** (bundle directly) or **composed** (manifest →
`PackResolver`). `packPolicy` parametrizes jurisdiction-free (`currencyScale`→`Currency`, `taxRoundingGranularity`→`TaxService`).

**Target model vs. today's status (honest — otherwise it drifts):** the socket/plug picture is the **target**. Today
only infrastructure ports (Clock/Id/Repositories) + the bundle as *data* are injected; the three policy kinds
are **not yet** built as ports (`tax-service.ts`/`asset-service.ts` are concrete classes). **Open
decision:** whether the mechanism repertoire is *closed* (core never grows, pack = selection only) or *open*
(grows only law-free + visible). Do not guess.
