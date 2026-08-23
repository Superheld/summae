# Tax — taktisches Modell

**Kontext: Tax (Supporting).** Stand 2026-06-07. Sprache: Steuerschlüssel, Versteuerungsart, Kennzahl. Konsumiert das Journal über die Published Language (Conformist); liefert Positionserweiterung beim Buchen (via Anwendungsschicht).

> **Schicht: Mechanik = Kern (`expand`-Sockel), Inhalte = DE-Pack** (USt-Sätze/-Regeln, Kennzahlen, Schwellen). US-Sales-Tax wäre der einzige Code-Bruch (anderes Paradigma). Schichtenzuordnung: `jurisdiction-profil.md`.

## Aggregate

### 1. `TaxCode` (Steuerschlüssel)

- **Identität:** Schlüssel-Code je Regelmodul-Version (z. B. `VSt19`, `USt19`, `RC13b`, `igE`).
- **Inhalt:** Liste von **Regelversionen mit Gültigkeitszeitraum** (NF-5.1), jede Version: Steuersatz, Buchungsregel (welche Steuerposition auf welches Konto, Brutto-/Netto-Rechnung, Rundung pro Beleg), Kennzahl-Zuordnung (USt-VA-Kz), Sonderverhalten (Reverse Charge: zwei Positionen gleichzeitig).
- **Invarianten:** Versionen lückenlos und überlappungsfrei; eine Buchungsregel erzeugt immer ausbalancierte Positionserweiterungen.
- **Herkunft:** Inhalte sind Regelmodul-Daten (Schicht 2) — das Aggregat ist die geladene, validierte Form davon. Eigene Schlüssel je Mandant möglich (DATEV-Kompatibilität: Nomenklatur offen, Frage 16-USt).

### 2. `TaxProfile` (steuerliches Mandantenprofil)

- Versteuerungsart (Soll/Ist) **mit Gültigkeitszeitraum**, Kleinunternehmer-Status **mit Gültigkeitszeitraum** (unterjähriger Wechsel! SF-11), Voranmeldungszeitraum (monatlich/vierteljährlich), Dauerfristverlängerung ja/nein (beeinflusst 10-Tage-Regel, BFH 13.12.2022).
- **Invariante:** Statuswechsel nur zu definierten Stichtagen mit Audit-Trail; keine rückwirkende Änderung in festgeschriebene Zeiträume.

## Domain Services

- **`expand`:** (Belegdaten, TaxCode, TaxProfile, Datum) → vollständige Positionserweiterung (Netto-, Steuer-Positionen, Tags). Side-effect-free — reine Funktion, ideal für die Konformitäts-Testsuite. Wählt die zum **Belegdatum** gültige Regelversion.
- **`vatReturn`:** Projektion USt-VA-Kennzahlen für einen Zeitraum: liest steuer-getaggte Journalpositionen, ordnet per Kennzahl-Mapping zu. Bei Ist-Versteuerung folgt sie den **Zahlungen** (OP-Ausgleichen), nicht den Rechnungen — nutzt dieselbe OP-Verkettung wie die EÜR-Projektion (R1). Bezugsdatum-fähig (Neuberechnung alter Zeiträume).

## Domain Events

`TaxCodeVersionActivated` · `TaxProfileChanged` (z. B. `SmallBusinessStatusLost` zum Stichtag — fachlich bedeutsam, SF-11)

## Bewusst nicht in Tax

Buchen selbst (Ledger), Steuer*berechnung* über USt hinaus (out of scope), ELSTER (App-Sache).
