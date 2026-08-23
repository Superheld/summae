# Grundlagen — gemeinsamer Kern aller Gebiete

Diese Konzepte gelten in EÜR, Doppik (HGB und kommunal) und KLR gleichermaßen. Sie sind die Kandidaten für den jurisdiktionsfreien Package-Kern.

## Beleg

Keine Buchung ohne Beleg (*Belegprinzip*). Ein Beleg ist der Nachweis eines Geschäftsvorfalls: Rechnung, Quittung, Kontoauszug, Vertrag, Eigenbeleg. Jede Buchung muss auf ihren Beleg rückverfolgbar sein und umgekehrt (progressive und retrograde Prüfbarkeit, vgl. GoBD).

Eigenschaften: Belegdatum, Belegnummer (lückenlos oder zumindest nachvollziehbar), Aussteller, Betrag, ggf. USt-Ausweis.

## Geschäftsvorfall

Ein wirtschaftliches Ereignis, das das Vermögen, die Schulden oder den Erfolg verändert: Verkauf, Einkauf, Zahlung, Abschreibung, Entnahme. Der Geschäftsvorfall ist das fachliche Ereignis; die Buchung ist seine Abbildung im Rechnungswesen. Diese Trennung ist wichtig: ein Geschäftsvorfall kann mehrere Buchungen auslösen (z. B. Rechnung + spätere Zahlung).

## Konto

Zweiseitige Rechnung mit *Soll* (links) und *Haben* (rechts). Kontentypen mit unterschiedlicher Mechanik:

| Typ | Anfangsbestand | Mehrung | Minderung | Saldo geht in |
|---|---|---|---|---|
| Aktivkonto (Vermögen) | Soll | Soll | Haben | Bilanz |
| Passivkonto (Kapital/Schulden) | Haben | Haben | Soll | Bilanz |
| Aufwandskonto | — | Soll | Haben | GuV |
| Ertragskonto | — | Haben | Soll | GuV |

Bestandskonten (Aktiv/Passiv) werden über die Bilanz fortgeführt; Erfolgskonten (Aufwand/Ertrag) werden je Periode über das GuV-Konto abgeschlossen und starten bei null.

In der EÜR gibt es keine Bestandskonten im strengen Sinn — dort werden nur Einnahmen und Ausgaben kategorisiert. Das Konto als Sammelstelle für gleichartige Vorgänge existiert aber auch dort.

## Buchungssatz

„Soll an Haben": mindestens ein Konto im Soll, mindestens eines im Haben. **Invariante: Summe Soll = Summe Haben** — pro Buchung und damit über das gesamte System. Zusammengesetzte Buchungssätze (mehrere Konten je Seite) sind normal (z. B. Rechnung mit USt: Forderungen an Umsatzerlöse + USt).

Bestandteile einer Buchung: Buchungsdatum, Belegdatum, Belegreferenz, Buchungstext, Positionen (Konto, Seite, Betrag), Periode, ggf. Steuerschlüssel.

## Journal und Hauptbuch

- **Journal (Grundbuch):** alle Buchungen in zeitlicher Reihenfolge, lückenlos. Die autoritative Aufzeichnung.
- **Hauptbuch:** dieselben Buchungen sachlich geordnet, je Konto. Aus dem Journal ableitbar — das Hauptbuch ist eine *Projektion* des Journals, keine zweite Datenhaltung.
- **Nebenbücher:** Detaillierung einzelner Hauptbuchkonten (Debitoren, Kreditoren, Anlagen, Lohn). Das zugehörige Hauptbuchkonto fungiert als Sammelkonto.

## Periode und Geschäftsjahr

Buchungen gehören zu einer Periode (üblich: Monat) innerhalb eines Geschäftsjahres (Kalenderjahr oder abweichend). Periodenabschluss sperrt die Periode gegen weitere Buchungen. Korrekturen geschlossener Perioden erfolgen durch neue Buchungen (Storno) in einer offenen Periode — nie durch Änderung alter Buchungen.

## Storno

Buchungen werden nie gelöscht oder geändert. Korrektur durch Stornobuchung (Umkehrung) + Neubuchung. Üblich ist Generalumkehr (negative Beträge auf denselben Seiten), damit die Verkehrszahlen der Konten nicht aufgebläht werden. Die Stornobuchung referenziert die stornierte Buchung.

## Soll/Ist-Trennung (kameraler Rest, aber universell nützlich)

Geplante vs. tatsächliche Werte. In der Doppik via Budget/Planbuchungen, in der kommunalen Doppik als Haushaltsplan verbindlich, in der KLR als Plankostenrechnung. Der Kern braucht ein Konzept dafür, auch wenn die Ausprägung je Gebiet verschieden ist.

## Beträge und Währung

Beträge sind exakte Dezimalwerte (niemals Float). Rundung nach kaufmännischen Regeln, definiert pro Operation (z. B. USt-Berechnung: Rundung pro Rechnung, nicht pro Position — § 14 UStG i. V. m. UStAE). Währung gehört zum Betrag (Money-Konzept: Betrag + Währung). Fremdwährung: Umrechnung mit Kurs zum Stichtag, Kursdifferenzen sind eigene Geschäftsvorfälle.

---

## Konsequenzen für die Packages

- Journal ist append-only; Hauptbuch, Salden, Auswertungen sind Projektionen daraus. Das legt eine ereignisorientierte Datenhaltung nahe (Entscheidung in Phase 2/3).
- `Summe Soll = Summe Haben` ist eine Invariante der Buchung — sie wird beim Schreiben erzwungen, nicht beim Lesen geprüft.
- Geschäftsvorfall ≠ Buchung: das Datenformat braucht beide Konzepte und die Verknüpfung.
- Money-Typ verpflichtend: Dezimal + Währung, Rundungsregeln explizit pro Kontext.
- Storno und Periodensperre gehören in den Kern, nicht in die Jurisdiktionsmodule — sie sind universell.

## Offene Fragen — alle beantwortet (2026-06-07)

- Periodengranularität: **konfigurierbar je Geschäftsjahr** (Standard 12 Monate; Fixtures nutzen auch 1-Perioden-Jahre).
- Mehrwährung: **v2**; Money trägt Währung, Kursfelder im Format reserviert.
- Buchungsnummer: **ja** — `sequenceNumber`, lückenlos je Geschäftsjahr (GoBD-Journalfunktion).
