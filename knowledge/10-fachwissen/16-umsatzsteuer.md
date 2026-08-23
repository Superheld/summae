# Umsatzsteuer — Querschnittsthema

Die USt durchzieht EÜR und Doppik, mit *unterschiedlicher* buchhalterischer Behandlung. Hier nur das, was die Packages betrifft — kein USt-Lehrbuch.

## Grundmechanik

- **Steuersätze:** Regelsatz 19 %, ermäßigt 7 % (§ 12 UStG), Steuerbefreiungen (§ 4 UStG: u. a. Heilberufe, Vermietung, Finanzdienstleistungen — teils mit Optionsrecht § 9).
- **Vorsteuer:** gezahlte USt auf Eingangsleistungen ist abziehbar (§ 15 UStG), sofern kein Ausschluss (z. B. steuerfreie Ausgangsumsätze ohne Option).
- **Zahllast** = USt auf Ausgangsumsätze − Vorsteuer → USt-Voranmeldung (monatlich/vierteljährlich, § 18 UStG), Jahreserklärung.

## Soll- vs. Ist-Versteuerung (§§ 16, 20 UStG)

- **Soll:** USt entsteht mit Leistungserbringung (Rechnungsstellung) — Standard.
- **Ist:** USt entsteht mit Zahlungseingang — auf Antrag; Umsatzgrenze **800.000 €** (§ 20 UStG, seit 2024; **verifiziert Stand 06/2026**), Freiberufler unabhängig von der Grenze.
- Vorsteuerabzug: bisher bei Leistungsbezug + Rechnung (unabhängig von Zahlung). **Ab 01.01.2028** (JStG 2024, § 15 Abs. 1 UStG n. F.): Bei Rechnungen von Ist-Versteuerern ist die Vorsteuer erst mit **Zahlung** abziehbar; dazu neue Rechnungspflichtangabe „Versteuerung nach vereinnahmten Entgelten" (§ 14 Abs. 4 S. 1 Nr. 6a UStG). → Das Datenmodell muss ab 2028 die Versteuerungsart des *Lieferanten* am Beleg kennen!

## Sonderfälle mit Modellrelevanz

- **Kleinunternehmer (§ 19 UStG):** seit 2025 echte Steuerbefreiung; Grenzen **25.000 €** Vorjahr und **100.000 €** laufendes Jahr (**verifiziert Stand 06/2026**). Überschreiten der 100.000 € im laufenden Jahr → Befreiung entfällt *ab diesem Umsatz* (kein rückwirkender Wegfall; unterjähriger Statuswechsel!). Kein Vorsteuerabzug; Buchungen ohne Steueranteil.
- **Reverse Charge (§ 13b UStG):** Steuerschuld beim Leistungsempfänger (Bauleistungen, EU-B2B-Dienstleistungen u. a.) — bucht USt und Vorsteuer gleichzeitig.
- **Innergemeinschaftlich:** ig. Lieferung (steuerfrei mit USt-IdNr.-Nachweis), ig. Erwerb (Erwerbsteuer + Vorsteuer), Zusammenfassende Meldung.
- **Anzahlungen:** USt bereits bei Vereinnahmung der Anzahlung.
- **USt-Korrektur (§ 17 UStG):** bei Entgeltminderung (Skonto, Rabatt, Forderungsausfall).
- **Steuerschlüssel:** die Praxis-Abstraktion — ein Schlüssel pro Steuersachverhalt, der Satz + Konten + Voranmeldungs-Kennzahl (Kz) bündelt. DATEV-Steuerschlüssel sind De-facto-Standard.

## Behandlung je Gewinnermittlungsart

| | EÜR | Doppik |
|---|---|---|
| vereinnahmte USt | Betriebseinnahme | Verbindlichkeit (erfolgsneutral) |
| gezahlte Vorsteuer | Betriebsausgabe | Forderung (erfolgsneutral) |
| Zahllast-Zahlung | Betriebsausgabe | Tilgung der Verbindlichkeit |

---

## Konsequenzen für die Packages

- Steuerschlüssel-Konzept in den Kern: Schlüssel = Satz (mit Gültigkeitszeitraum! Steuersatzänderungen wie 2020) + Buchungsregel + Meldungs-Kennzahl.
- USt-Berechnung mit definierten Rundungsregeln (pro Beleg, nicht pro Position).
- Soll/Ist-Versteuerung als Konfiguration des Mandanten — beeinflusst, *wann* USt-Buchungen entstehen.
- Voranmeldungs-Auswertung (Kennzahlen-Mapping) als Projektion; tatsächliche ELSTER-Übermittlung out of scope.
- Reverse Charge & ig. Vorgänge: Buchungsregeln mit zwei gleichzeitigen Steuerpositionen.

## Offene Fragen — beantwortet (2026-06-07)

- Steuerschlüssel: **eigene sprechende Codes** (`USt19`, `VSt19`, `RC13b`); DATEV-BU-Schlüssel als Alias-Spalte im Regelmodul (für Import + Buchungsstapel-Export).
- OSS-Verfahren: **out of scope v1** (in `out-of-scope.md` einsortiert unter Mehrländer-USt).
- KU-Statuswechsel: als Fixture umgesetzt (small-business-switch).
