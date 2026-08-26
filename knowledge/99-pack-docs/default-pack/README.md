# `default`-Pack — der jurisdiktionsfreie Fall

Das Pack für Bestände, die **keine** Rechtsordnung behaupten sollen: ein neutraler
Kontenrahmen und die Ergebnisverwendung darauf. Kein Steuerrecht, keine Abschreibungstabellen,
keine Gliederungen.

> **Status:** Beschreibung der ausgelieferten Module unter `pack-library/default-pack/`.
> Normativer Format-Vertrag: `50-spezifikation/datenformat.md`. Orakel:
> `testing/testsuite/fixtures/pack/default-pack/`.

## Module

| # | `kind` | `id` | Datei |
|---|---|---|---|
| 1 | `accounts` | `neutral` | [modul-1-konten-neutral.md](modul-1-konten-neutral.md) |
| 2 | `resultAppropriation` | `neutral-appropriation` | [modul-2-result-appropriation.md](modul-2-result-appropriation.md) |

## Wofür es gedacht ist

Zwei Verwendungen, und die zweite ist die wichtigere:

1. **Ein Bestand ohne Jurisdiktion** — Buchführung, Journal, Salden, Kontoblätter, alles was das
   Substrat kann, ohne Steuer und ohne gesetzliche Gliederung.
2. **Die Vorlage für ein neues Pack.** Wer `fr` oder `at` baut, kopiert diesen Rahmen und macht
   ihn eigenständig; Packs bauen **nicht** aufeinander auf, es gibt kein geteiltes Basismodul.
   Das Test-Pack `xx` in den Fixtures ist genauso gebaut.

## Was es bewusst nicht kann

Ohne `mapping`-Modul sind `balanceSheet`, `incomeStatement` und `cashBasisReport` nicht
aufrufbar — sie verlangen alle einen `mapping`-Parameter, und das Pack liefert keinen. Das ist
Absicht (eine Gliederung ist immer jurisdiktionell), aber heute erfährt es der Aufrufer über
`E_INPUT_INVALID` statt über eine Aussage: **IMPL-032** in `SPEC-FINDINGS.md`.
