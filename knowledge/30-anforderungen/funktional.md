# Funktionale Anforderungen

Stand 2026-06-08: ausformuliert aus den Standardfällen SF-01–26 (`lieferumfang.md`) und den Kontextmodellen (`40-domaenenmodell/`). Jede Anforderung ist testbar formuliert; die SF-Spalte zeigt den abdeckenden Standardfall. Format: MUSS/SOLL/KANN.

## F-CORE — Buchungskern (Ledger)

| ID | Anforderung | SF |
|---|---|---|
| F-CORE-001 | Jede Buchung MUSS Σ Soll = Σ Haben erfüllen; Verstöße werden beim Schreiben abgewiesen. | alle |
| F-CORE-002 | Das Journal MUSS append-only sein; festgeschriebene Buchungen sind unveränderbar, Korrektur nur per Storno (Generalumkehr mit Rückverweis). | 06 |
| F-CORE-003 | Jede Buchung MUSS genau einen Beleg (`voucher`) referenzieren; progressive und retrograde Prüfung MUSS über die Referenzkette möglich sein. | alle |
| F-CORE-004 | Perioden MÜSSEN schließbar sein (nur in Reihenfolge); Buchungen in geschlossene Perioden werden abgewiesen. | 07 |
| F-CORE-005 | Kontenrahmen MÜSSEN als versionierte Daten importierbar sein; Kontenpläne sind mandantenspezifisch ableitbar. | 13 |
| F-CORE-006 | Buchungspositionen MÜSSEN frei definierbare Dimensionen tragen können; Kostenstelle/Kostenträger/Produkt werden als Standardtypen mitgeliefert. | 12 |
| F-CORE-007 | Eigene Konten MÜSSEN jederzeit anlegbar sein (innerhalb der Kontenplan-Systematik). | 13 |
| F-CORE-008 | Die EÜR MUSS als Projektion über zahlungswirksame Buchungen erzeugbar sein (Regeln R1–R6, bewiesen). | 08 |
| F-CORE-009 | Zahlungen MÜSSEN offene Posten referenzieren (auch Teilausgleich, proportionale Kategorie-Aufteilung). | 04 |
| F-CORE-010 | Buchungen/Belege MÜSSEN die Metadaten `recurring`, `due`, `economicYear` tragen können. | 08 |
| F-CORE-011 | Jede Buchung MUSS eine lückenlose Journalnummer und drei Zeitangaben (Belegdatum, Buchungsdatum, Erfassungszeitpunkt) tragen. | 14 |
| F-CORE-012 | Buchungen im Status `entered` DÜRFEN korrigiert werden; jede Korrektur MUSS im Audit-Trail nachvollziehbar sein. | 06 |
| F-CORE-013 | Festschreibung MUSS je Buchung erfolgen; eine Massenoperation „festschreiben bis Datum X" MUSS verfügbar sein. | 07 |
| F-CORE-014 | Buchführungsrelevante Stammdatenänderungen (Konten, Steuerschlüssel, Profile) MÜSSEN protokolliert werden. | — |
| F-CORE-015 | Salden, Hauptbuch, SuSa, Bilanz, GuV MÜSSEN deterministische Projektionen sein (kein gespeicherter Saldo als zweite Wahrheit). | 10 |
| F-CORE-016 | Jede Projektion MUSS mit Bezugsdatum rechnen; Neuberechnung alter Perioden MUSS dauerhaft identische Ergebnisse liefern. | 16 |
| F-CORE-017 | Mandanten MÜSSEN per Profil anlegbar sein (Profil = Kontenrahmen + Steuerschlüssel + Mappings + Voreinstellungen); danach sofort buchbar. | 01 |
| F-CORE-018 | Saldenübernahme MUSS per Eröffnungsbuchung gegen Saldovortragskonten möglich sein; der Beleg (Schlussbilanz Altsystem) ist Pflicht; übernommene AR/AP-Positionen erzeugen offene Posten. | 17 |
| F-CORE-019 | `settle` MUSS Ausgleich mit Differenz (Skonto/uneinbringlich/Bagatelle) unterstützen; die Differenz MUSS als sichtbare Buchungszeile(n) in der ausgleichenden Buchung stehen. | 18 |
| F-CORE-020 | Der Audit-Trail (Korrekturen, Stammdatenänderungen) MUSS Teil des Datenformats sein und Migrationen vollständig überleben. | 15 |
| F-CORE-021 | Bestandskonten tragen implizit vor (kumulative Projektion); Erfolgskonten starten je Geschäftsjahr bei null; es gibt KEINE Saldenabschluss-/Eröffnungsbuchungen. | 17 |
| F-CORE-022 | Geschäftspartner MÜSSEN als schlankes Stammdatenobjekt führbar sein (Name, USt-IdNr., Konto-Refs); Belege und OPs tragen optionale partnerId; OP-Listen sind je Partner filterbar. | 21 |
| F-CORE-023 | OP-Entstehungsregel: ar+Soll → receivable, ap+Haben → payable; Gegenseite-Zeilen (Gutschriften) erzeugen keinen OP, sondern gleichen per settle aus. | 24 |
| F-CORE-024 | Ergebnisverwendung erfolgt als normale Buchung über result_allocation-Konten; includesNetIncome zeigt das noch nicht verwendete Ergebnis. | 25 |
| F-CORE-025 | Geschäftsjahre MÜSSEN per createFiscalYear anlegbar sein (lückenlos, überschneidungsfrei). | — |
| F-CORE-026 | trialBalance-Zeilen MÜSSEN openingBalance, debitTotal, creditTotal und balance führen (SuSa-Praxis). | — |
| F-CORE-027 | Eine Projektion unfinalizedEntries (älter als X Tage) MUSS die Festschreibe-Frist überwachbar machen. | — |
| F-CORE-028 | Eine Projektion accounts MUSS den Kontenplan schlank lesbar machen (Nummer, Name, Typ, subtype, status) — ohne Salden, Bewegungen oder Hashes; subtype bestimmt die Rolle eines Kontos, status ist die Leseseite von lockAccount. | — |
| F-CORE-029 | Eine Projektion fiscalYears MUSS Geschäftsjahre samt Perioden mit Beginn, Ende und Status liefern (Leseseite von closePeriod/reopenPeriod/closeFiscalYear); ohne Beginn/Ende ist keine Periodenliste ohne Erfindung möglich. | — |
| F-CORE-030 | openItems MUSS neben partnerId auch partnerName führen (aktueller Name aus den Stammdaten) — eine Mahnung ohne Empfänger ist keine Mahnung. | 21 |

## F-TAX — Steuern

| ID | Anforderung | SF |
|---|---|---|
| F-TAX-001 | Steuerschlüssel MÜSSEN Satz, Buchungsregel und Meldekennzahl in Regelversionen mit lückenlosen Gültigkeitszeiträumen bündeln. | 02 |
| F-TAX-002 | `expand` MUSS aus Belegdaten + Steuerschlüssel die vollständige, ausbalancierte Positionserweiterung erzeugen (side-effect-free; Rundung pro Beleg). | 02, 03 |
| F-TAX-003 | Soll-/Ist-Versteuerung MUSS je Mandant mit Gültigkeitszeitraum konfigurierbar sein und bestimmen, wann USt-Positionen wirksam werden. | — |
| F-TAX-004 | Der Kleinunternehmer-Status MUSS mit Gültigkeitszeitraum geführt werden; unterjähriger Statuswechsel MUSS korrekt abgebildet werden. | 11 |
| F-TAX-005 | USt-VA-Kennzahlen MÜSSEN je Zeitraum als Projektion abrufbar sein; bei Ist-Versteuerung folgen sie den OP-Ausgleichen. | 09 |
| F-TAX-006 | Reverse-Charge-Schlüssel MÜSSEN USt und Vorsteuer gleichzeitig erzeugen. | — |
| F-TAX-007 | Die Versteuerungsart des Lieferanten MUSS am Beleg erfassbar sein (Vorsteuerabzug ab 2028 zahlungsabhängig). | — |
| F-TAX-008 | Entgeltminderungen (§ 17 UStG: Skonto, Forderungsausfall) MÜSSEN die Bemessungsgrundlage und Steuer per Korrektur-Buchungszeile mindern und in der VA des Korrekturzeitraums wirken. | 18 |
| F-TAX-009 | Anzahlungen MÜSSEN USt bei Vereinnahmung auslösen (auch bei Soll-Versteuerung, Mindest-Ist) und bei Schlussrechnung verrechnet werden. | 19 |
| F-TAX-010 | Unentgeltliche Wertabgaben MÜSSEN als Buchungsmuster mit USt abbildbar sein (VA-wirksam; EÜR-wirksam ohne Zahlungsfluss, Regel R7). | 20 |
| F-TAX-011 | Die Steuerregelversion MUSS nach dem Leistungsdatum gewählt werden (§ 27 UStG); VA-Zuordnung bei Soll-Versteuerung folgt dem Leistungsdatum. | — |
| F-TAX-012 | Eine Projektion ecSalesList MUSS ig. Umsätze je USt-IdNr. und Zeitraum liefern (ZM-Grundlage; Übermittlung App-Sache). | 21 |

## F-AST — Anlagen

| ID | Anforderung | SF |
|---|---|---|
| F-AST-001 | Anlagegüter MÜSSEN mit AfA-Plan (Methode, Nutzungsdauer, monatsgenauer Pro-rata-Beginn) geführt werden; Nutzungsdauer-Vorschlag aus AfA-Tabellen (Regelmodul). | 05 |
| F-AST-002 | Die GWG-Weiche (Sofortabzug / Sammelposten / Aktivierung) MUSS beim Zugang nach datierten Regelmodul-Grenzen entscheiden. | 05 |
| F-AST-003 | Der AfA-Lauf MUSS idempotent je Periode normale Journal-Buchungen erzeugen. | 05 |
| F-AST-004 | Restbuchwert = AHK − Σ Abschreibungen MUSS als Invariante gelten; Abgänge buchen Restbuchwert und Veräußerungsergebnis aus. | — |
| F-AST-005 | Das Anlageverzeichnis MUSS als Projektion verfügbar sein (auch bei EÜR, § 4 Abs. 3 S. 5 EStG). | 05 |
| F-AST-006 | Sammelposten MÜSSEN jahrgangsbezogen starr über die **vom Pack deklarierte** Dauer (`poolYears`) aufgelöst werden; ob ein Abgang den Posten vermindert, deklariert das Pack ebenfalls (`poolReducedOnDisposal`) — Deutschland: 5 Jahre, Abgang ohne Wirkung (§ 6 Abs. 2a EStG); UK/Australien: Abgang entnimmt. | 06 |
| F-AST-007 | AfA-Pläne MÜSSEN linear und degressiv (Regelmodul-Sätze mit Anschaffungszeitraum) inkl. automatischem Wechsel degressiv→linear unterstützen; Sonder-AfA und AK-Minderung (§ 7g) als Plan-Mechanik. | — |

## F-KLR — Costing

| ID | Anforderung | SF |
|---|---|---|
| F-KLR-001 | Abrechnungsläufe MÜSSEN je Periode versioniert sein (draft → released); Auswertungen lesen nur released Läufe. | 12 |
| F-KLR-002 | Die Abgrenzungsrechnung MUSS regelbasiert übernehmen/ausschließen/ersetzen/hinzufügen und eine beidseitige Abstimmbrücke liefern. | 12 |
| F-KLR-003 | Umlagen MÜSSEN Anbau-, Stufenleiter- und Gleichungsverfahren unterstützen; Verrechnungssumme bleibt erhalten, Hilfskostenstellen enden bei 0. | 12 |
| F-KLR-004 | BAB und Kalkulationssätze MÜSSEN als Projektion eines released Laufs abrufbar sein. | 12 |
| F-KLR-005 | Kalkulatorische Kosten MÜSSEN als eigene Verrechnungen im Costing-Rechnungskreis geführt werden — nie im Fibu-Journal. | 12 |

## F-IO — Import/Export

| ID | Anforderung | SF |
|---|---|---|
| F-IO-001 | GoBD-Datenträgerüberlassung (Z3, Beschreibungsstandard) MUSS aus dem Datenformat erzeugbar sein. | 14 |
| F-IO-002 | Kontenrahmen MÜSSEN im DATEV-kompatiblen Format importierbar sein. | 13 |
| F-IO-003 | Eine CLI MUSS die volle API bedienen (buchen, stornieren, abfragen, auswerten); Ausgaben maschinenlesbar (LLM-Operator). | — |
| F-IO-004 | Ein Datenbestand MUSS von jeder Implementierung gelesen und fortgeschrieben werden können (Cross-Kompatibilität). | 15 |
| F-IO-005 | Buchungsdaten SOLLEN im DATEV-Buchungsstapel-Format exportierbar sein (Steuerberater-Übergabe). | — |
| F-IO-006 | datevExport MUSS zusätzlich Kontenbeschriftungen und Geschäftspartner-Stammdaten exportieren (kind: accounts/partners). | 21 |
| F-IO-007 | Das Package MUSS eine technische Systembeschreibung generieren können (Baustein der Verfahrensdokumentation, GoBD Rz. 151 ff.). | — |
| F-IO-008 | DATEV-Buchungsstapel SOLLEN importierbar sein (Rückweg vom Steuerberater); Formatdetails bei JOB-011 verifizieren. | — |
| F-IO-009 | US-GL-Export nach AICPA Audit Data Standard (General Ledger) — das US-Gegenstück zu F-IO-001 (GoBD-Z3 ist deutsch); MUSS journals/trialBalance/accounts erzeugen (signierte Beträge: Soll +, Haben −). | 32 |
| F-IO-010 | Auch **Operationen** MÜSSEN ihre Eingaben deklarieren (`api-parameters.json`, Block `operations`); der Dispatcher prüft vor dem Routing: unbekannter Schlüssel ⇒ `E_INPUT_INVALID`, falscher Typ wird abgewiesen statt konvertiert, abwesend behält den dokumentierten Standard. Pflichtfelder bleiben bei der Operation (dort ist der Fehlercode genauer). | — |

## Abdeckungsprüfung SF → F

Alle Standardfälle SF-01–26 sind durch Anforderungen abgedeckt (Fixture-Zahl: `validate.py`, SF-15 erfüllt: zweite Runtime Node + bidirektionaler Cross-Test PHP ↔ Node grün). Die Ausformulierung über v0.3–v0.5 hat zahlreiche Anforderungen ergänzt (F-CORE-011…027, F-TAX-006…012, F-AST-004…007, F-IO-005…008) — siehe Versionseinträge im Entscheidungslog.
