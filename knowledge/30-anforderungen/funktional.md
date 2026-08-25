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
| F-CORE-006 | Buchungspositionen MÜSSEN frei definierbare Dimensionen tragen können. **Präzisiert 2026-08-24:** Typen und Werte sind *Mandanten*-Stammdaten (`defineDimensionType`/`defineDimensionValue`) — eine Jurisdiktion hat keine Meinung darüber, wie eine Firma ihre Kostenstellen nennt; das Pack liefert nur `dimensionRules` (welche Konten ohne welche Dimension nicht bebucht werden dürfen). Der Typcode `costCenter` ist Kern-Vokabular (Primärkosten-Einlass der KLR), nicht Pack-Daten. „Standardtypen mitgeliefert" beschrieb einen später verworfenen Entwurf. | 12 |
| F-CORE-007 | Eigene Konten MÜSSEN jederzeit anlegbar sein (innerhalb der Kontenplan-Systematik). | 13 |
| F-CORE-008 | Die EÜR MUSS als Projektion über zahlungswirksame Buchungen erzeugbar sein (Regeln R1–R6, bewiesen). **Ergänzt 2026-08-24:** und MUSS ihr Ergebnis nennen — `surplus` samt `totalIncome`/`totalExpenses`. Vorher lieferte sie jede Zahl, aus der die Rechnung besteht, und nicht die eine, für die sie existiert; der Aufrufer durfte sie auch nicht selbst bilden, weil das Subtrahieren zweier Felder derselben Projektion genau die Arithmetik ist, die diese Bibliothek Einbettern untersagt. `incomeStatement` nennt `netIncome`, `balanceSheet` beide Summen — die dritte Rechnung derselben Familie nannte keine. | 08 |
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
| F-CORE-031 | Eine Projektion journal MUSS das Journal gefenstert (fiscalYear + fromDate/toDate) und seitenweise (offset/limit) liefern — vollständig je Buchung (alle Zeilen, Kontonummer UND -name), ohne Hashes. Blättern zählt BUCHUNGEN, nicht Zeilen; `count` nennt die Gesamtzahl im Fenster vor dem Blättern. | 14 |
| F-CORE-032 | Partner-Stammdaten MÜSSEN korrekt anlegbar UND korrigierbar sein: `name` ist Pflicht (leer/whitespace ⇒ `E_INPUT_INVALID`), `kind` nur `customer`/`supplier`/`both`, `accountNumbers`/`address` per updatePartner änderbar (ersetzend), `paymentTermsDays: null` löscht den Zahlungsterm wie `vatId: null` die USt-IdNr. Kein deletePartner — die Bücher behalten, worauf sie verweisen. **Ergänzt 2026-08-24:** `accountNumbers` MÜSSEN gegen den Kontenrahmen geprüft werden (`E_ACCOUNT_UNKNOWN`), bei `createPartner` wie bei `updatePartner`. Ein Partner auf Konto 9999 in Büchern, deren Rahmen bei 3110 endet, ist für jeden Leser falsch, nicht nur für die erfassende Maske — dasselbe Argument, das `name` und `kind` hierher geholt hat. Die ersetzende Semantik bleibt: eine leere Liste löscht die Verknüpfung. | 21 |
| F-CORE-033 | Zu `lockAccount` MUSS es `unlockAccount` geben (`locked` → `active`), beide mit Audit-Eintrag (`locked`/`unlocked` + Status-Diff). Die Unumkehrbarkeit einer Kontosperre ist **kein** Rechtserfordernis — geschützt wird die Buchung, bei Stammdaten verlangt die GoBD nur die Protokollierung; deshalb Substrat und nicht Pack. | — |
| F-CORE-034 | Ein Partner MUSS als „nicht mehr in Gebrauch" markierbar und wieder aktivierbar sein (`deactivatePartner`/`reactivatePartner`, `status` am Datensatz, beide Richtungen im Audit-Trail). **Zustand, keine Kontrolle:** ein inaktiver Partner weist nichts ab — ob ein Picker ihn noch anbietet, ist App-Workflow. Ersetzt kein `deletePartner`: die Bücher behalten, worauf sie verweisen. | 21 |
| F-CORE-035 | Die Konfiguration eines Mandanten MUSS lesbar sein: eine Projektion `tenantConfiguration` liefert Steuerprofil, Dimensions-Stammdaten (Typen und Werte), die Pflicht-Dimensionsregeln, das Umlageschema und die geltenden Mappings (Kennung/Sorte/Version, **nicht** deren Positionen — die gehören ins Pack, das der Einbetter selbst hält). Seit `summae_tenants` die Konfiguration speichert und der gespeicherte Datensatz gewinnt (SPEC-015), ist die Kopie der einbettenden Anwendung nicht mehr per Konstruktion dieselbe: geschrieben wurden vier Dinge, zurückgemeldet genau eines (das Steuerprofil, über `systemDescription`). Eine Maske mit Kostenstellenfeld konnte die zulässigen Werte nur erfahren, indem sie bucht und `E_DIMENSION_INVALID` liest. Berichtet wird, **was gilt**, nicht was gespeichert ist — die Dimensionsregeln stehen in keinem Datensatz (sie kommen bei jedem Öffnen aus dem Pack), und die Mapping-Liste enthält die des Packs samt der per `importMapping` daraufgelegten. | — |
| F-CORE-036 | Der Änderungs-Trail MUSS gezielt abrufbar sein, nicht nur als Ganzes: `auditLog` filtert nach `objectType`, `objectId`, `actor` und `action` (UND-verknüpft, abwesend heißt „filtert nicht") und blättert über `offset`/`limit` mit `count` = Treffer **vor** dem Blättern — dieselbe Zusage wie bei `journal`. Vorher gab es nur `from`/`to`, also war die eigentliche Prüferfrage („was ist mit *dieser* Buchung passiert", „wer hat *dieses* Konto angefasst") über die API nicht stellbar: der Aufrufer musste den gesamten Trail holen und außerhalb filtern. Das ist zweimal die falsche Stelle — es transportiert die am schnellsten wachsende Tabelle des Systems über eine Grenze, um das meiste davon wegzuwerfen, und es macht progressive wie retrograde Nachvollziehbarkeit zur Eigenschaft der einbettenden Anwendung statt der Bücher (GoBD Rz. 107 ff.). | — |
| F-CORE-037 | `journal` und `unfinalizedEntries` MÜSSEN je Buchung den `actor` führen — wer sie erfasst hat. Die Buchung selbst trägt keinen Urheber: die Tatsache steht im Audit-Trail und sonst nirgends, also konnte die Leseseite alles über eine Buchung berichten außer, wer sie gemacht hat. Eine Anwendung, die **Funktionstrennung** prüft („niemand darf einen Stapel festschreiben, der eigene Buchungen enthält"), las deshalb bei *jedem* Festschreiben den kompletten Trail und baute die Zuordnung selbst nach — eine Einbettung, die Bibliotheks-Zustand aus einer Spur rekonstruiert. Der Trail bleibt die einzige Quelle; der Urheber wird **nicht** auf die Buchung kopiert (append-only: dort wäre er nie korrigierbar). | — |

## F-TAX — Steuern

| ID | Anforderung | SF |
|---|---|---|
| F-TAX-001 | Steuerschlüssel MÜSSEN Satz, Buchungsregel und Meldekennzahl in Regelversionen mit lückenlosen Gültigkeitszeiträumen bündeln. | 02 |
| F-TAX-002 | `expand` MUSS aus Belegdaten + Steuerschlüssel die vollständige, ausbalancierte Positionserweiterung erzeugen (side-effect-free; Rundung pro Beleg). | 02, 03 |
| F-TAX-003 | Soll-/Ist-Versteuerung MUSS je Mandant mit Gültigkeitszeitraum konfigurierbar sein und bestimmen, wann USt-Positionen wirksam werden. **Präzisiert 2026-08-24:** „konfigurierbar" heißt auch *prüfbar* — `taxationMethod` und `vatPeriod` sind auf ihre dokumentierten Werte begrenzt (`E_INPUT_INVALID`), nicht auf einen Default zurückgebogen. Vorher war alles außer `cash` gleich `accrual` und alles außer `monthly` gleich `quarterly`: ein Tippfehler in der Konfigurationsdatei des Einbetters entschied still, ob USt bei Rechnung oder bei Zahlung fällig wird. `vatPeriod` kennt zusätzlich `yearly` und ist rein deskriptiv — es benennt das Melde­fenster und wählt keines (das tut `vatReturn` selbst). | — |
| F-TAX-004 | Der Kleinunternehmer-Status MUSS mit Gültigkeitszeitraum geführt werden; unterjähriger Statuswechsel MUSS korrekt abgebildet werden. | 11 |
| F-TAX-005 | USt-VA-Kennzahlen MÜSSEN je Zeitraum als Projektion abrufbar sein; bei Ist-Versteuerung folgen sie den OP-Ausgleichen. | 09 |
| F-TAX-006 | Reverse-Charge-Schlüssel MÜSSEN USt und Vorsteuer gleichzeitig erzeugen. | — |
| F-TAX-007 | Die Versteuerungsart des Lieferanten MUSS am Beleg erfassbar sein (Vorsteuerabzug ab 2028 zahlungsabhängig). | — |
| F-TAX-008 | Entgeltminderungen (§ 17 UStG: Skonto, Forderungsausfall) MÜSSEN die Bemessungsgrundlage und Steuer per Korrektur-Buchungszeile mindern und in der VA des Korrekturzeitraums wirken. | 18 |
| F-TAX-009 | Anzahlungen MÜSSEN USt bei Vereinnahmung auslösen (auch bei Soll-Versteuerung, Mindest-Ist) und bei Schlussrechnung verrechnet werden. | 19 |
| F-TAX-010 | Unentgeltliche Wertabgaben MÜSSEN als Buchungsmuster mit USt abbildbar sein (VA-wirksam; EÜR-wirksam ohne Zahlungsfluss, Regel R7). | 20 |
| F-TAX-011 | Die Steuerregelversion MUSS nach dem Leistungsdatum gewählt werden (§ 27 UStG); VA-Zuordnung bei Soll-Versteuerung folgt dem Leistungsdatum. | — |
| F-TAX-012 | Eine Projektion ecSalesList MUSS ig. Umsätze je USt-IdNr. und Zeitraum liefern (ZM-Grundlage; Übermittlung App-Sache). | 21 |
| F-TAX-013 | `vatReturn` MUSS in `gapWarnings[]` melden, wenn eine Buchung ein Steuerkonto (`tax_in`/`tax_out`) ohne Steuerschlüssel berührt — solche Beträge gehen in keine Kennzahl ein. Melden, nicht verhindern (Korrekturbuchungen dürfen das). | — |
| F-TAX-014 | Eine nachträgliche Entgeltminderung MUSS über die normale Buchungs-API in die Steuermeldung gelangen: `postVoucher`/`expandTax` mit `reduction: true` buchen das **Spiegelbild** der durch `direction` beschriebenen Buchung — Netto-, Steuer- und Gegenzeile tauschen die Seite — und taggen es so, dass die Meldekennzahl **sinkt** (negative Basis im Tag). Gleicher Steuerschlüssel, gleiche Version, gleiche Kennzahl: die Minderung gehört in die Kennzahl, unter der die Leistung gemeldet wurde. **Rechtsfrei und deshalb Flag statt Mechanismus:** *ob* Skonto, Gutschrift oder Preisnachlass die Bemessungsgrundlage ändern, ist Recht des Packs und Entscheidung des Aufrufers (§ 17 UStG im DE-Pack); *das Spiegeln einer besteuerten Buchung* ist Mechanik und überall gleich. Erreichbar war der Fall vorher nur über ein handgeschriebenes `taxTag` mit negativer `baseMoney` an einem einfachen `post` (so pinnt es `core/settlement-discount` seit v0.4) — das verlangt vom Aufrufer die Tag-Form, die angewandte Regelversion und die Meldekennzahl, also Bibliotheks-Internes und eine deutsche Formularnummer auf dem Bildschirm einer Anwendung. | 18 |

## F-AST — Anlagen

| ID | Anforderung | SF |
|---|---|---|
| F-AST-001 | Anlagegüter MÜSSEN mit AfA-Plan (Methode, Nutzungsdauer, monatsgenauer Pro-rata-Beginn) geführt werden; Nutzungsdauer-Vorschlag aus AfA-Tabellen (Regelmodul). | 05 |
| F-AST-002 | Die GWG-Weiche (Sofortabzug / Sammelposten / Aktivierung) MUSS beim Zugang nach datierten Regelmodul-Grenzen entscheiden. | 05 |
| F-AST-003 | Der AfA-Lauf MUSS idempotent je Periode normale Journal-Buchungen erzeugen. | 05 |
| F-AST-004 | Restbuchwert = AHK − Σ Abschreibungen MUSS als Invariante gelten; Abgänge buchen Restbuchwert und Veräußerungsergebnis aus. | — |
| F-AST-005 | Das Anlageverzeichnis MUSS als Projektion verfügbar sein (auch bei EÜR, § 4 Abs. 3 S. 5 EStG). **Ergänzt 2026-08-24:** jede Zeile MUSS zusätzlich ausweisen, ob die Sonderabschreibung gewählt wurde, mit welchem Budget und wieviel davon übrig ist (`specialDepreciation: {elected, allowance, remaining}`). `bookSpecialDepreciation` antwortet mit dem Rest *nach* der Buchung — zu spät für die Frage, ob eine Zeile die Buchung überhaupt anbieten darf. | 05 |
| F-AST-006 | Sammelposten MÜSSEN jahrgangsbezogen starr über die **vom Pack deklarierte** Dauer (`poolYears`) aufgelöst werden; ob ein Abgang den Posten vermindert, deklariert das Pack ebenfalls (`poolReducedOnDisposal`) — Deutschland: 5 Jahre, Abgang ohne Wirkung (§ 6 Abs. 2a EStG); UK/Australien: Abgang entnimmt. | 06 |
| F-AST-007 | AfA-Pläne MÜSSEN linear und degressiv (Regelmodul-Sätze mit Anschaffungszeitraum) inkl. automatischem Wechsel degressiv→linear unterstützen; Sonder-AfA und AK-Minderung (§ 7g) als Plan-Mechanik. | — |

## F-KLR — Costing

| ID | Anforderung | SF |
|---|---|---|
| F-KLR-001 | Abrechnungsläufe MÜSSEN je Periode versioniert sein (draft → released); Auswertungen lesen nur released Läufe. **Ergänzt 2026-08-24:** und MÜSSEN auflistbar sein (Projektion `costingRuns`, filterbar nach Jahr und Periode). BAB, Zuschlagssätze und Herstellungskosten verlangen alle eine `runId`, und keine Projektion sagte, woher eine kommt — wer keine aufgehoben hatte, kam an keine heran, und ein Einbetter führte notgedrungen ein zweites Register neben den Büchern. **Präzisiert 2026-08-24:** „versioniert" heißt persistent — Läufe liegen hinter einem eigenen Port (`CostingRunRepository`), die nächste Version kommt aus dem Bestand, nicht aus einem Zähler im Prozess. | 12 |
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
| F-IO-011 | `ecSalesList` MUSS eine ig. Lieferung, die es **nicht** melden kann, als nicht meldbar ausweisen (`gapWarnings`, Form wie bei `vatReturn`): ohne USt-IdNr. des Empfängers gibt es keine Zeile — und genau dieser Fall ist der, in dem etwas nicht stimmt (§ 6a Abs. 1 Nr. 3 UStG). Unterschieden wird `partner_without_vat_id` von `supply_without_partner`, weil die Abhilfe eine andere ist. Keine Abweisung: ob eine fehlende USt-IdNr. die Lieferung steuerpflichtig macht, ist Recht des Packs und Sache der einbettenden Anwendung; die Bibliothek schuldet nur, dass der Fall nie unsichtbar ist. | 21 |

## Abdeckungsprüfung SF → F

Alle Standardfälle SF-01–26 sind durch Anforderungen abgedeckt (Fixture-Zahl: `validate.py`, SF-15 erfüllt: zweite Runtime Node + bidirektionaler Cross-Test PHP ↔ Node grün). Die Ausformulierung über v0.3–v0.5 hat zahlreiche Anforderungen ergänzt (F-CORE-011…027, F-TAX-006…012, F-AST-004…007, F-IO-005…008) — siehe Versionseinträge im Entscheidungslog.
