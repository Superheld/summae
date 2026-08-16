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
stayed as the **orchestrator** in `ledger/` (whose own methods were split in turn, see below).
Each slice green (typecheck/lint/test + `fixtures --strict`
+ `make cross`), PHP + Node 1:1. `records/` may reference the substrate (data layer); the
substrate boundary (lint/arch test) forbids `policies/` + upper layers.

## Gated — tax seam resolved (A1), the rest still method-level

- **The tax-mechanism seam is now an addressable registry** (A1, byte-identical): `tax-service.ts`
  delegates to `tax-mechanisms.ts` (`mechanismFor` → `Standard`/`ReverseCharge`/`IntraCommunitySupply`
  strategies) instead of an inline switch — the **form** the socket calls for. It is core-internal, which
  since 2026-08-16 is also the **decided** shape (closed repertoire — see below). A new mechanism (e.g.
  `exempt`) is just a fourth registered strategy.
- **`ledger.ts` is disentangled** (surgery B, 2026-08-16, byte-identical): it keeps the operations that
  *write postings* — `post`/`correct`/`finalize`/`reverse` — plus the line parsing they share, and is a
  thin **facade** over `settlement-service.ts` (expansion), `chart-admin-service.ts` (setup) and
  `fiscal-period-service.ts` (constraint); `audit-writer.ts` and the free functions in `lookups.ts` carry
  what all of them need. The facade is the point: `TenantOperations` and every adapter still see one
  object, so the seam is internal. 879 → 520 lines.

## Engine bundle & target model vs. status

**Engine bundle:** the engine eats *one* resolved `ruleModules` bundle (`profiles/chartsOfAccounts/taxCodes/
mappings/assetAccounts/depreciation/packPolicy`); reached **inline** (bundle directly) or **composed** (manifest →
`PackResolver`). `packPolicy` parametrizes jurisdiction-free (`currencyScale`→`Currency`, `taxRoundingGranularity`→`TaxService`).

**Target model vs. today's status (honest — otherwise it drifts):** the socket/plug picture is the **target**. Today
only infrastructure ports (Clock/Id/Repositories) + the bundle as *data* are injected; the three policy kinds
are **not yet** built as ports (`tax-service.ts`/`asset-service.ts` are concrete classes).

**Decided 2026-08-16 — the mechanism repertoire is *closed*.** A new tax mechanism is registered in
`tax-mechanisms.ts` inside the core, in **both** languages, with a fixture; the pack selects one per tax code
via `version.mechanism` and never carries code. The reason is the top quality policy, not distrust of the
embedder: a mechanism registered from *outside* would be **different code in PHP than in Node**, so "same input
→ same result regardless of language" would stop holding for it, and the shared oracle could not check it — the
cross-test would silently prove less than it does today. The cost is low: `exempt` showed that a new mechanism
is four registered lines.

**What would reopen it:** this seam covers only *line assembly* — the mechanism receives an already-computed,
already-rounded tax amount (`base × rate / 100` sits in `tax-service.ts`). The variance that actually differs
between jurisdictions is elsewhere and has **no socket at all**: tax-inclusive/gross-up bases (Brazil, Odoo's
`division`), compound bases (Canadian PST on a GST-inclusive base), tax at payment time (withholding, split
payment), margin schemes. If the **base computation** ever becomes its own socket, a mechanism becomes
describable as data — today's four differ only in accounts/sides/reporting keys/gross delta — and closed/open
is a different question with possibly a different answer. Until then it is settled.

**Hardening done 2026-08-16:** `mechanismFor` no longer falls back to the standard mechanism for an unknown name — it raises `E_PACK_INCOHERENT`. Under a closed repertoire an unlisted name is a typo or a pack built against a newer core, and both used to book plain VAT silently: `reverse-charge` instead of `reverse_charge` produced a normal tax line on the normal account in the normal VAT box. The resolver calls the same function, so a composed pack now fails at `resolvePack`/`init` rather than at the first posting. Fixture `resolver-unknown-mechanism`.