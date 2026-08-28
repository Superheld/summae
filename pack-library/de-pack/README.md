# de-pack — Germany

The first complete jurisdiction pack: Germany. Selectable as `createTenant(pack: "de")`.
**Current chart version: `2026.4`** — raised from `2026.3` when the chart gained seven
operating-expense accounts (6030–6090) and `de-euer` moved from an explicit number list to
ranges, so that the chart can be extended without one statement losing the accounts the other
sees. Modules version independently; the manifest pins the exact ones it wants, and a published
version keeps resolving — superseded module files stay in `versions/`.
**Self-contained:** all modules live in this folder, no module shared with other packs
(packs do not build on each other).

## Extending the chart — and the one limit that is real

**Own chart of accounts** (no SKR adopted): we manage the accounts ourselves, on neutral numbers.

Adding accounts is safe **within the bands the mappings cover by range**, and after 2026.4 that is
all of them: operating expenses `6000–6099` and `6700–6899`, personnel `6300–6399`, depreciation
`6500–6599`, materials `5000–5999`, revenue `4000–4099`. An account added there reaches the
balance sheet, the income statement *and* the EÜR (fixture `de-aufwandskonten-erweiterbar`).
Three numbers are claimed individually because the law treats them individually — `6010`/`6020`
(entertainment, deductible / non-deductible) and `6900` (carrying amount on disposal) — so do not
extend *between* them. Anything the mappings do not know still lands in the reports under its own
account name, and `importMapping` and `cashBasisReport` say so in `gapWarnings`; that warning is
the safety net, not the plan.

> ⚠ **SKR03/04 are a different matter, and the earlier wording here was misleading.** The accounts
> can be created via `importChartOfAccounts` — that part is true and always was. What does not come
> with them is the three mappings: `de-bilanz`, `de-guv` and `de-euer` all reference **this
> chart's** numbers, so a tenant on an SKR03 chart gets a balance sheet, an income statement and an
> EÜR that find almost nothing. "Loadable" was true in the narrow sense and false in the sense a
> reader takes from it. Until SKR03/04 mappings exist in this library, an SKR chart means bringing
> your own mappings with it.

## What's inside (modules → the manifest `de.json` composes them)

| Module | kind | Content |
|---|---|---|
| `accounts/de-konten` | accounts | Own DE chart of accounts (standard + DE extras: 1250 current-asset securities · 4020 taxable cash discount/revenue reduction · 5010 taxable received discount · 4030 intra-community supplies · 4040 small-business revenue · 4050 deemed supply · 6010/6020 entertainment · 1900/3900 accruals/deferrals · 6030–6090 operating expenses) |
| `tax/de-ust` | tax | USt19, USt7, VSt19, VSt7, RC13b (§13b), igL (intra-community supply), IGE19/IGE7 (intra-community acquisition, Kz 89/93 with input tax on Kz 61), AUSFUHR (exempt export to a third country, Kz 43), USt19WA (deemed supply) — rates/codes, accounts on neutral numbers |
| `mappings/de-bilanz` | mapping | Balance-sheet structure HGB §266 |
| `mappings/de-guv` | mapping | Income-statement structure HGB §275 (total-cost method) |
| `mappings/de-euer` | mapping | Cash-basis categories — Einnahmen-Überschuss-Rechnung §4 Abs. 3 EStG (Anlage EÜR) |
| `depreciation/de-afa` | depreciation | Low-value-asset thresholds (§6 (2) EStG), useful lives |
| `assets/de-assets` | assetAccounts | Asset contra-accounts (addition/depreciation/low-value/disposal) on neutral numbers |
| `policy/de` | policy | EUR, half-up per voucher (`perVoucher`), scale 2; defaults: cash (EÜR), standard taxation, quarterly |
| `constraint/de-entgeltminderung` | constraint | § 17 UStG: an entry on 4020 must carry its output-VAT correction, one on 5010 its input-VAT correction (`E_COMBINATION_REQUIRED`) |
| `constraint/de-kleinunternehmer` | constraint | § 19 UStG: an entry on 4040 (small-business revenue) must not touch an output-VAT account (`E_COMBINATION_FORBIDDEN`) |

The German tax/HGB background (VAT §13b, intra-community supply, small business §19, deemed
supply, EÜR, HGB balance sheet/P&L, depreciation) is maintained internally; the rules this pack
implements are listed above and proven by the conformance fixtures below.

## Conformance — each module tests its requirements

All fixtures under `testing/testsuite/fixtures/pack/de-pack/` run **purely from this library** (no
inline) and are green in **PHP and Node** (`--strict`, byte-identical double run). Module → requirement → test:

| Module | Requirement | Test fixture |
|---|---|---|
| accounts (`de-konten`) | resolves, selectable as a pack | `de-pack-resolves-current` |
| accounts (`de-konten`) | the chart can be extended without a statement losing the account | `de-aufwandskonten-erweiterbar` |
| tax · USt19 standard rate | F-TAX-002 / SF-02 | `de-pack-resolves-current`, `de-jahresgang-current` |
| tax · USt7 reduced | F-TAX-002 | `de-ust7-ermaessigt` |
| tax · VSt19 input tax | F-TAX-002 / SF-03 | `de-eingangsrechnung` |
| tax · VSt7 reduced input tax | F-TAX-002 / SF-03 | `de-vorsteuer-ermaessigt` |
| tax · RC13b §13b | F-TAX-006 | `de-reverse-charge` |
| tax · igL | F-TAX-012 / SF-21 | `de-ig-lieferung` |
| tax · USt19WA deemed supply | F-TAX-010 / SF-20 | `de-wertabgabe` |
| tax · IGE19/IGE7 intra-community acquisition | F-TAX-006 / SF-04 | `de-ig-erwerb` |
| tax · AUSFUHR exempt export | F-TAX-007 / SF-04 | `de-ausfuhr` |
| tax · VAT return | F-TAX-005 / SF-09 | `de-vat-return` |
| de-konten · 4020 cash discount §17 | F-TAX-008 / SF-18 | `de-skonto`, `de-jahresgang-current` |
| de-entgeltminderung · §17 correction enforced | F-CORE-042 | `de-entgeltminderung-erzwungen-current` |
| de-kleinunternehmer · §19 no VAT shown | F-CORE-042 / F-TAX-004 / SF-11 | `de-kleinunternehmer-ust-verboten` |
| de-konten · 6010/6020 entertainment §4(7) | SF-23 | `de-bewirtung` |
| de-konten · 4040 small business §19 | F-TAX-004 / SF-11 | `de-kleinunternehmer` |
| de-konten · 1900/3900 accruals/deferrals | HGB §266 | `de-jahresgang-current` |
| mappings · de-bilanz §266 + de-guv §275 | F-CORE-015 / SF-10 | `de-bilanz-guv`, `de-jahresgang-current` |
| mappings · de-euer (Anlage EÜR §4 Abs. 3) | F-CORE-008/010 / SF-08 | `de-euer` |
| depreciation + assetAccounts | F-AST-001/002/003 / SF-05 | `de-afa-lauf`, `de-jahresgang-current` |
| policy · perVoucher/scale 2 | Determinism | `de-pack-resolves-current`; mechanism `conformance-xx` |
| **Integration** (balance sheet + journal correct at all times) | F-CORE-016 / SF-10 | `de-jahresgang-current` |

`de-jahresgang-current` is the continuous end-to-end test of a fiscal year (posting → input tax →
cash discount → asset addition → accrual/deferral → depreciation) with a balanced balance sheet
at several reporting dates.

**Also tested from the frontend (CLI):** `summae init --pack de` loads the pack from the library —
in **both** CLIs (PHP + Node). The end-to-end smoke test (init → post → balanced balance sheet) is
green in both CLI test suites. Usage: `docs/handbuch` § 3.
