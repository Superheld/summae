# Abdeckungsstand (2026-06-08, nach SPEC-FINDINGS v0.5)

Automatisch prüfbar via `python3 validate.py`. Fixtures: 43.

## Fehlercodes: 34 / 34 ✅

v0.5 (aus PHP-Implementierungs-Findings): E_VOUCHER_UNKNOWN (voucher-unknown), E_FISCALYEAR_UNFINALIZED_ENTRIES (fiscalyear-close-guard), E_COSTING_RUN_UNKNOWN (costing-run-unknown).

## Standardfälle: 25 / 26 — offen nur SF-15 (braucht zweite Implementierung)

Neu (v0.4): SF-21 partner-and-ec-sales · SF-22 payroll-entry · SF-23 entertainment-split · SF-24 customer-credit-note · SF-25 profit-appropriation · SF-26 money-transit. Dazu: service-date-rate-change (Leistungsdatum/§ 27), monthly-income-statement (BWA-Grundlage), fiscal-year-management.

## Review-Befunde (review-2026-06-07.md): alle eingearbeitet

G1 → two-year-carryover, opening-balance-takeover · G2 → settlement-discount, settlement-bad-debt · G3 → audit-trail · M1 → vat-return-cash-basis · M2 → reverse-charge · M3 → advance-payment · M4 → finalize-reverse-period (erweitert) · M5 → deviating-fiscal-year · M6 → siehe Hinweis unten · M7 → non-cash-benefit · K5 → mixed-tax-rates · übrige K → Spec-/Doku-Fixes.

*Hinweis M6 (cash-Subtype):* formal in R1/api.md verankert („Geldkonto := subtype ∈ {bank, cash}"); eigene Kassen-Fixture folgt, falls ein Kassenmodul je Scope wird (§ 146a/TSE bleibt out of scope).

## Standardfälle: 19 / 20

Neu (v0.3): SF-17 opening-balance-takeover ✅ · SF-18 settlement-discount ✅ · SF-19 advance-payment ✅ · SF-20 non-cash-benefit ✅ — einzig offen bleibt SF-15 (braucht zweite Implementierung).

### Ursprüngliche Liste

| SF | Fixture |
|---|---|
| 01 | create-tenant-profile ✅ |
| 02/03 | tax-expansion, post-and-invariants, create-tenant-profile ✅ |
| 04 | open-items-settlement ✅ |
| 05 | gwg-and-depreciation ✅ |
| 06/07 | finalize-reverse-period, period-ordering ✅ |
| 08 | cash-basis-ten-day-rule ✅ |
| 09 | vat-return ✅ |
| 10 | balance-sheet-mapping ✅ |
| 11 | small-business-switch ✅ |
| 12 | allocation-run, allocate-largest-remainder ✅ |
| 13 | accounts-and-import ✅ |
| 14 | journal-export-z3 ✅ |
| 15 | — per Definition erst mit zwei Implementierungen testbar (Cross-Test-Protokoll steht in `README.md`) |
| 16 | cash-basis-ten-day-rule (asOf 2030) ✅ |

## Determinismus-Pflichtfälle: 5 / 5 ✅

half-up-Falle · USt pro Beleg · allocate largest-remainder · Sortierung führende Nullen · AfA-Monatsraten (depreciation-monthly-allocation: 1–28 je 27,78, 29–36 je 27,77, Σ exakt 1.000,00).

## Zusätzlich abgedeckt

Mapping-Import inkl. `E_MAPPING_OVERLAP` und gapWarnings (mapping-import) · DATEV-Buchungsstapel-Export mit BU-Alias (datev-export, F-IO-005).

## Fazit

**Suite komplett als Implementierungs-Vorgabe für Phase 4:** Eine Implementierung, die diese 20 Fixtures (plus Determinismus-Doppellauf) besteht, erfüllt den Vertrag. Einziger strukturell offener Fall: SF-15 (Cross-Implementierung) — wird mit der zweiten Implementierung (Node) scharf geschaltet.
