# GoBD — Compliance-Anforderungen an die Software

GoBD: „Grundsätze zur ordnungsmäßigen Führung und Aufbewahrung von Büchern, Aufzeichnungen und Unterlagen in elektronischer Form sowie zum Datenzugriff". **Maßgebliche Fassung (verifiziert Stand 06/2026):** BMF-Schreiben vom 28.11.2019, geändert durch BMF-Schreiben vom 11.03.2024 und vom 14.07.2025 (Anpassungen v. a. wegen E-Rechnung: strukturierte Datensätze müssen nicht mehr bildlich aufbewahrt werden, der Datensatz selbst genügt; rein technische Belege ohne steuerliche Relevanz sind nicht archivierungspflichtig). Gilt für **alle** Buchführungs- und Aufzeichnungspflichtigen, auch EÜR. Für die Packages sind die GoBD die wichtigste nicht-funktionale Anforderungsquelle.

## Kernanforderungen

1. **Nachvollziehbarkeit und Nachprüfbarkeit:** progressive Prüfung (Beleg → Buchung → Auswertung) und retrograde Prüfung (Auswertung → Beleg) müssen jederzeit möglich sein. → lückenlose Verweisketten im Datenmodell.
2. **Vollständigkeit:** jeder Geschäftsvorfall erfasst, einzeln, lückenlos. Keine Saldierung von Vorgängen.
3. **Richtigkeit:** Abbildung in Übereinstimmung mit den tatsächlichen Verhältnissen.
4. **Zeitgerechte Erfassung:** unbare Geschäftsvorfälle binnen 10 Tagen erfassen (grundsätzlich); **Kasseneinnahmen täglich**; periodengerechte Buchung bis zum Ablauf des Folgemonats.
5. **Ordnung:** systematische Erfassung (Kontierung), Trennung barer/unbarer Vorgänge.
6. **Unveränderbarkeit (zentral!):** Eine Buchung darf nach Festschreibung nicht mehr verändert werden, ohne dass der ursprüngliche Inhalt feststellbar bleibt (§ 146 Abs. 4 AO). Änderungen nur durch protokollierte Korrekturen/Storni. → **Festschreibung** ist ein eigener Zustand im Lebenszyklus einer Buchung: *erfasst (änderbar) → festgeschrieben (unveränderbar)*. Festschreibung spätestens mit USt-Voranmeldung.

## Weitere Pflichten

- **Journalfunktion:** vollständige, zeitgerechte, formal richtige Darstellung aller Buchungen in Buchungsfolge.
- **Kontenfunktion:** Darstellung nach Sach- und Personenkonten.
- **Protokollierung:** Änderungen an Stammdaten (Konten, Steuerschlüssel) und Einstellungen müssen protokolliert werden, wenn sie buchführungsrelevant sind.
- **Aufbewahrung (verifiziert Stand 06/2026):** Bücher, Aufzeichnungen, Jahresabschlüsse: **10 Jahre**; **Buchungsbelege/Rechnungen: 8 Jahre** (verkürzt ab 2025 durch das Vierte Bürokratieentlastungsgesetz, § 147 Abs. 1 Nr. 4 AO, § 257 HGB; Ausnahme: Banken/Versicherungen weiter 10 Jahre). Frist läuft nicht ab, solange Festsetzungsfrist offen (§ 169 AO — bei Steuerhinterziehung 10 Jahre; Vorsicht beim automatischen Löschen!). Maschinelle Auswertbarkeit über die gesamte Dauer; bei Systemwechsel Daten migrieren oder Altsystem vorhalten.
- **Datenzugriff der Finanzverwaltung (Z1/Z2/Z3):** unmittelbarer Zugriff, mittelbarer Zugriff, Datenträgerüberlassung. Z3 praktisch = **GoBD/GDPdU-Export** (Beschreibungsstandard, IDEA-kompatibel) — De-facto-Pflichtfeature jeder Buchhaltungssoftware.
- **Verfahrensdokumentation:** Beschreibung von System, Prozessen, Kontrollen — betrifft den Anwender, aber die Software muss die technische Doku liefern können.
- **Internes Kontrollsystem (IKS):** Zugriffskontrollen, Funktionstrennung, Abstimmkontrollen.

## Verwandte Themen (vormerken)

- **Kassenführung:** § 146a AO, TSE-Pflicht für elektronische Kassen, Kassensicherungsverordnung — nur relevant, falls Kassenmodul je Scope wird.
- **E-Rechnung:** Pflicht zum Empfang strukturierter E-Rechnungen (B2B) seit 2025, stufenweise Ausstellungspflicht (EN 16931, XRechnung, ZUGFeRD) — betrifft Belegimport.

---

## Konsequenzen für die Packages

- **Buchungs-Lebenszyklus mit Festschreibung** ist Kernmodell, nicht Feature: Entwurf → festgeschrieben; danach nur Storno. Append-only-Journal deckt das natürlich ab.
- Audit-Trail für buchführungsrelevante Stammdatenänderungen (Konten, Steuerschlüssel, Mandanteneinstellungen) im Kern.
- Beleg-Verknüpfung verpflichtend im Datenmodell (Buchung ↔ Belegreferenz, idealerweise ↔ Belegdatei).
- Export nach Beschreibungsstandard (Z3) als Standard-Feature; das Datenformat sollte diesen Export trivial machen.
- Zeitstempel-Disziplin: Erfassungszeitpunkt ≠ Buchungsdatum ≠ Belegdatum — alle drei führen.
- Das gemeinsame Datenformat **ist** die beste GoBD-Versicherung: maschinelle Auswertbarkeit und Systemwechselfähigkeit sind eingebaut.

## Offene Fragen — beantwortet (2026-06-07)

- Festschreibung: **Zustand je Buchung, Massenauslöser „festschreiben bis Datum"** (F-CORE-013).
- Aufbewahrungsfristen: **App-Sache** (Abgrenzungsprinzip); das Backend liefert Belegtyp und alle Datumsfelder, keine Löschlogik.
