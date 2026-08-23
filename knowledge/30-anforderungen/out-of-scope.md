# Out of Scope (bewusste Abgrenzung)

## Abgrenzungsprinzip: Fähigkeiten, nicht rechtliche Pflichten

Das Backend stellt **Fähigkeiten** bereit; rechtliche **Workflows** erfüllt die einbettende App. Konkret:

| Backend (wir) | App (Einbettung) |
|---|---|
| GoBD-konformes Buchen, Festschreiben, Stornieren | Rechnungen schreiben und versenden |
| Belegreferenz verwalten (**nicht** die Datei — Storage ist App-Sache, `lieferumfang.md`) | E-Rechnung empfangen/erzeugen/parsen (XRechnung, ZUGFeRD) |
| Auswertungen und Kennzahlen liefern (USt-VA-Werte, Bilanz, EÜR) | Übermittlung (ELSTER & Co.), Fristen, Workflows |
| Den **selbstbeschreibenden Datensatz** für GoBD Z3 erzeugen (`journalExport`: Streams, SHA-256 je Stream, Feldkatalog) + DATEV-Format | Die **Abbildung** dieses Datensatzes auf den Beschreibungsstandard (`index.xml`/DTD, IDEA-Import) · Aufbewahrungsorganisation, Archivsystem |
| Prüf- und Validierungsregeln durchsetzen | Nutzerführung, Berechtigungs-UI, Freigabeprozesse |

Faustregel: Wenn eine Anforderung lautet „der Anwender muss bis zum X …", ist es App-Sache. Wenn sie lautet „die Daten müssen …", ist es unsere Sache.

> **Die Z3-Zeile ist die, die am ehesten missverstanden wird**, deshalb ausgeschrieben: `datenformat.md` hält fest, dass der Export nach Beschreibungsstandard „eine **Abbildung**, keine Erfindung" ist — der Feldkatalog liegt bei, die `index.xml` nicht. Verlangt eine Prüfung eine Datenträgerüberlassung, erzeugt die App (oder ihr Werkzeug) diese Datei aus `journalExport`. **Kein Test von summae wird deshalb rot**, und genau deshalb steht es hier und nicht nur im Konformitätsdokument.

## Nicht Teil der Packages (v1)

Einbettungsprojekte können das selbst ergänzen:

- **UI/Frontend** jeder Art — die Packages sind Bibliotheken.
- **ELSTER-Übermittlung** (USt-VA, E-Bilanz, Anlage EÜR): die Packages liefern die Daten/Kennzahlen, die Übermittlung ist Sache der Einbettung. (Zertifizierungs- und Pflegeaufwand zu hoch.)
- **Lohnbuchhaltung** — eigenes, hochreguliertes Fachgebiet; nur die Verbuchung der Lohnergebnisse (Lohnbuchungsbeleg) ist in Scope.
- **Banking** (FinTS, PSD2, CAMT/CSV-Parsing): komplett App-Sache — das Package importiert keine Kontoauszüge; `postVoucher`/`settleVoucher` sind die Andockpunkte, die eine App mit geparsten Umsätzen füttert (präzisiert 2026-06-08, Buchhalter-Review).
- **Kassensysteme/TSE** (§ 146a AO) — vormerken, nicht v1.
- **Belegerkennung/OCR und E-Rechnungs-Parsing** — Belege werden als Referenz + Datei verwaltet, nicht ausgelesen. E-Rechnung ist App-Sache (entschieden 2026-06-07, siehe Abgrenzungsprinzip oben).
- **IFRS, AT/CH** — Architektur hält die Tür offen (Kern jurisdiktionsfrei), Inhalte kommen später.
- **Steuerberatung/-berechnung** über USt hinaus (ESt, GewSt, KSt): keine Steuerermittlung, nur Buchführung.
- **KLR-Steuerungsinstrumente** (entschieden 2026-08-23): Plankostenrechnung und Abweichungsanalyse, Prozesskostenrechnung, Deckungsbeitrags-/Teilkostenrechnung. Das sind Controlling-Werkzeuge ohne Rechtsbezug — „*der Nutzer muss…*", also App. **Bewusst NICHT out of scope** ist der bilanzwirksame Teil der KLR: die **Herstellungskostenermittlung** für die Vorratsbewertung (§ 255 Abs. 2 HGB) samt ihrer Voraussetzungen (Gleichungsverfahren für gegenseitige Kostenstellenbeziehungen, Zuschlagskalkulation Kostenstelle → Kostenträger). Der Aufbau ist Mechanik (Kern), *welche Bestandteile einbezogen werden müssen bzw. dürfen* ist Recht (Pack). Noch nicht gebaut; Reihenfolge und Begründung in `00-projekt/backlog-rechtssicherheit-2026-08-23.md`, P7.

## Jurisdiktion: v1 = DE-Pack auf neutralem Kern

- **v1 liefert genau ein vollständiges Pack: Deutschland** — auf einem jurisdiktionsfreien Kern (NF-5.3, `40-domaenenmodell/jurisdiction-profil.md`). „Erstmal nur DE" ist damit keine Sackgasse, sondern ein vollständiger erster Pack auf neutraler Mechanik.
- **Weitere Jurisdiktionen sind additiv und nachfragegetrieben**, kein Umbau: ein neues Pack = neue Daten (Kontenrahmen, Steuersätze/-codes, Mappings, Rundungspolitik). EU-weit überwiegend **Konfiguration** auf einem geteilten VAT-Modul.
- **Echter Code-Bruch nur bei neuem Steuer*paradigma*:** US-Sales-Tax (kein Vorsteuerabzug) ist ein anderer Algorithmus, keine andere Zahl — das ist Code, nicht Config. Saubere Ja/Nein-Grenze für jede künftige Anfrage.
- **Konformitätsanspruch (Lackmustest als Vertrag):** Der Kern gilt als jurisdiktionsfrei, wenn DE-Pack *und* ein bewusst schräges fiktives Pack (3-NK-Währung, Rundung je Position, kein Vorsteuerabzug) ohne Kernänderung laufen. Heute dokumentiert, ausführbar getestet erst mit den Pack-Policy-Feldern (nachfragegetriebene Stufe, siehe `40-domaenenmodell/offene-fragen.md`).

Begründungen, falls strittig, in `00-projekt/entscheidungen.md` dokumentieren.
