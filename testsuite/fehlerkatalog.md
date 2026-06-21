# Fehlerkatalog v0.5

Fehler sind Vertragsbestandteil: gleicher Verstoß → gleicher Code in allen Implementierungen. Jeder Code MUSS mindestens eine Fixture haben (Spalte „Fixture"; ✗ = noch zu bauen). Codes sind stabil — Umbenennung ist ein Breaking Change.

## E_ENTRY — Buchung

| Code | Auslösende Invariante | Fixture |
|---|---|---|
| `E_ENTRY_UNBALANCED` | Σ Soll ≠ Σ Haben (F-CORE-001) | post-and-invariants |
| `E_ENTRY_NO_VOUCHER` | voucherId fehlt (F-CORE-003) | post-and-invariants |
| `E_VOUCHER_UNKNOWN` | voucherId gesetzt, aber kein Beleg vorhanden (v0.5/F-001) | voucher-unknown |
| `E_ENTRY_TOO_FEW_LINES` | < 2 Positionen | post-malformed |
| `E_ENTRY_INVALID_AMOUNT` | Betrag ≤ 0, falsches Format oder Fremdwährung ≠ Mandantenwährung (v1) | post-malformed |
| `E_ENTRY_FINALIZED` | Korrekturversuch nach Festschreibung (F-CORE-002) | finalize-reverse-period |
| `E_ENTRY_ALREADY_REVERSED` | Doppelstorno | finalize-reverse-period |
| `E_ENTRY_UNKNOWN` | entryId existiert nicht | post-malformed |

## E_PERIOD / E_FISCALYEAR

| Code | Invariante | Fixture |
|---|---|---|
| `E_PERIOD_CLOSED` | Buchung in geschlossene Periode (F-CORE-004) | finalize-reverse-period |
| `E_PERIOD_OUT_OF_ORDER` | Schließen außer Reihenfolge | period-ordering |
| `E_PERIOD_UNKNOWN` | Buchungsdatum außerhalb angelegter Geschäftsjahre | period-ordering |
| `E_FISCALYEAR_CLOSED` | Wiedereröffnung nach Jahresabschluss | edge-errors |
| `E_FISCALYEAR_UNFINALIZED_ENTRIES` | closeFiscalYear bei nicht festgeschriebenen Buchungen (v0.5/F-003) | fiscalyear-close-guard |

## E_ACCOUNT / E_COA

| Code | Invariante | Fixture |
|---|---|---|
| `E_ACCOUNT_UNKNOWN` | Konto existiert nicht | post-malformed |
| `E_ACCOUNT_LOCKED` | Konto gesperrt | post-and-invariants |
| `E_ACCOUNT_NUMBER_TAKEN` | Kontonummer doppelt (Repository-Kontrakt) | accounts-and-import |
| `E_COA_FORMAT_INVALID` | Kontenrahmen-Import nicht parsebar | accounts-and-import |

## E_SETTLEMENT / E_OPENITEM

| Code | Invariante | Fixture |
|---|---|---|
| `E_SETTLEMENT_EXCEEDS_ITEM` | Σ Ausgleiche > OP-Betrag | open-items-settlement |
| `E_SETTLEMENT_DIFFERENCE_INVALID` | `difference.kind` unbekannt, Betrag ≤ 0 oder > Restbetrag (v0.3) | settlement-discount |
| `E_OPENITEM_UNKNOWN` | openItemId existiert nicht | open-items-settlement |

## E_CASHBASIS

| Code | Invariante | Fixture |
|---|---|---|
| `E_CASHBASIS_DEVIATING_FISCAL_YEAR` | EÜR-Projektion über abweichendes Geschäftsjahr (v0.3) | deviating-fiscal-year |

## E_PARTNER / E_FISCALYEAR (v0.4)

| Code | Invariante | Fixture |
|---|---|---|
| `E_PARTNER_UNKNOWN` | partnerId am Beleg existiert nicht | partner-and-ec-sales |
| `E_FISCALYEAR_OVERLAP` | createFiscalYear überschneidet bestehendes Jahr | fiscal-year-management |

## E_TAX

| Code | Invariante | Fixture |
|---|---|---|
| `E_TAXCODE_UNKNOWN` | Steuerschlüssel nicht definiert | tax-expansion |
| `E_TAXCODE_NO_VALID_VERSION` | keine Regelversion zum Belegdatum gültig | tax-expansion |
| `E_PROFILE_RETROACTIVE_CONFLICT` | Profiländerung in festgeschriebenen Zeitraum | small-business-switch |
| `E_PROFILE_UNKNOWN` | Mandanten-Profil nicht vorhanden (createTenant) | create-tenant-profile |

## E_DIMENSION

| Code | Invariante | Fixture |
|---|---|---|
| `E_DIMENSION_INVALID` | unbekannter Typ/Code oder Pflichtdimension fehlt (Regelmodul) | edge-errors |

## E_DEPRECIATION / E_COSTING

| Code | Invariante | Fixture |
|---|---|---|
| `E_ASSET_UNKNOWN` | Anlagegut existiert nicht | edge-errors |
| `E_ASSET_DISPOSED` | Operation auf abgegangenem Anlagegut | edge-errors |
| `E_COSTING_RUN_RELEASED` | Änderungsversuch an released Lauf | allocation-run |
| `E_COSTING_RUN_UNKNOWN` | runId existiert nicht (release/Projektion) (v0.5/F-006) | costing-run-unknown |
| `E_COSTING_CYCLE` | Stufenleiter mit Zyklus | edge-errors |

## E_MAPPING

| Code | Invariante | Fixture |
|---|---|---|
| `E_MAPPING_OVERLAP` | ein Konto fällt in mehrere Mapping-Positionen | mapping-import |

(Mapping-Lücken sind kein Fehler: `gapWarnings[]` + Auffangposition.)

## E_PACK / E_POLICY (Pack-Komposition)

Der Pack-Resolver scheitert **laut** statt still falsch zu rechnen: Er liefert entweder
einen vollständig integren `ResolvedPack` oder genau **einen** dieser Codes mit
`details` (beteiligte `{kind,id}`, Konto-`number`, `code`). Trennlinie der
beiden Resolver-Codes: `E_PACK_UNRESOLVED_REF` = eine Referenz zeigt ins Nichts;
`E_PACK_INCOHERENT` = die Referenzen existieren, aber das Bündel ist in sich
widersprüchlich. `E_PACK_UNRESOLVED_REF` hat Vorrang, wenn beide zugleich zuträfen.

| Code | Auslösende Invariante | Fixture |
|---|---|---|
| `E_PACK_UNRESOLVED_REF` | Eine Referenz im Manifest/Modul zeigt ins Leere: Modul-`id`/`version` im Modulbestand nicht gefunden, `dependsOn` zeigt auf ein nicht in der effektiven Liste enthaltenes Modul, oder ein gefalteter Beitrag referenziert ein fehlendes Ziel — `taxAccount` (bzw. `inputTaxAccount` bei `reverse_charge`) ohne Konto (I1), Mapping-Selektor trifft kein Konto / zeigt vollständig ins Leere (I2), eines der fünf `assetAccounts.*Account` fehlt (I3), ein vom Profil/Manifest referenzierter `taxCode` wird von keinem aufgelösten `tax`-Modul bereitgestellt (I4, mapping-frei) | resolver-errors |
| `E_PACK_INCOHERENT` | Referenzen existieren, aber das Bündel passt nicht zusammen: Abhängigkeits-Zyklus, Konto-`number`-Kollision aus zwei Kontenrahmen (I6), doppelter `taxCode.code`/`mapping.id` oder mehr als ein `policy`-Modul (I7), kollidierender oder ins Leere greifender `override` (Doppel-Override, `replace` auf nicht gelistetes Modul), unbekanntes `kind` | resolver-errors |
| `E_POLICY_INVALID` | `packPolicy`-Wert ungültig oder inkonsistent: unbekannter `roundingMode`/`taxRoundingGranularity`-Enum, `currencyScale` nicht ganzzahlig oder außerhalb 0–4, ISO-Exponent-Widerspruch, Manifest-`packPolicy`-Kopie ≠ aufgelöstes `policy`-Modul, oder `currencyScale`-Änderung auf bestehendem Mandanten | ✗ |
| `E_AMOUNT_SCALE_MISMATCH` | Betrag im Bestand hat eine andere Nachkommastellenzahl als der `currencyScale` des Mandanten verlangt (exakte Stellenzahl inkl. Pflicht-Nullen, kanonische Form) — Reader-/Writer-Prüfung jenseits des kontextfreien amount-Patterns | ✗ |

**Stand 2026-06-21 (v0.6): 38 Codes, 36 mit Fixture** (`validate.py`). Die 34 Kern-Codes
sind vollständig abgedeckt; von den 4 Pack-Codes haben `E_PACK_UNRESOLVED_REF` und
`E_PACK_INCOHERENT` Fixtures (`resolver-errors`). **Offen (✗): `E_POLICY_INVALID`,
`E_AMOUNT_SCALE_MISMATCH` — Fixtures in Gate 1.**

Hinweis: `runDepreciation` auf bereits gelaufene Periode ist **kein Fehler** (idempotent, `alreadyRun: true`) — bewusste Abweichung, siehe `api.md`.

## Konventionen

Fehler tragen strukturierte Details (`code`, `message` (implementierungsfrei formulierbar), `details`-Objekt mit den beteiligten IDs/Werten). Die Fixture prüft nur `code` — Wortlaut ist frei.
