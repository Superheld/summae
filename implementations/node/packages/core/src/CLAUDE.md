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
  - **`constraint/`** — predicate gates. Has a pack socket since 2026-08-23 (module kind `constraint`),
    two predicates since 2026-08-28: `dimensionRules` (which accounts may not be posted without
    which dimension) and `accountCombinationRules` (which accounts must, or must not, meet in one
    entry — F-CORE-042, the A-13 case). Both see one entry at most: no deadlines, no reach across
    entries, no rule about a settlement, because `settle` posts nothing
- **`composition/`** — resolver · factory · tenant · dispatcher (dependency inversion)
- **`records/`** — vouchers/records (voucher · open-item · audit), **not** a policy kind
- **`partner/`** — supporting subdomain (master data), **not** a policy kind
- **`ports/` · `adapters/`** — hexagon edge / outside

## Layer boundary (enforced)

`records/` may reference the substrate (data layer); the substrate boundary — a lint/arch test,
not review — forbids `policies/` and everything above it from being imported there.

## Where the two seams sit

- **The tax expansion has TWO seams.** `taxBase` (`net`|`inclusive`) decides how an amount splits into base and
  tax — pack data, closed enum, `tax-bases.ts` / `TaxBases.php`; the mechanism then decides which accounts and
  keys the tax lands on. Base first, assembly second.
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

**What would reopen it — and what happened to half of it on 2026-08-28.** This seam covers only *line
assembly*: the mechanism receives an already-computed, already-rounded tax amount. The variance that actually
differs between jurisdictions sits **before** it, and used to have no socket at all. It has one now for the
commonest case: `taxBase` (`net` | `inclusive`, F-TAX-010, `tax-bases.ts` / `TaxBases.php`) lets a pack say
that the amount handed in is the gross, which is how most of the world quotes prices.

**That does not reopen the repertoire, and saying why is the point.** The remaining variance still has no
socket and is not a base *function* at all: a **compound** base (Canadian PST on a GST-inclusive amount) needs
another code's result and therefore an ordering between codes; **tax at payment time** (withholding, split
payment) is a timing question; a **margin scheme** needs the purchase price of the thing sold, which is not in
the posting. Until a mechanism is describable as pure data — today's four differ in accounts, sides, reporting
keys and gross delta, and now also in nothing else — closed/open stays settled.

**Decided 2026-08-28 — the account `subtype` repertoire is *closed* too (F-CORE-046).** Third time
this shape has come up and third identical answer, which is why it is written here rather than
argued again: `subtype` tells the engine what an account *is* (profit-neutral movement, which side
a tax account's tax stands on, which posting opens a receivable) and it was a free string, so
`tax-out` for `tax_out` produced an account that looked annotated and was inert — the VAT return
skipped it and nothing said a tax account had gone missing. Eleven values in
`AccountSubtype.php` / `substrate/types.ts`, byte-equal in both languages. Enforced at the two
places a subtype is **authored** — pack resolution (`E_PACK_INCOHERENT`) and the chart API
(`E_INPUT_INVALID`, `E_COA_FORMAT_INVALID`) — and deliberately **not** in the `Account`
constructor, where it would be shortest: hydration runs through that constructor, so a database
written before the repertoire existed would stop loading, and a validation that refuses to read
back what it once wrote is the worse failure. Three of the eleven (`fixed_asset`,
`opening_balance`, `private`) are annotation no code consults; they are in the list because every
shipped pack uses them, and a repertoire of only the eight would refuse all three shipped charts.
**What would reopen it:** a pack needing an account role the engine genuinely does not have — the
answer then is a twelfth registered value with its reader, not a free string again.

**And on 2026-08-29 exactly that happened, which is why this paragraph is worth keeping rather than
rewriting.** Stock was the missing role: `inventory` is the twelfth value, and it arrived the way
the sentence above said it should — registered, with its reader (`InventoryService` refuses to value
stock onto an account that is not a stock account, `E_INVENTORY_ACCOUNT_INVALID`). Without the
reader the posting would balance, satisfy every invariant, and land in the wrong balance-sheet
position, which is the same inert-annotation defect one step worse: a wrong number instead of a
missing one. The repertoire is closed at twelve on the same terms.
