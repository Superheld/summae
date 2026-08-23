# Offene Fragen (Modell + fachlich)

Geordnet nach Tragweite. Erledigte Fragen wandern mit Antwort + Datum nach unten in „Beantwortet".

## Internationalisierung (Zukunftsachse, nachfragegetrieben — Rahmen steht, Umsetzung vertagt)

Architektur-Rahmen ist integriert (NF-5.3 Triade, `jurisdiction-profil.md`, Determinismus-Attribution, Scope-Ehrlichkeit). Offen bleibt die *Umsetzung* der „Tür durchschreiten"-Hälfte:

- **Pack-Policy-Felder ins Format** (`roundingMode`, `taxRoundingGranularity`, Währungsskala) + amount-Pattern auf 0–N Nachkommastellen lockern. Additiv/rückwärtskompatibel — erst nötig, wenn ein zweites Pack kommt. Schaltet zugleich das **fiktive Test-Pack** frei.
- **Zweites reales Pack:** AT am billigsten (nahe DE), FR am aussagekräftigsten (PCG-Pflichtkontenrahmen + FEC-Export). Welches zuerst?
- **US in Scope?** Einziger echter Code-Bruch (zweites Steuerparadigma, kein Vorsteuerabzug) — bewusste Ja/Nein-Entscheidung.
- **Multi-Currency-*Buchung*** (Fremdwährung mit Kursdifferenzen): „Tür durchschreiten", Felder reserviert (v2). Timing?
- **Export-Adapter** SAF-T (OECD) / FEC (FR) — je dünner Serializer über derselben Projektion wie Z3/DATEV.
- **Komponierbare Packs (Umsetzung):** Modul-Registry, Pack-Resolver (Abhängigkeits-/Integritätsprüfung), Fehlercodes `E_PACK_UNRESOLVED_REF`/`E_PACK_INCOHERENT`, Manifest-Format im Datenformat, erste Resolver-Fixtures. Konzept + Granularität (kohärente Regelsätze) stehen in `jurisdiction-profil.md` (Design 2026-06-09); Bau nachfragegetrieben. **Auslösebedingung eingetreten (2026-06-09):** Der „erste App-Bedarf nach Teil-Packs" ist da — Bruces Konsumenten-Projekte (Frage 3: Haushaltsbuch, NK-Abrechnung) sind genau Nutzungsweg 3 (à la carte). Zweites Länder-Pack dagegen ausdrücklich zurückgestellt (erstmal DE).

## Noch offen (alle bewusst vertagt — nichts blockiert Phase 4)

1. **Kommune-Paket** (wartet auf Priorisierung kommunaler Projekte): Finanzrechnung als parallel gebuchte zweite Rechnung oder Projektion; Obligo-Modellierung (Vorentscheidung: Pre-Posting-Hook, `context-map.md`); Referenz-Bundesland für GemHVO-Erstausarbeitung.
2. **DATEV-SKR-Lizenz:** **ENTSCHIEDEN 2026-06-21** (`00-projekt/entscheidungen.md`) — eigener offener Basis-Rahmen `summae-base` *statt* SKR-Rahmendaten mitliefern; SKR03/04 bleiben über Import verfügbar. Mitliefer-/Lizenzproblem entfällt. Offener Folgepunkt: Nummern-Mapping `summae-base` → SKR/BU für die `datevExport`-StB-Übergabe (nachfragegetrieben).
3. **Erste Konsumenten-Projekte (Antwort konkretisiert 2026-06-09, Bruce):** Zwei projektindividuelle Kompositionen, bewusst **keine** kuratierten Packs: (a) **privates Haushaltsbuch** — Substrat + Assets-Expansion (Inventarliste = assetRegister, Abschreibungen mit eigenen Regeldaten statt AfA-Tabellen), praktisch kein Rechtsinhalt; (b) **Nebenkostenabrechnung für Vermieter** — KLR-Teilmenge (Kostenstellen = Wohneinheiten, Umlageschlüssel = AllocationScheme, umlagefähig/nicht umlagefähig = Abgrenzungsregeln); BetrKV-/§ 556-Fristen sind App-Sache. Damit ist der KLR-Scope nachfragebelegt. Offen: Reihenfolge der beiden, NF-7-Ziele daran schärfen.
4. **Costing-Läufe ins Format:** released Läufe sind heute prozesslokal (Adapter-Annahme 5, `ABSCHLUSSBERICHT.md`); vor Cross-Implementation-Austausch (SF-15) gehört `costingRuns.jsonl` spezifiziert oder die Aussparung explizit gemacht. Spätestens mit der Node-Portierung entscheiden.
5. **Fixture-Lücken der Suite** (Gesamt-Review 2026-06-09): **geschlossen 2026-06-14.** `vat-return-reversal` (Storno→VA) und `vat-return-cash-basis-rounding` (Ist-Versteuerung krumme Teilzahlung) gebaut, gegen die PHP-Referenz verifiziert (Suite 45/45 grün). Die Verifikation förderte SPEC-010 (Fixture-Fehler korrigiert) und SPEC-011 (PHP-Bug gefixt) zutage — Details `80-implementierung/SPEC-FINDINGS.md`. Daraus zwei fachliche Rückfragen → eigener Block „Für den Rechercheagenten" unten. § 17-Korrektur-VA und Generalumkehr-Vorzeichen waren bereits abgedeckt (Eigenprüfung Gesamt-Review).
6. **SPEC-FINDINGS aus der Node-Portierung** (nächste Runtime): Rückfluss ins Entscheidungslog wie bei PHP. *(PHP-Findings SPEC-001–009 sind erledigt, PHP-Referenz abgeschlossen — `80-implementierung/ABSCHLUSSBERICHT.md`.)*

## Für den Rechercheagenten (fachliche Klärung, eröffnet 2026-06-14)

Aufgetaucht bei der Verifikation der beiden 2026-06-09-Steuer-Fixtures gegen die PHP-Referenz (SPEC-010/SPEC-011). Beide betreffen die USt-Voranmeldung. Implementierung läuft mit der jeweils plausibelsten Lesart weiter; diese ist zu bestätigen oder zu korrigieren. **Rechtsstand maßgeblich: 06/2026** (vgl. verifizierte Stände unter „Beantwortet").

**RQ-1 — Generalumkehr/Storno: in welchen VA-Zeitraum?** (Quelle: SPEC-011, `datenformat.md` Leistungsdatum-Abschnitt, Fixture `vat-return-reversal`.)
Aktuelle Lesart/Implementierung: eine Stornobuchung (`reverse`) wird dem VA-Zeitraum ihres **eigenen Buchungsdatums** zugeordnet (§ 17 Abs. 1 S. 7 UStG: Korrektur im Zeitraum, in dem die Änderung eintritt), nicht der Original-Leistungsperiode.
Zu klären:
- Trägt diese **pauschale** Regel auch dann, wenn das Storno eine **reine Fehlbuchung** rückgängig macht (Geschäft gab es nie / Rechnung war von Anfang an falsch)? Strenge Lesart: dann wäre die *ursprüngliche* (falsche) Voranmeldung der Original-Periode zu **berichtigen**, nicht eine Korrektur in der Folgeperiode.
- Rolle von **§ 14c UStG** (unrichtiger / unberechtigter Steuerausweis) — eigener Korrekturzeitpunkt/-mechanismus?
- Konsequenz fürs Datenmodell: Muss `reverse` den **Anlass** unterscheiden (echte Rückabwicklung vs. Fehlerberichtigung), oder ist eine einheitliche Behandlung vertretbar (ggf. mit dokumentierter Vereinfachung)? Was ist gängige Praxis (DATEV/Standardsoftware)?

**RQ-2 — Euro-Abrundung der Bemessungsgrundlage in der VA.** (Quelle: SPEC-010, `determinismus.md` Z. 40, Fixture `vat-return-cash-basis-rounding`.)
Aktuelle Lesart/Implementierung: Bemessungsgrundlagen je Kennzahl auf **volle Euro abgerundet** (amtliche Konvention), Steuer centgenau.
Zu klären:
- Stimmt die **Richtung** (echtes Abrunden/Abschneiden, *nicht* kaufmännisch) und die Anwendung **auf die Kennzahlen-Summe** für die amtliche USt-VA / ELSTER?
- Die Abrundung ist **nicht summenerhaltend**: über mehrere VA-Zeiträume ergibt Σ der angezeigten Basen weniger als den Jahresnettoumsatz (im Fixture 999 statt 1000). Ist das die akzeptierte Realität, und wie behandelt die **USt-Jahreserklärung** das (eigene, nicht-gefloorte Basis-Ermittlung / Abstimmung)? Relevant, sobald eine Jahreserklärungs-Projektion gebaut wird.

## Beantwortet

- **Buchhalter-/StB-Review-Paket (2026-06-08, v0.4):** Alle Befunde beider Reviews realisiert (Leistungsdatum, Partner schlank [Bruce], Ergebnisverwendung als Buchung [Bruce], degressive AfA/§ 7g-Mechanik mit verifiziertem Rechtsstand, Gutschriften-Regel, kanonische Subtypes, Lohn-/Bewirtungs-/Transit-Muster, Monats-GuV, DATEV beidseitig, Verfahrensdoku, Scope-Ehrlichkeit, Glossar-Kur). Kontoauszugs-Import-Formulierung präzisiert: kein Import im Package, postVoucher/settleVoucher sind die Andockpunkte. Details: Entscheidungslog 2026-06-08.
- **Restfragen-Paket (2026-06-07, Abschluss):** Bewertungsbereiche: v1 einer, `valuationArea` reserviert (Bruce). Journalnummer: je Geschäftsjahr. Korrektur: nur `correct` + Audit, kein Löschen. Fremdwährung: v2, Felder reserviert. Steuerschlüssel: eigene Codes, DATEV-BU als Alias. Aufbewahrungsfristen: App-Sache. Dimensions-Validierung + CLI: umgesetzt/spezifiziert. Details: `00-projekt/entscheidungen.md`.
- **Phase-3-Formatfragen (2026-06-07):** Money String-Dezimal; JSON überall; UUIDv7; taxTag mit appliedVersion; Profile gepinnt; Mapping-Format einheitlich; Hash nur Manifest-Ebene; Fixtures append-only.
- **Phase-2-Festlegungen (2026-06-07):** Tax synchron beim Buchen via App-Schicht-Orchestrierung (Ledger gesetzesfrei); Reporting kein eigener Kontext; Dimensionen frei definierbar; Beleg = `voucher`; Festschreibung je Buchung mit Massenauslöser Periode; Datenformat = Published Language. Siehe `context-map.md` und `ledger-modell.md`.
- **EÜR-als-Projektion validiert (2026-06-07):** Beweis erbracht — 8 Testfälle (inkl. 10-Tage-Regel in 3 Varianten, USt, AfA) im Prototyp grün. Regeln R1–R6 in `euer-projektions-beweis.md`. Daraus: OP-Verknüpfung Pflicht; Metadaten `recurring`/`due`/`economicYear`.
- **Rechtsstände (verifiziert 06/2026):** § 141 AO: 800.000 € / 80.000 € (seit 2024). GWG: 800 € / Sammelposten 250–1.000 €. § 19 UStG: 25.000 € / 100.000 €, echte Befreiung seit 2025, unterjähriger Wechsel. § 20 UStG: 800.000 €. Aufbewahrung: Belege 8 Jahre (ab 2025), Bücher 10. GoBD: 28.11.2019 i. d. F. 11.03.2024 + 14.07.2025. Vorsteuer bei Ist-Versteuerer-Rechnungen ab 2028 zahlungsabhängig.
- **EÜR: eigener Stil oder Projektion?** → Projektion über doppelte Buchung (Bruce).
- **Journal/Event-Sourcing?** → Fachlich append-only Event-Log; Persistenztechnik ist Adapter-Sache.
- **Referenzimplementierung?** → PHP zuerst (Kern framework-frei + Laravel-Adapter), Node danach, Python später; CLI für LLM früh (Bruce).
- **Priorität kommunale Doppik?** → später; Modell hält die Tür offen (Bruce).
- **E-Rechnungs-Parsing?** → out of scope; App-Sache (Bruce).
- **Kontenrahmen?** → DATEV-kompatibler Import, eigene Konten Grundfunktion (Bruce).
