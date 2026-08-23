# Kaufmännische Doppik (HGB)

## Pflicht und Rahmen

Buchführungspflicht: Kaufleute (§ 238 HGB), Kapitalgesellschaften immer; Befreiung für Einzelkaufleute unter Schwellen (§ 241a HGB). Steuerlich: § 140 AO (derivativ aus HGB) und § 141 AO (originär ab Schwellen). Grundlage sind die *GoB* (Grundsätze ordnungsmäßiger Buchführung): Klarheit, Vollständigkeit, Richtigkeit, zeitgerechte Erfassung, Ordnung, Unveränderbarkeit.

## Periodisierung statt Zufluss/Abfluss

Erträge und Aufwendungen gehören in die Periode ihrer **wirtschaftlichen Verursachung**, unabhängig vom Zahlungszeitpunkt. Instrumente:

- **Forderungen/Verbindlichkeiten:** Leistung gebucht bei Lieferung/Leistung, Zahlung separat.
- **Rechnungsabgrenzungsposten (RAP):** aktive RAP (§ 250 Abs. 1 HGB) — Ausgabe jetzt, Aufwand später (z. B. Jahresversicherung im Dezember bezahlt); passive RAP — Einnahme jetzt, Ertrag später.
- **Rückstellungen (§ 249 HGB):** Aufwand jetzt, Zahlung später, Höhe/Zeitpunkt ungewiss (Prozessrisiken, Gewährleistung, Urlaubsrückstellungen).
- **Abschreibungen (AfA):** Verteilung von Anschaffungs-/Herstellungskosten über die Nutzungsdauer (§ 253 HGB; steuerlich AfA-Tabellen).

## Bilanz und GuV

- **Bilanz** (§ 266 HGB, Gliederung): Aktiva (Anlagevermögen, Umlaufvermögen, ARAP) = Passiva (Eigenkapital, Rückstellungen, Verbindlichkeiten, PRAP). Stichtagsbetrachtung.
- **GuV** (§ 275 HGB): Gesamtkostenverfahren oder Umsatzkostenverfahren. Periodenbetrachtung. Saldo = Jahresüberschuss/-fehlbetrag → fließt ins Eigenkapital.
- **Eröffnungsbilanz → laufende Buchungen → Abschlussbuchungen → Schlussbilanz**; Bilanzidentität (Schlussbilanz Jahr n = Eröffnungsbilanz Jahr n+1).

Wichtige Bewertungsgrundsätze (§ 252 HGB): Vorsicht, Realisation (Gewinne erst bei Realisierung), Imparität (drohende Verluste sofort), Einzelbewertung, Stetigkeit, Going Concern. Niederstwertprinzip beim Umlaufvermögen (streng) und Anlagevermögen (gemildert).

## Kontenrahmen und Kontenplan

- **Kontenrahmen:** standardisiertes Ordnungsschema, v. a. DATEV **SKR03** (prozessorientiert) und **SKR04** (abschlussorientiert, folgt Bilanz-/GuV-Gliederung). Branchen-SKRs existieren (SKR14 Landwirtschaft, SKR49 Vereine, SKR80/81 Heilberufe/Ärzte u. a.).
- **Kontenplan:** der konkrete, aus dem Rahmen abgeleitete Plan eines Unternehmens.
- Vierstellige Kontonummern, Klassen 0–9. Automatikkonten (USt-Schlüssel fest hinterlegt) sind eine DATEV-Eigenheit, die Nutzer erwarten.

## Jahresabschluss-Ablauf (vereinfacht)

1. Abstimmung Neben-/Hauptbücher, Saldenbestätigungen, Inventur (§ 240 HGB)
2. Abschlussbuchungen: AfA, RAP, Rückstellungen, Bestandsveränderungen, Wertberichtigungen
3. Erfolgskonten → GuV-Konto → Eigenkapital; Bestandskonten → Schlussbilanzkonto
4. Anhang/Lagebericht je nach Größenklasse (§ 267 HGB), Offenlegung (§ 325 HGB)

## Steuerbilanz vs. Handelsbilanz

Maßgeblichkeitsprinzip (§ 5 Abs. 1 EStG) mit wachsenden Durchbrechungen → ggf. zwei Wertansätze je Sachverhalt (z. B. Rückstellungsbewertung, AfA-Methoden). E-Bilanz: elektronische Übermittlung nach amtlicher Taxonomie (§ 5b EStG).

---

## Konsequenzen für die Packages

- Kontenrahmen sind **Daten, nicht Code**: mitgeliefert wird der eigene, jurisdiktionsneutrale Basis-Rahmen **summae-base** als versionierte Default-Konfiguration; SKR03/04 werden **nicht** gebündelt, sondern über die `importChartOfAccounts`-Operation eingespielt; eigene Kontenpläne müssen möglich sein.
- Abschlussbuchungen (AfA-Läufe, RAP-Auflösung) sind generierte Buchungen — sie laufen durch dasselbe Journal wie alles andere, keine Sonderwege.
- Mehrere Wertansätze je Sachverhalt (HB/StB) → das Modell braucht ggf. parallele Bewertungsbereiche (Entscheidung Phase 2; IFRS-Erweiterung würde dasselbe Konstrukt nutzen).
- Bilanz/GuV sind Projektionen über Konten + Gliederungs-Mapping (Konto → Bilanzposition). Das Mapping gehört zum Kontenrahmen.
- Größenklassen/Schwellenwerte ändern sich → als konfigurierbare Stammdaten mit Gültigkeitszeitraum, nicht hartkodiert.

## Offene Fragen — beantwortet (2026-06-07)

- Bewertungsbereiche HB/StB: **v1 ein Bereich**, `valuationArea` im Format reserviert (Bruce).
- Automatikkonten: nicht übernommen — USt-Logik läuft explizit über Steuerschlüssel/`expandTax`; DATEV-BU-Schlüssel als Alias im Regelmodul.
- Anlagenbuchhaltung: **eigener Kontext Assets** (Nebenbuch, erzeugt normale Journal-Buchungen). Inventur bleibt App-Sache (Abgrenzungsprinzip).
- Lizenzfrage SKR (Rechercheergebnis 06/2026, **nicht belastbar — juristisch klären**): Die Kontenrahmen-*Struktur* gilt verbreitet als nicht schutzfähig und ist in fast aller Drittsoftware implementiert; DATEVs *gedruckte/publizierte Fassungen* (mit Erläuterungen, Layout) sind geschützt. Unser Ansatz (DATEV-kompatibler *Import*, Nutzer laden eigene Daten hoch) umgeht das Problem weitgehend — ob wir Rahmendaten *mitliefern* dürfen, bleibt vor Phase 4 zu klären.
