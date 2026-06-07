# Abdeckungsstand (2026-06-07, final)

Automatisch prüfbar via `python3 validate.py`. Fixtures: 20.

## Fehlercodes: 27 / 27 ✅

## Standardfälle: 15 / 16

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
