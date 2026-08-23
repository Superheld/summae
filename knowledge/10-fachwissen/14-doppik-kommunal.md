# Kommunale Doppik (NKF / NKHR)

## Rahmen

Reform von Kameralistik zu doppelter Buchführung in den Kommunen („Neues Kommunales Finanzmanagement" NKF in NRW, „Neues Kommunales Haushalts- und Rechnungswesen" NKHR in BW, andere Länder eigene Bezeichnungen). **Landesrecht!** Jede Gemeindeordnung/GemHVO weicht im Detail ab — es gibt keinen bundeseinheitlichen Standard. Bayern erlaubt weiterhin Kameralistik als Wahlrecht.

Ziel der Doppik im kommunalen Kontext: Ressourcenverbrauchskonzept statt Geldverbrauchskonzept — Abschreibungen, Rückstellungen (v. a. Pensionen) werden sichtbar; intergenerative Gerechtigkeit messbar.

## Drei-Komponenten-Rechnung

Der zentrale Strukturunterschied zur kaufmännischen Doppik:

1. **Ergebnisrechnung** (≙ GuV): Erträge und Aufwendungen → Jahresergebnis (Ressourcenverbrauch)
2. **Finanzrechnung** (≙ Kapitalflussrechnung, aber als *eigene laufende Rechnung geführt*, nicht nur abgeleitet): Einzahlungen und Auszahlungen → Änderung des Zahlungsmittelbestands
3. **Vermögensrechnung / Bilanz**: Vermögen, Schulden, Eigenkapital (häufig „Basis-Reinvermögen" + Rücklagen)

Verknüpfung: Jahresergebnis → Eigenkapital; Saldo Finanzrechnung → Zahlungsmittelbestand in der Bilanz. Viele Geschäftsvorfälle buchen also **parallel in zwei Rechnungen** (Aufwand und Auszahlung).

## Haushalt als führendes Steuerungsinstrument

Anders als im Unternehmen ist der **Haushaltsplan** rechtsverbindlich (Satzung): Ergebnishaushalt + Finanzhaushalt, gegliedert nach **Produkten** (Produktplan: Produktbereiche → Produktgruppen → Produkte), nicht primär nach Organisationseinheiten. Dazu:

- **Bewirtschaftung:** Buchungen laufen gegen Haushaltsansätze (Budget); Mittelbindung durch *Obligo* (Bestellungen/Aufträge reservieren Budget vor der eigentlichen Buchung).
- **Über-/außerplanmäßige Aufwendungen** brauchen Genehmigungsverfahren (§ Landesrecht).
- **Deckungsfähigkeit:** Budgets können gegenseitig deckungsfähig erklärt werden.
- **Haushaltsausgleich:** Ergebnishaushalt muss ausgeglichen sein (Sollvorschrift, Details Landesrecht); Konsolidierungspflichten bei Defizit.

## Weitere Besonderheiten

- **Kommunaler Kontenrahmen** je Land (z. B. NKF-Kontenrahmen NRW, kommunaler Kontenrahmen BW) — andere Klassenlogik als SKR.
- **Sonderposten:** erhaltene Zuwendungen/Beiträge passiviert und parallel zur AfA des geförderten Vermögens ertragswirksam aufgelöst.
- **Pensionsrückstellungen** dominieren oft die Passivseite.
- **Gesamtabschluss:** Konsolidierung der Kommune mit ihren Beteiligungen (Stadtwerke etc.) — „Konzern Kommune".
- **Jahresabschluss:** Ergebnisrechnung, Finanzrechnung, Bilanz, Anhang, Rechenschaftsbericht; Teilrechnungen je Produktbereich.

## Verhältnis zur kaufmännischen Doppik

Gemeinsamer Kern: doppelte Buchung, Konten, Journal, Perioden, Abschreibungen, Rückstellungen — identische Mechanik. Unterschiede: dritte Rechnungskomponente (Finanzrechnung als gebuchte Rechnung), verbindliches Budget mit Obligo, Produktorientierung, landesrechtliche Kontenrahmen und Ausgleichsregeln, anderes Eigenkapitalkonzept.

→ Modellsicht: **eigener Bounded Context**, der den Buchungskern wiederverwendet. „Ergebnis", „Haushalt", „Produkt" haben hier Bedeutungen, die es im HGB-Kontext nicht gibt.

---

## Konsequenzen für die Packages

- Der Buchungskern muss Buchungen erlauben, die mehrere Rechnungskreise gleichzeitig bedienen (Ergebnis- + Finanzrechnung) — oder das Modell bildet die Finanzrechnung als zweite Projektion mit eigenen Konten ab. Zentrale Designentscheidung Phase 2.
- Budget/Obligo-Mechanik (Verfügbarkeitskontrolle *vor* Buchung) ist ein eigenes Subsystem — im Unternehmenskontext optional (Budgetierung), im kommunalen Kontext Pflicht.
- Kontenrahmen-Abstraktion muss auch kommunale Rahmen tragen (andere Klassen, Produkt-Dimension).
- Mehrdimensionale Zuordnung jeder Buchung: Konto × Produkt × ggf. Maßnahme — Dimensionen müssen erweiterbar sein (dieselbe Mechanik braucht die KLR mit Kostenstellen).
- Landesrecht = Konfiguration: ein „Jurisdiktionsmodul" pro Bundesland wäre übertrieben; besser parametrisierbare Regeln (Ausgleichsregeln, Kontenrahmen als Daten).

## Offene Fragen

- Priorität: Wie wichtig ist kommunale Doppik für die ersten konkreten Projekte? (Bestimmt, ob Phase-2-Modellierung sie nur *berücksichtigt* oder *ausarbeitet*.)
- Referenz-Bundesland für die erste Ausarbeitung (NRW/NKF ist am besten dokumentiert)?
- Obligo: eigener Buchungstyp oder Vorstufe außerhalb des Journals?
