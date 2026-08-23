# API- und Datenformat-Leitplanken

> **Status-Update 2026-06-09:** Die Spezifikation existiert — `datenformat.md` und `api.md` (aktuell v0.5, laufend gepflegt; Erststand v0.1 vom 2026-06-07). Dieses Dokument bleibt als Grundsatz-Referenz; bei Widerspruch gewinnen die Spec-Dokumente.

## API

- Sprachneutrale Spezifikation zuerst (Operationen, Eingaben, Invarianten, Fehlerfälle); jede Runtime implementiert sie idiomatisch (Laravel-Adapter darf sich nach Laravel anfühlen), aber Namen und Semantik sind identisch.
- Namen kommen aus dem Glossar (EN-Spalte). Glossar-Änderung = API-Änderung — deshalb wird das Glossar wie Code behandelt (Review, Entscheidung, Log).
- Schreiboperationen erzwingen Invarianten (Σ Soll = Σ Haben, Periodenstatus, Festschreibung) und sind die einzigen Wege, Daten zu ändern.
- Fehlerfälle sind Teil der Spec: gleiche Verletzung → gleicher Fehlercode in jeder Implementierung.

## Datenformat

- Versioniertes Schema; Migrationspfade sind Teil der Spec.
- Append-only-freundlich: Journal als fortlaufende, unveränderliche Einträge; Projektionen (Salden, Hauptbuch) sind ableitbar und müssen nicht ausgetauscht werden.
- Beträge als String-Dezimal oder Integer-Minor-Units (Entscheidung Phase 3) — niemals Binary-Float im Format.
- Identitäten: global eindeutige, implementierungsunabhängige IDs (z. B. UUIDv7) — eine in Laravel erzeugte Buchung bleibt in Python referenzierbar.
- Selbstbeschreibend genug für den GoBD-Export (Z3): Feldbeschreibungen gehören zum Format.

## Konformitäts-Testsuite (der eigentliche Vertrag)

- Sprachneutrale Fixtures: Eingabe-Buchungen + erwartete Salden/Auswertungen/Fehler als Daten (JSON/YAML).
- Jede Implementierung führt dieselbe Suite aus; grün = konform.
- Rundungs- und Sortierfälle explizit als Fixtures (häufigste Quelle von Cross-Implementierungs-Abweichungen).
- Cross-Test: Datenbestand mit Implementierung A erzeugen, mit B fortschreiben, mit C auswerten.
