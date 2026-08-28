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
  - **`Constraint/`** — predicate gates. Has a pack socket since 2026-08-23 (module kind `constraint`),
    two predicates since 2026-08-28: `dimensionRules` (which accounts may not be posted without
    which dimension) and `accountCombinationRules` (which accounts must, or must not, meet in one
    entry — F-CORE-042, the A-13 case). Both see one entry at most: no deadlines, no reach across
    entries, no rule about a settlement, because `settle` posts nothing
- **`Composition/`** — resolver · factory · tenant · dispatcher (dependency inversion)
- **`Records/`** — vouchers/records (Voucher · OpenItem · Audit), **not** a policy kind
- **`Partner/`** — supporting subdomain (master data), **not** a policy kind
- **`Port/` · `InMemory/`** — hexagon edge / outside

## Layer boundary (enforced)

`records/` may reference the substrate (data layer); the substrate boundary — a lint/arch test,
not review — forbids `policies/` and everything above it from being imported there.

## Where the two seams sit

- **Tax mechanisms are a registry, not a switch:** `TaxService.php` delegates to
  `TaxMechanisms::mechanismFor` (`Standard`/`ReverseCharge`/`IntraCommunitySupply`/`Exempt`). Core-internal
  by decision (closed repertoire, below); a new mechanism is one more registered strategy plus a fixture.
- **`Ledger.php` is a facade:** it keeps the operations that *write postings* — `post`/`correct`/`finalize`/
  `reverse` — plus their shared line parsing, and delegates the rest to `SettlementService` (expansion),
  `ChartAdminService` (setup) and `FiscalPeriodService` (constraint); `AuditWriter` and the static `Lookups`
  carry what all of them need. `TenantOperations` and every adapter still see one object.

## Engine bundle & target model vs. status

**Engine bundle:** the engine eats *one* resolved `ruleModules` bundle (`profiles/chartsOfAccounts/taxCodes/
mappings/assetAccounts/depreciation/packPolicy`); reached **inline** (bundle directly) or **composed** (manifest →
`PackResolver`). `packPolicy` parametrizes jurisdiction-free (`currencyScale`→`Currency`, `taxRoundingGranularity`→`TaxService`).

**Target model vs. today's status (honest — otherwise it drifts):** the socket/plug picture is the **target**. Today
only infrastructure ports (Clock/Id/Repositories) + the bundle as *data* are injected; the three policy kinds
are **not yet** built as ports (`TaxService.php`/`AssetService.php` are concrete classes).

**Decided 2026-08-16 — the mechanism repertoire is *closed*.** A new tax mechanism is registered in
`TaxMechanisms.php` inside the core, in **both** languages, with a fixture; the pack selects one per tax code
via `version.mechanism` and never carries code. The reason is the top quality policy, not distrust of the
embedder: a mechanism registered from *outside* would be **different code in PHP than in Node**, so "same input
→ same result regardless of language" would stop holding for it, and the shared oracle could not check it — the
cross-test would silently prove less than it does today. The cost is low: `exempt` showed that a new mechanism
is four registered lines.

**What would reopen it:** this seam covers only *line assembly* — the mechanism receives an already-computed,
already-rounded tax amount (`base × rate / 100` sits in `TaxService.php`). The variance that actually differs
between jurisdictions is elsewhere and has **no socket at all**: tax-inclusive/gross-up bases (Brazil, Odoo's
`division`), compound bases (Canadian PST on a GST-inclusive base), tax at payment time (withholding, split
payment), margin schemes. If the **base computation** ever becomes its own socket, a mechanism becomes
describable as data — today's four differ only in accounts/sides/reporting keys/gross delta — and closed/open
is a different question with possibly a different answer. Until then it is settled.
