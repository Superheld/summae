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

## Layer boundary (enforced)

`records/` may reference the substrate (data layer); the substrate boundary — a lint/arch test,
not review — forbids `policies/` and everything above it from being imported there.

## Where the two seams sit

- **Tax mechanisms are a registry, not a switch:** `tax-service.ts` delegates to `mechanismFor` in
  `tax-mechanisms.ts` (`Standard`/`ReverseCharge`/`IntraCommunitySupply`/`Exempt`). Core-internal by
  decision (closed repertoire, below); a new mechanism is one more registered strategy plus a fixture.
- **`ledger.ts` is a facade:** it keeps the operations that *write postings* — `post`/`correct`/`finalize`/
  `reverse` — plus their shared line parsing, and delegates the rest to `settlement-service.ts` (expansion),
  `chart-admin-service.ts` (setup) and `fiscal-period-service.ts` (constraint); `audit-writer.ts` and the
  free functions in `lookups.ts` carry what all of them need. `TenantOperations` and every adapter still
  see one object.

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
