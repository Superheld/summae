# Offene Fragen (Modell + fachlich)

Geordnet nach Tragweite. Erledigte Fragen wandern mit Antwort + Datum nach unten in „Beantwortet".

## Internationalisierung (Rahmen steht; der größere Teil ist inzwischen gebaut)

> **Durchgesehen am 2026-08-28.** Dieser Abschnitt führte vier Punkte als offen, die längst
> ausgeliefert sind — Pack-Policy-Felder, das fiktive Test-Pack, „US in Scope?" und die
> Umsetzung komponierbarer Packs. Eine Liste, die Erledigtes als offen führt, ist schlimmer als
> keine: sie kostet jeden Leser dieselbe Prüfung, und sie lässt die wenigen wirklich offenen
> Punkte darin untergehen. Erledigtes steht jetzt unter „Beantwortet".

Offen bleibt:

- **Zweites reales Länder-Pack:** AT am billigsten (nahe DE), FR am aussagekräftigsten
  (PCG-Pflichtkontenrahmen + FEC-Export). Welches zuerst? *(Nicht mehr blockiert — der Resolver,
  das Manifest-Format und drei ausgelieferte Packs stehen; es ist reine Priorisierung.)*
- **Multi-Currency-*Buchung*** (Fremdwährung mit Kursdifferenzen): Felder reserviert (v2). Timing?
- **Export-Adapter** SAF-T (OECD) / FEC (FR) — je ein dünner Serializer über derselben Projektion
  wie Z3/DATEV.
- **Nummern-Mapping `summae-base` → SKR/BU** für die `datevExport`-StB-Übergabe
  (nachfragegetrieben; Folgepunkt der SKR-Lizenzentscheidung vom 2026-06-21).

## Noch offen

1. **Kommune-Paket** (wartet auf Priorisierung kommunaler Projekte): Finanzrechnung als parallel
   gebuchte zweite Rechnung oder Projektion; Obligo-Modellierung (Vorentscheidung: Pre-Posting-Hook,
   `context-map.md`); Referenz-Bundesland für die GemHVO-Erstausarbeitung.
2. **Reihenfolge der beiden Konsumenten-Projekte** — (a) privates Haushaltsbuch (Substrat +
   Assets-Expansion, praktisch kein Rechtsinhalt), (b) Nebenkostenabrechnung für Vermieter
   (KLR-Teilmenge: Kostenstellen = Wohneinheiten, Umlageschlüssel = AllocationScheme). Beide sind
   bewusst projektindividuelle Kompositionen, **keine** kuratierten Packs; damit ist der KLR-Scope
   nachfragebelegt. Offen ist nur die Reihenfolge und daran geschärfte NF-7-Ziele.
3. **Die beiden fachlichen Rückfragen RQ-1/RQ-2** — siehe nächster Abschnitt. Sie sind der einzige
   Punkt dieser Liste, an dem heute Code mit einer *unbestätigten* Lesart läuft.

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

- **Pack-Policy-Felder im Format** (`roundingMode`, `taxRoundingGranularity`, `currencyScale`) und
  das gelockerte amount-Pattern: gebaut, seit v0.6 im Schema, belegt durch die `xx-*`-Fixtures
  (F-PACK-POLICY-001…004). *(Nachgetragen 2026-08-28.)*
- **Fiktives Test-Pack:** existiert als `xx-*`-Familie und ist der Ort, an dem Mechanismen bewiesen
  werden, ohne ein ausgeliefertes Pack festzuschweißen. *(Nachgetragen 2026-08-28.)*
- **US in Scope?** → **Ja, entschieden und gebaut** (us-pack, v0.4.0): eigener Kontenrahmen,
  Sales/Use Tax, GAAP-Mappings, Schedule C, MACRS. Das zweite Steuerparadigma ohne Vorsteuerabzug
  ist der Grund, warum `F-PACK-POLICY-003` existiert. *(Nachgetragen 2026-08-28.)*
- **Komponierbare Packs (Umsetzung):** Modul-Registry, Resolver mit Invarianten I1–I10,
  `E_PACK_UNRESOLVED_REF`/`E_PACK_INCOHERENT`, Manifest im Datenformat und die Resolver-Fixtures —
  alles gebaut; drei Packs ausgeliefert. *(Nachgetragen 2026-08-28.)*
- **Costing-Läufe ins Format:** mit der Node-Portierung entschieden und gebaut; Läufe sind nicht
  mehr prozesslokal. *(Nachgetragen 2026-08-28.)*
- **SPEC-FINDINGS aus der Node-Portierung:** zurückgeflossen; der Befundregister ist seit
  2026-08-28 `FINDINGS-OPEN.md` / `FINDINGS-CLOSED.md` im Repo-Root. *(Nachgetragen 2026-08-28.)*

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
