# EÜR — Einnahmen-Überschuss-Rechnung (§ 4 Abs. 3 EStG)

## Wer darf EÜR?

Gewinnermittlung durch Überschuss der Betriebseinnahmen über die Betriebsausgaben. Zulässig für: Freiberufler (immer, unabhängig von Größe), Gewerbetreibende unterhalb der Schwellen des § 141 AO (Umsatz ≤ 800.000 €, Gewinn ≤ 80.000 € — **verifiziert Stand 06/2026**, gilt seit 2024 durch Wachstumschancengesetz; die Pflicht beginnt erst nach schriftlicher Mitteilung des Finanzamts, mit dem Folgewirtschaftsjahr), Kleingewerbe ohne Handelsregistereintrag. Wer buchführungspflichtig ist (§§ 238 ff. HGB, § 141 AO), muss bilanzieren.

## Kernprinzip: Zufluss/Abfluss (§ 11 EStG)

Einnahmen zählen im Jahr des **Zuflusses**, Ausgaben im Jahr des **Abflusses** — nicht im Jahr der wirtschaftlichen Verursachung. Das ist der fundamentale Unterschied zur Doppik (dort: Periodisierung).

Ausnahmen vom Zufluss/Abfluss-Prinzip:

- **10-Tage-Regel** (§ 11 Abs. 1 S. 2, Abs. 2 S. 2 EStG): regelmäßig wiederkehrende Einnahmen/Ausgaben, die kurz vor/nach Jahreswechsel fließen (≤ 10 Tage), gehören ins Jahr der wirtschaftlichen Zugehörigkeit. Klassiker: USt-Vorauszahlung für Dezember, gezahlt am 8. Januar.
- **Anlagevermögen:** Anschaffungskosten abnutzbarer Wirtschaftsgüter werden nicht beim Abfluss abgesetzt, sondern über AfA verteilt (§ 4 Abs. 3 S. 3 EStG). Nicht abnutzbares Anlagevermögen (z. B. Grundstücke): Abzug erst bei Veräußerung/Entnahme.
- **GWG**: geringwertige Wirtschaftsgüter ≤ 800 € netto sofort absetzbar (§ 6 Abs. 2 EStG); Sammelposten-Alternative 250–1.000 € mit Pool-Abschreibung über 5 Jahre; Aufzeichnungspflicht ab 250 €. (**Verifiziert Stand 06/2026** — unverändert seit 2018.)
- **Darlehen:** Aufnahme/Tilgung sind keine Einnahme/Ausgabe (erfolgsneutral), nur Zinsen zählen.
- **Durchlaufende Posten** (§ 4 Abs. 3 S. 2 EStG): in fremdem Namen vereinnahmt/verausgabt — keine Einnahme/Ausgabe.

## Besonderheiten

- **USt ist in der EÜR erfolgswirksam:** vereinnahmte USt ist Betriebseinnahme, gezahlte Vorsteuer und USt-Zahllast ans Finanzamt sind Betriebsausgaben. (In der Doppik dagegen erfolgsneutral als Verbindlichkeit/Forderung.)
- **Aufzeichnungspflichten trotz fehlender Buchführungspflicht:** Anlageverzeichnis (§ 4 Abs. 3 S. 5 EStG), Wareneingangsbuch bei Gewerbetreibenden (§ 143 AO), beschränkt abziehbare Betriebsausgaben getrennt (§ 4 Abs. 7 EStG), GoBD gelten auch hier.
- **Anlage EÜR:** amtlicher Vordruck, elektronische Übermittlung verpflichtend. Die Kategorien der Anlage EÜR sind de facto der „Kontenrahmen" der EÜR.
- **Privatanteile:** Entnahmen/Einlagen, private Kfz-Nutzung (1-%-Regel oder Fahrtenbuch), häusliches Arbeitszimmer — eigene Kategorien.
- **Wechsel EÜR ↔ Bilanzierung:** erfordert Überleitungsrechnung mit Zu-/Abrechnungen (Gewinnkorrekturen), damit kein Geschäftsvorfall doppelt oder gar nicht erfasst wird.

## Datenmodell-Sicht

Die EÜR ist *einseitige* Verbuchung: eine Zahlung wird einer Kategorie zugeordnet. Es gibt aber einen wichtigen Modellierungspunkt: intern kann (und sollte) auch die EÜR über doppelte Buchführung abgebildet werden — Kategorien als Erfolgskonten, Geldkonten als Bestandskonten. Die EÜR ist dann eine *Auswertung* (Projektion) über zahlungswirksame Buchungen, kein eigenes Buchungsmodell. Viele kommerzielle Tools machen genau das. Vorteil: Wechsel zur Bilanzierung wird ein Auswertungswechsel, kein Datenmigrationsprojekt.

→ Modellierungsentscheidung getroffen und gebaut: **EÜR als Projektion über doppelte Buchung** (`CashBasisProjection` in PHP + Node; Fixtures `cash-basis-ten-day-rule`, `two-year-carryover`), nicht als eigener Buchungsstil. Siehe `40-domaenenmodell/offene-fragen.md`.

---

## Konsequenzen für die Packages

- Zufluss/Abfluss-Datum (Zahlungsdatum) muss neben Belegdatum erstklassig im Datenmodell stehen.
- 10-Tage-Regel: Periodenzuordnung kann vom Zahlungsdatum abweichen → Zuordnungslogik mit Regelwerk.
- AfA-Engine wird auch für EÜR gebraucht (Anlageverzeichnis + AfA sind Pflicht).
- USt-Behandlung unterscheidet sich je Gewinnermittlungsart → USt-Logik muss vom Buchungsstil parametrisierbar sein.
- Kategoriesystem der Anlage EÜR als mitgelieferter „Kontenrahmen" für EÜR-Nutzer.

## Offene Fragen — beantwortet (2026-06-07)

- Kleinunternehmer + EÜR: über `TaxProfile` mit Gültigkeitszeitraum gelöst (Fixture small-business-switch); KU-Erlöskonten als Profil-Inhalt.
- Überleitungsrechnung EÜR → Bilanz: durch die Projektions-Entscheidung obsolet — der Datenbestand ist doppisch, der „Wechsel" ist ein Auswertungswechsel.
