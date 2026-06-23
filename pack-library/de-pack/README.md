# de-pack — Germany

The first complete jurisdiction pack: Germany. Selectable as `createTenant(pack: "de")`.
**Self-contained:** all modules live in this folder, no module shared with other packs
(packs do not build on each other). **Own chart of accounts** (no SKR adopted) — we manage
the accounts ourselves; SKR03/04 remain loadable via `importChartOfAccounts`.

## What's inside (modules → the manifest `de.json` composes them)

| Module | kind | Content |
|---|---|---|
| `accounts/de-konten` | accounts | Own DE chart of accounts, **40 accounts** (standard + DE extras: 4020 cash discount/revenue reduction · 4030 intra-community supplies · 4040 small-business revenue · 4050 deemed supply · 6010/6020 entertainment · 1900/3900 accruals/deferrals) |
| `tax/de-ust` | tax | USt19, USt7, VSt19, VSt7, RC13b (§13b), igL (intra-community supply), USt19WA (deemed supply) — rates/codes, accounts on neutral numbers |
| `mappings/de-bilanz` | mapping | Balance-sheet structure HGB §266 |
| `mappings/de-guv` | mapping | Income-statement structure HGB §275 (total-cost method) |
| `mappings/de-euer` | mapping | Cash-basis categories — Einnahmen-Überschuss-Rechnung §4 Abs. 3 EStG (Anlage EÜR) |
| `depreciation/de-afa` | depreciation | Low-value-asset thresholds (§6 (2) EStG), useful lives |
| `assets/de-assets` | assetAccounts | Asset contra-accounts (addition/depreciation/low-value/disposal) on neutral numbers |
| `policy/de` | policy | EUR, half-up per voucher (`perVoucher`), scale 2; defaults: cash (EÜR), standard taxation, quarterly |

The German tax/HGB background (VAT §13b, intra-community supply, small business §19, deemed
supply, EÜR, HGB balance sheet/P&L, depreciation) is maintained internally; the rules this pack
implements are listed above and proven by the conformance fixtures below.

## Conformance — each module tests its requirements

All fixtures under `testsuite/fixtures/pack/de-pack/` run **purely from this library** (no
inline) and are green in **PHP and Node** (`--strict`, byte-identical double run). Module → requirement → test:

| Module | Requirement | Test fixture |
|---|---|---|
| accounts (`de-konten`) | resolves, 40 accounts, selectable as a pack | `de-pack-resolves` |
| tax · USt19 standard rate | F-TAX-002 / SF-02 | `de-pack-resolves`, `de-jahresgang` |
| tax · USt7 reduced | F-TAX-002 | `de-ust7-ermaessigt` |
| tax · VSt19 input tax | F-TAX-002 / SF-03 | `de-eingangsrechnung` |
| tax · VSt7 reduced input tax | F-TAX-002 / SF-03 | `de-vorsteuer-ermaessigt` |
| tax · RC13b §13b | F-TAX-006 | `de-reverse-charge` |
| tax · igL | F-TAX-012 / SF-21 | `de-ig-lieferung` |
| tax · USt19WA deemed supply | F-TAX-010 / SF-20 | `de-wertabgabe` |
| tax · VAT return | F-TAX-005 / SF-09 | `de-vat-return` |
| de-konten · 4020 cash discount §17 | F-TAX-008 / SF-18 | `de-skonto`, `de-jahresgang` |
| de-konten · 6010/6020 entertainment §4(7) | SF-23 | `de-bewirtung` |
| de-konten · 4040 small business §19 | F-TAX-004 / SF-11 | `de-kleinunternehmer` |
| de-konten · 1900/3900 accruals/deferrals | HGB §266 | `de-jahresgang` |
| mappings · de-bilanz §266 + de-guv §275 | F-CORE-015 / SF-10 | `de-bilanz-guv`, `de-jahresgang` |
| mappings · de-euer (Anlage EÜR §4 Abs. 3) | F-CORE-008/010 / SF-08 | `de-euer` |
| depreciation + assetAccounts | F-AST-001/002/003 / SF-05 | `de-afa-lauf`, `de-jahresgang` |
| policy · perVoucher/scale 2 | Determinism | `de-pack-resolves`; mechanism `conformance-xx` |
| **Integration** (balance sheet + journal correct at all times) | F-CORE-016 / SF-10 | `de-jahresgang` |

`de-jahresgang` is the continuous end-to-end test of a fiscal year (posting → input tax →
cash discount → asset addition → accrual/deferral → depreciation) with a balanced balance sheet
at several reporting dates.

**Also tested from the frontend (CLI):** `summae init --pack de` loads the pack from the library —
in **both** CLIs (PHP + Node). The end-to-end smoke test (init → post → balanced balance sheet) is
green in both CLI test suites. Usage: `docs/handbuch` § 3.
