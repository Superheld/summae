# Lieferumfang aus Konsumentensicht

Maßstab gegen „zu wenig gebaut": Das Package *beherrscht* Rechnungswesen — die einbettende App baut Oberfläche und Workflows, nie Fachlichkeit. **Jeder Standardfall unten, der App-seitige Fachlogik erfordert, ist ein Befund.**

## Was die einbettende App tut

1. Persistenz-Adapter anschließen (oder mitgelieferten Standard nutzen)
2. Mandant anlegen und **Profil** wählen (z. B. „Freiberufler EÜR", „GmbH SKR04", später „Kommune NRW") — Profil = Bündel aus Kontenrahmen, Steuerschlüsseln, Auswertungs-Mappings, Voreinstellungen
3. Belegdateien speichern (Storage ist App-Sache; das Package verwaltet die Referenz)
4. UI, Workflows, Berechtigungs-Oberfläche, Fristen, Übermittlung

## Was die App geschenkt bekommt

Korrekte USt-Buchungen per Steuerschlüssel; EÜR und Bilanz/GuV als fertige Auswertungen aus demselben Datenbestand; AfA-Läufe inkl. GWG/Sammelposten; GoBD-Festschreibung, Storno, Audit-Trail; offene Posten (Debitoren/Kreditoren); SuSa, BWA-Grundlagen, USt-VA-Kennzahlen; KLR (Dimensionen, Abgrenzung, BAB, Umlagen); Kontenrahmen-Import und eigene Konten; Z3-Export; Periodensteuerung.

## Standardfälle

Jeder Fall soll mit minimaler API-Interaktion machbar sein (Richtwert: ein Aufruf für den Vorgang, plus Abfragen).

**Checkbox-Semantik:** ☑ = die *Referenzimplementierung besteht* die zugehörige Konformitäts-Fixture, je Runtime erneut geprüft. Ob eine Fixture *existiert*, trackt `testing/testsuite/abdeckung.md` — Stand 2026-06-20: 45 Fixtures, 26/26 Fälle haben Fixtures, SF-15 (Cross-Implementierung) erfüllt (zweite Runtime Node + bidirektionaler Cross-Test PHP ↔ Node grün). Die **PHP-Referenz und die Node-Runtime bestehen alle vorhandenen Fixtures** (`80-implementierung/ABSCHLUSSBERICHT.md`).

- [x] SF-01 Mandant mit Profil „Freiberufler EÜR" anlegen — sofort buchbar
- [x] SF-02 Ausgangsrechnung 19 % buchen (ein Aufruf; USt-Aufteilung macht der Steuerschlüssel)
- [x] SF-03 Eingangsrechnung mit Vorsteuer buchen
- [x] SF-04 Zahlung erfassen und offenem Posten zuordnen
- [x] SF-05 Anlagegut erfassen → AfA-Lauf zum Jahresende (inkl. GWG-Entscheidung per Regelmodul)
- [x] SF-06 Festgeschriebene Buchung stornieren (Generalumkehr, Referenz aufs Original)
- [x] SF-07 Periode abschließen; Buchung in geschlossene Periode wird abgewiesen
- [x] SF-08 EÜR-Jahresauswertung inkl. 10-Tage-Regel-Zuordnung
- [x] SF-09 USt-VA-Kennzahlen für ein Quartal abrufen
- [x] SF-10 Bilanz + GuV für GmbH (SKR04-Mapping)
- [x] SF-11 Kleinunternehmer bucht ohne USt; unterjähriger Wechsel zur Regelbesteuerung
- [x] SF-12 Buchung mit Kostenstelle/Kostenträger kontieren; BAB und Umlage rechnen
- [x] SF-13 Eigenes Konto anlegen; Kontenrahmen DATEV-kompatibel importieren
- [x] SF-14 GoBD-Z3-Export erzeugen
- [x] SF-15 Datenbestand aus Implementierung A in B öffnen und weiterbuchen
- [x] SF-16 Auswertung einer Altperiode Jahre später neu berechnen — identisches Ergebnis (datierte Regelmodule)
- [x] SF-17 Mandant mit Eröffnungsbilanz + offenen Posten übernehmen (Saldenübernahme; Beleg = Schlussbilanz Altsystem, Pflicht); Bilanz und OP-Liste stimmen (v0.3, Review G1)
- [x] SF-18 Zahlung mit Skontoabzug: OP voll ausgeglichen, Erlösschmälerung + USt-Korrektur als sichtbare Buchungszeilen, VA korrigiert (v0.3, Review G2)
- [x] SF-19 Anzahlung erhalten (USt bei Vereinnahmung, Mindest-Ist-Versteuerung) + Schlussrechnung mit Verrechnung (v0.3, Review M3)
- [x] SF-20 Unentgeltliche Wertabgabe (z. B. Kfz-Privatnutzung): Buchungsmuster mit USt, korrekt in VA und EÜR (v0.3, Review M7)
- [x] SF-21 Geschäftspartner anlegen, ig. Lieferung mit USt-IdNr., OP-Liste je Partner, ZM-Grundlage, DATEV-Stammdaten-Export (v0.4, Buchhalter-G2 + StB-1/2)
- [x] SF-22 Lohnbuchungsbeleg verbuchen (Brutto, AG-Anteile, Verbindlichkeiten Netto/LSt/SV) (v0.4, Buchhalter-G4)
- [x] SF-23 Bewirtung mit 70/30-Split und vollem Vorsteuerabzug; getrennte Aufzeichnung § 4 Abs. 7 EStG über eigene Konten (v0.4, Buchhalter-G3)
- [x] SF-24 Kundengutschrift erstellen und gegen offenen Posten ausgleichen (v0.4, Buchhalter-M)
- [x] SF-25 Ergebnisverwendung: Gewinnvortrag und Ausschüttung als Buchung, Bilanz-EK korrekt gegliedert (v0.4, Buchhalter-G6)
- [x] SF-26 Geldtransit Bank↔Kasse und PSP-Konto: EÜR-neutral, Kassenkonto als Geldkonto (v0.4, Buchhalter-M6/M)
- [x] SF-27 Pack-Komposition: ein aus Modulen komponiertes Pack ergibt byte-identisch dasselbe Bündel wie
  hand-gereichte `ruleModules`, ein daraus gebauter Mandant bucht identisch, und `overrides[remove]` ist
  rückstandsfrei (v0.3, F-PACK-001/002/003)
  — *am 2026-08-28 nachgetragen: fünf Fixtures deckten diesen Standardfall ab und `validate.py` zählte ihn,
  während diese Liste bei SF-26 endete; die Zählung war also die einzige Stelle, an der es SF-27 gab.*

Liste wächst mit Befunden; jeder Fall bekommt Eingaben + erwartete Ergebnisse als Fixture.

## Wofür das Package NICHT reicht (StB-5 — Ehrlichkeit für Einbettungs-Entwickler)

Die bewusste Abgrenzung führt **`out-of-scope.md`** (Master) — u. a. Kassensysteme/TSE, Lohnabrechnung, ELSTER-/Finanzverwaltungs-Übermittlung, E-Rechnungs-Erzeugung/-Validierung und Steuerermittlung über USt hinaus (ESt/KSt/GewSt) liegen außerhalb des Packages.
