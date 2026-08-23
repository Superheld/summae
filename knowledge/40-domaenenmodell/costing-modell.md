# Costing (KLR) — taktisches Modell

**Kontext: Costing (Supporting, früh benötigt).** Stand 2026-06-07. Sprache: Kosten (≠ Aufwand!), Kostenstelle, Kostenträger, Umlage, BAB. Sitzt hinter dem ACL „Abgrenzungsrechnung"; führt einen **eigenen Rechnungskreis** — Fibu-Journal bleibt unberührt.

## Aggregate

### 1. `CostingPeriodRun` (Abrechnungslauf)

Das zentrale Aggregat — die KLR arbeitet periodenweise, und die Konsistenz eines Laufs ist die eigentliche Invariante:

- **Schritte (intern, in Reihenfolge):** Primärkostenübernahme (via ACL) → kalkulatorische Kosten → innerbetriebliche Leistungsverrechnung (Umlagen) → Kalkulationssätze. Jeder Schritt baut auf dem vorigen auf.
- **Invarianten:** Verrechnungssumme bleibt erhalten (Umlagen verteilen, erzeugen nichts); Hilfskostenstellen nach Umlage = 0; ein Lauf ist je Periode + Version eindeutig; Wiederholung erzeugt neue Version (alte bleibt nachvollziehbar).
- **Lauf-Status:** `draft` → `released` (für Auswertungen freigegeben).

### 2. `ReconciliationRule`-Satz (Abgrenzungsregeln — der ACL)

- Regeln je Fibu-Konto: übernehmen / nicht übernehmen (neutraler Aufwand) / ersetzen (Anders-Kosten: kalk. AfA statt Bilanz-AfA) / hinzufügen (Zusatzkosten: kalk. Unternehmerlohn, kalk. Zinsen, kalk. Miete, Wagnisse).
- **Invariante:** Überleitung ist beidseitig abstimmbar — Ergebnis Fibu ↔ Betriebsergebnis als explizite Rechnung (die klassische Abstimmbrücke). Genau das macht den ACL prüfbar.

### 3. `AllocationScheme` (Umlageschema)

- Senderstellen, Empfängerstellen, Schlüssel (fest, prozentual, mengenbasiert: m², Köpfe, Stunden), Verfahren (Anbau / Stufenleiter / Gleichungsverfahren).
- **Invariante:** Stufenleiter braucht zyklenfreie Reihenfolge; Gleichungsverfahren erlaubt Zyklen (lineares Gleichungssystem — deterministisch lösbar, Testsuite-relevant: Rundung!).

## Stammdaten (eigene kleine Aggregate)

`CostCenter`, `CostObject`, `CostType` — mit Gültigkeit und Hierarchie. **Wichtig:** Sie sind *Costing-Sicht* auf die Dimensionswerte des Ledgers (DimensionValue, frei definierbar) — gleiche Codes, eigene Attribute (Verantwortlicher, Hilfs-/Hauptstellen-Typ, Umlageeigenschaften). Der Kern erfasst Dimensionen, Costing gibt ihnen KLR-Semantik.

## Projektionen

- **BAB** (Betriebsabrechnungsbogen): Matrix Kostenarten × Kostenstellen eines released Laufs.
- **Kostenträgerrechnung / Kalkulation:** Zuschlagskalkulation über die Kalkulationssätze des Laufs; Deckungsbeitragsrechnung (variabel/fix aus CostType).
- **Abstimmbrücke:** Fibu-Ergebnis → Betriebsergebnis (der ACL als Bericht).

## Domain Events

`CostingRunReleased` · `ReconciliationRulesChanged` (Audit — Regeländerungen verändern Betriebsergebnis)

## Offene Punkte

- Plankostenrechnung (Soll-Ist-Abweichungen): zweite Ausbaustufe, Modell hält `CostingPeriodRun` versionierbar genug.
- Gebührenkalkulation (kommunal, KAG): wartet auf Budgeting-Kontext.
- Verhältnis CostType ↔ Fibu-Kontenplan: 1:1-Default mit Override (pragmatisch), zu validieren mit echten Fällen.
