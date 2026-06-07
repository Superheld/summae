# SPEC-FINDINGS

Befunde aus der Implementierung: Stellen, an denen Spec (v0.2), Fixtures und Modell
sich widersprechen oder etwas fehlt. Regel aus dem Briefing: **nicht raten, nicht
die Fixture ändern** — hier dokumentieren, mit dem nächstplausiblen Verhalten
weiterbauen. Fließt zurück ins Entscheidungslog der Wissensbasis.

Format je Befund:

```
## F-XXX: Kurztitel
- **Job:** JOB-NNN
- **Was:** Beschreibung des Widerspruchs / der Lücke
- **Wo:** Datei(en) + Abschnitt in Spec/Fixture/Modell
- **Gewähltes Verhalten:** Was die Implementierung jetzt tut
- **Vorschlag:** Empfehlung für Spec v0.3
```

---

## F-001: Kein Fehlercode für unbekannte voucherId

- **Job:** JOB-003
- **Was:** `E_ENTRY_NO_VOUCHER` ist definiert als „voucherId fehlt". Für eine
  *gesetzte, aber unbekannte* voucherId existiert kein Code; keine Fixture
  deckt den Fall ab.
- **Wo:** fehlerkatalog.md (E_ENTRY), api.md (post)
- **Gewähltes Verhalten:** Unbekannte voucherId wird ebenfalls als
  `E_ENTRY_NO_VOUCHER` gemeldet (Referenz-Prüfschritt 2, vor Konten).
- **Vorschlag:** Entweder explizit so festschreiben oder eigenen Code
  `E_VOUCHER_UNKNOWN` einführen + Fixture.

## F-002: E_ENTRY_NOT_FINALIZED in api.md, aber nicht im Fehlerkatalog

- **Job:** JOB-003
- **Was:** api.md listet `E_ENTRY_NOT_FINALIZED`* bei `reverse` (mit Fußnote
  „Entscheidung offene Frage 5"); der Fehlerkatalog (29 Codes, alle mit
  Fixture) kennt ihn nicht. Fixture finalize-reverse-period storniert eine
  *nicht* festgeschriebene Stornobuchung erfolgreich.
- **Wo:** api.md (Ledger-Tabelle) vs. fehlerkatalog.md vs. finalize-reverse-period.json (Step 9)
- **Gewähltes Verhalten:** `reverse` ist unabhängig vom Status zulässig
  (folgt Fixture + Katalog).
- **Vorschlag:** Fußnote in api.md auflösen — Zeile aus der Fehlerspalte
  streichen oder Verhalten für `entered` explizit definieren.

## F-004: Konten-Auflösung für Asset-Buchungen nicht spezifiziert

- **Job:** JOB-009
- **Was:** acquireAsset/runDepreciation erzeugen Buchungen, aber weder Spec
  noch Regelmodul-Daten benennen Gegenkonto (Geldkonto), AfA-Aufwandskonto
  oder GWG-Sofortabschreibungskonto. Die Fixtures erwarten 1200/4830/4855.
- **Wo:** assets-modell.md, api.md (Assets), gwg-and-depreciation.json
- **Gewähltes Verhalten:** Regelmodul-Schlüssel `acquisitionCounterAccount`/
  `depreciationExpenseAccount`/`gwgExpenseAccount`; Fallback-Konvention:
  einziges bank-Konto, Aufwandskonto per Namensteil ("AfA"/"GWG").
- **Vorschlag:** Schlüssel in die Regelmodul-Spec aufnehmen; Fixtures
  ergänzen.

## F-005: journal-export-z3 vs. audit-trail — Manifest-Streams widersprechen sich

- **Job:** JOB-011
- **Was:** journal-export-z3 erwartet exakt [journal, accounts, vouchers]
  (obwohl post/finalize Audit-Einträge erzeugen), audit-trail (v0.3) exakt
  [..., auditLog]. Außerdem erwartet journal-export-z3 formatVersion "0.2"
  (Spec ist v0.4), und das Schema-Manifest kennt `streams`/`hashAlgorithm`
  nicht, die die Fixture verlangt.
- **Wo:** journal-export-z3.json, audit-trail.json, schema/format.schema.json
- **Gewähltes Verhalten:** auditLog-Strom nur bei echter Änderungshistorie
  (Aktionen jenseits created/finalized); formatVersion fest "0.2";
  Manifest-Validierung auf Schema-bekannte Felder begrenzt.
- **Vorschlag:** journal-export-z3 als v0.4-Fixture neu schneiden
  (auditLog immer, formatVersion aktuell), Schema-manifest um
  streams/hashAlgorithm ergänzen.

## F-006: E_COSTING_RUN_UNKNOWN fehlt im Katalog

- **Job:** JOB-010
- **Was:** releaseCosting/costAllocationSheet mit unbekannter runId hat
  keinen definierten Fehlercode.
- **Gewähltes Verhalten:** eigener Code `E_COSTING_RUN_UNKNOWN` (analog
  E_OPENITEM_UNKNOWN).
- **Vorschlag:** in den Fehlerkatalog aufnehmen + Fixture.

## F-007: balanceSheet-Seitenzuordnung per Wurzelreihenfolge

- **Job:** JOB-008
- **Was:** Die Spec definiert nicht, welche Mapping-Wurzel Aktiva und
  welche Passiva ist; die Fixtures nutzen durchgehend [Aktiva, Passiva].
- **Gewähltes Verhalten:** erste Wurzelposition = Aktiva (Soll−Haben),
  alle weiteren = Passiva (Haben−Soll).
- **Vorschlag:** `side: assets|liabilitiesAndEquity` am Mapping-Wurzelknoten.

## F-003: Kein Fehlercode für „Jahresabschluss mit nicht festgeschriebenen Buchungen"

- **Job:** JOB-003
- **Was:** api.md verlangt für `closeFiscalYear` „alle Buchungen
  festgeschrieben", definiert aber keinen Code für den Verstoß; keine Fixture.
- **Wo:** api.md (Zeitraum-Semantik, closeFiscalYear)
- **Gewähltes Verhalten:** `E_PERIOD_OUT_OF_ORDER` (derselbe Code wie bei
  offenen Perioden — „Abschlussvoraussetzung verletzt").
- **Vorschlag:** Eigenen Code `E_FISCALYEAR_UNFINALIZED_ENTRIES` erwägen
  oder die Wiederverwendung dokumentieren.
