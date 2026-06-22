# us-pack — United States

The second complete jurisdiction pack: the United States. Selectable as
`createTenant(pack: "us")`. **Self-contained:** all modules live in this folder, no
module shared with other packs (packs do not build on each other). **Own chart of
accounts** — the US has no statutory chart (US-GAAP leaves the account structure free),
so we manage the accounts ourselves on the same accepted number set as the de-pack
(4-digit, account class = leading digit), with English names and US-specific accounts in
the gaps. Base currency **USD** is set when creating the tenant (the pack carries only
rounding/granularity/scale, not a currency).

## What's inside (modules → the manifest `us.json` composes them)

| Module | kind | Content |
|---|---|---|
| `accounts/us-accounts` | accounts | Own US chart, **40 accounts** (32 base + 8 US extras: 1900 prepaid · 3130 sales tax payable · 3140 use tax payable · 3900 deferred revenue · 4020 returns & allowances · 4030 sales discounts · 4040 exempt sales · 6020 use tax expense) |
| `tax/us-salestax` | tax | SALETAX (single-stage retail sales tax, 7% placeholder), USETAX (self-assessed use tax → cost + liability), EXEMPT (resale/interstate/nontaxable, rate 0) |
| `mappings/us-balance-sheet` | mapping | Classified Balance Sheet US-GAAP (assets ordered by liquidity) |
| `mappings/us-income-statement` | mapping | Multi-Step Income Statement US-GAAP (by function) |
| `mappings/us-schedule-c` | mapping | Cash-basis categories — IRS Schedule C (Form 1040) |
| `depreciation/us-macrs` | depreciation | De-minimis safe harbor (2,500 USD, no pool), MACRS GDS recovery periods as useful lives |
| `assets/us-assets` | assetAccounts | Asset movement accounts (acquisition counter, depreciation, de minimis, disposal) |
| `policy/us` | policy | USD, half-up per voucher (`perVoucher`), scale 2; defaults: accrual (GAAP), standard taxation, quarterly |

The single-stage sales tax (no input-tax credit), use tax, economic nexus (Wayfair),
de minimis safe harbor, MACRS, and the GAAP statement structures are maintained
internally; the rules this pack implements are listed above and proven by the
conformance fixtures below.

## How the US differs from Germany (in the data, not the engine)

Same module kinds and manifest mechanics as the de-pack; the differences are purely in
the **domain logic**:

- **Sales tax is single-stage** (retail, no input-tax credit) — German VAT is multi-stage
  with deductible input tax. SALETAX books a pure liability on 3130, no recoverable counterpart.
- **Use tax** reuses the `reverse_charge` mechanism, but the input leg points at an **expense**
  account (6020) instead of a recoverable-tax account → the tax becomes **cost + liability**,
  not net zero (the opposite of German §13b).
- **US-GAAP** orders balance-sheet assets by liquidity (reverse of HGB §266) and presents a
  multi-step, by-function income statement (vs. the German total-cost method).
- **De minimis safe harbor** (2,500 USD, no pool) replaces the German low-value-asset rules;
  MACRS GDS recovery periods stand in for the German depreciation tables.
- **Default `taxationMethod` is `accrual`** (GAAP norm) — the de-pack starts on `cash` (EÜR).

## Conformance — each module tests its requirements

All fixtures under `testsuite/fixtures/pack/us-pack/` run **purely from this library** (no
inline) and are green in **PHP and Node** (`--strict`, byte-identical double run), against
both the in-memory core and the database subject. Module → requirement → test:

| Module | Requirement | Test fixture |
|---|---|---|
| accounts (`us-accounts`) | resolves, 40 accounts, selectable as a pack | `us-pack-resolves` |
| tax · SALETAX standard | F-TAX-002 / SF-02 | `us-pack-resolves`, `us-sales-tax`, `us-fiscal-year` |
| tax · USETAX (cost + liability) | F-TAX-006 | `us-use-tax` |
| tax · EXEMPT (rate 0, base tag) | F-TAX-004 | `us-exempt-sale` |
| mappings · balance sheet + income statement | F-CORE-015 / SF-10 | `us-balance-income`, `us-fiscal-year` |
| depreciation + assetAccounts (de minimis 2,500, MACRS 60 mo) | F-AST-001/002/003 / SF-05 | `us-depreciation`, `us-fiscal-year` |
| policy · perVoucher/scale 2/accrual | Determinism | `us-pack-resolves` |
| **Integration** (balance sheet correct at all times) | F-CORE-015 / F-CORE-016 / SF-10 | `us-fiscal-year` |

`us-fiscal-year` is the continuous end-to-end test of a fiscal year (taxable sale → deferred
revenue → capitalized asset + de-minimis immediate expense → SG&A → depreciation) with a
balanced balance sheet at mid-year and year-end.

> **Sign-off pending.** A few human decisions from the build are still open (account-number
> sign-off, use-tax naming, default taxation method, multi-state strategy, the
> `cash-basis-categories` schema gap NF-002/F-008). See `99-pack-docs/us-pack/offene-entscheidungen.md`.
