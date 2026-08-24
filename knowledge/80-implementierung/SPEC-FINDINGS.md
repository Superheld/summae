# SPEC-FINDINGS (historisch — Wissensbasis-Seite, erste Runde)

> ## ⚠ Diese Nummern sind **nicht** die aus `implementations/<sprache>/SPEC-FINDINGS.md`
>
> Es gibt zwei Register mit denselben Präfixen und **verschiedenen Inhalten**. Hier ist SPEC-010
> „Fixture `vat-return-cash-basis-rounding` widerspricht der Euro-Floor-Regel"; dort ist SPEC-010
> „`EXEMPT` kann nicht gebucht werden". Wer eine Nummer nachschlägt, muss also wissen, in welchem
> Register er steht — genau der Zustand, den die ID-Konvention im Wurzel-`CLAUDE.md`
> („Namensräume trennen sich am Präfix") verhindern soll. Aufgefallen 2026-08-24.
>
> **Dieses Register ist geschlossen und historisch.** Es dokumentiert die Findings der ersten Runde
> (Spec-Stand v0.2 bis v0.5), als die Wissensbasis noch außerhalb dieses Repos lag und Befunde
> dorthin zurückflossen. **Nichts wird hier mehr angehängt.** Der aktive Ort ist
> `implementations/php/SPEC-FINDINGS.md` bzw. `implementations/node/SPEC-FINDINGS.md`; die
> sprachübergreifenden Einträge stehen in beiden.
>
> Umbenannt wurde bewusst nichts: die Nummern sind in Commits, Entscheidungslogs und Spec-Updates
> zitiert, und eine nachträgliche Umnummerierung würde diese Verweise still falsch machen — dieselbe
> Begründung, aus der das `CHANGELOG` seine alten Präfixe behält.


Befunde aus der Implementierung: Stellen, an denen Spec (v0.2), Fixtures und Modell
sich widersprechen oder etwas fehlt. Regel aus dem Briefing: **nicht raten, nicht
die Fixture ändern** — hier dokumentieren, mit dem nächstplausiblen Verhalten
weiterbauen. Fließt zurück ins Entscheidungslog der Wissensbasis.

> **Befund-IDs am 2026-08-23 neu präfixiert — die Nummern sind unverändert.**
> Die alten Präfixe saßen in den Anforderungs-Namensräumen: `F-0xx` war nur ein
> Kategoriewort von den funktionalen Anforderungen (`F-CORE-…`, `F-IO-…`) entfernt,
> und `NF-0xx` trennte von den nicht-funktionalen Anforderungen (`NF-1` … `NF-7`)
> allein die führende Null.
>
> | alt | neu | Serie |
> |---|---|---|
> | `F-001` … `F-011` | `SPEC-001` … `SPEC-011` | Widersprüche Spec/Fixture/Modell |
> | `F-CROSS-001` | `SPEC-C01` | implementierungsübergreifend |
> | `NF-001` … `NF-025` | `IMPL-001` … `IMPL-025` | Implementierung & Sprachparität |
>
> Das `CHANGELOG.md` von summae behält bewusst die alten IDs: Release-Notes
> beschreiben, was veröffentlicht wurde.

> **Status 2026-06-08: SPEC-001 bis SPEC-007 alle in Spec v0.5 aufgelöst** (siehe
> `00-projekt/entscheidungen.md` und `80-implementierung/SPEC-UPDATE-v0.5.md`).
> Kurz: SPEC-001 → eigener Code `E_VOUCHER_UNKNOWN`; SPEC-002 → `E_ENTRY_NOT_FINALIZED`
> gestrichen, `reverse` statusunabhängig; SPEC-003 → `E_FISCALYEAR_UNFINALIZED_ENTRIES`;
> SPEC-004 → Regelmodul-Schlüssel `assetAccounts`; SPEC-005 → Manifest `streams`/`hashAlgorithm`,
> `auditLog` immer, formatVersion aktuell; SPEC-006 → `E_COSTING_RUN_UNKNOWN`;
> SPEC-007 → `side` am Bilanz-Wurzelknoten. Testsuite nach v0.5 neu syncen.
> Weitere Findings unten anhängen.

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

## SPEC-001: Kein Fehlercode für unbekannte voucherId

- **Job:** JOB-003
- **Was:** `E_ENTRY_NO_VOUCHER` ist definiert als „voucherId fehlt". Für eine
  *gesetzte, aber unbekannte* voucherId existiert kein Code; keine Fixture
  deckt den Fall ab.
- **Wo:** fehlerkatalog.md (E_ENTRY), api.md (post)
- **Gewähltes Verhalten:** Unbekannte voucherId wird ebenfalls als
  `E_ENTRY_NO_VOUCHER` gemeldet (Referenz-Prüfschritt 2, vor Konten).
- **Vorschlag:** Entweder explizit so festschreiben oder eigenen Code
  `E_VOUCHER_UNKNOWN` einführen + Fixture.

## SPEC-002: E_ENTRY_NOT_FINALIZED in api.md, aber nicht im Fehlerkatalog

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

## SPEC-004: Konten-Auflösung für Asset-Buchungen nicht spezifiziert

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

## SPEC-005: journal-export-z3 vs. audit-trail — Manifest-Streams widersprechen sich

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

## SPEC-006: E_COSTING_RUN_UNKNOWN fehlt im Katalog

- **Job:** JOB-010
- **Was:** releaseCosting/costAllocationSheet mit unbekannter runId hat
  keinen definierten Fehlercode.
- **Gewähltes Verhalten:** eigener Code `E_COSTING_RUN_UNKNOWN` (analog
  E_OPENITEM_UNKNOWN).
- **Vorschlag:** in den Fehlerkatalog aufnehmen + Fixture.

## SPEC-007: balanceSheet-Seitenzuordnung per Wurzelreihenfolge

- **Job:** JOB-008
- **Was:** Die Spec definiert nicht, welche Mapping-Wurzel Aktiva und
  welche Passiva ist; die Fixtures nutzen durchgehend [Aktiva, Passiva].
- **Gewähltes Verhalten:** erste Wurzelposition = Aktiva (Soll−Haben),
  alle weiteren = Passiva (Haben−Soll).
- **Vorschlag:** `side: assets|liabilitiesAndEquity` am Mapping-Wurzelknoten.

## SPEC-003: Kein Fehlercode für „Jahresabschluss mit nicht festgeschriebenen Buchungen"

- **Job:** JOB-003
- **Was:** api.md verlangt für `closeFiscalYear` „alle Buchungen
  festgeschrieben", definiert aber keinen Code für den Verstoß; keine Fixture.
- **Wo:** api.md (Zeitraum-Semantik, closeFiscalYear)
- **Gewähltes Verhalten:** `E_PERIOD_OUT_OF_ORDER` (derselbe Code wie bei
  offenen Perioden — „Abschlussvoraussetzung verletzt").
- **Vorschlag:** Eigenen Code `E_FISCALYEAR_UNFINALIZED_ENTRIES` erwägen
  oder die Wiederverwendung dokumentieren.

## SPEC-008: Generalumkehr kopiert `taxTag.baseMoney` (VA-Projektion negiert)

- **Job:** JOB-007 / Abschlussbericht-Beobachtung (b)
- **Was:** Storno trägt negierte Beträge, aber `taxTag.baseMoney` wird
  unverändert kopiert; die VA-Projektion negiert die Basis anhand des
  Vorzeichens der Steuerposition. War in der Spec nicht explizit.
- **Gewähltes Verhalten:** so umgesetzt (VatReturnProjection); korrekt.
- **Status (v0.5+, 2026-06-08):** in Spec aufgenommen — `datenformat.md`
  (taxTag-Abschnitt) und `api.md` (vatReturn). § 17-Korrekturen/Gutschriften
  tragen negatives `baseMoney`.

## SPEC-009: Maschinell erzeugte Buchungen werden sofort festgeschrieben

- **Job:** JOB-009 / Adapter-Annahme #1
- **Was:** Asset-Zugang/-Abgang und AfA-Läufe müssen direkt `finalized`
  sein, sonst scheitert `closeFiscalYear`. War nur Implementierungswissen.
- **Gewähltes Verhalten:** generierende Operationen erzeugen `finalized`.
- **Status (2026-06-08):** als Regel in `api.md` aufgenommen.

---

> **Status 2026-06-08:** SPEC-001…SPEC-009 alle in der Spezifikation aufgelöst.
> Die PHP-Referenz ist abgeschlossen (`ABSCHLUSSBERICHT.md`).

## SPEC-010: Fixture `vat-return-cash-basis-rounding` widerspricht der Euro-Floor-Regel

- **Job:** Verifikation der 2026-06-09-Fixtures gegen die PHP-Referenz (2026-06-14)
- **Was:** Die Fixture erwartete centgenaue Bemessungsgrundlagen je Quartal
  (336.13 / 336.13 / 327.74). Das widerspricht `determinismus.md` Z. 40 —
  die die Fixture in ihrer eigenen Beschreibung zitiert: „Bemessungsgrundlagen
  je Kennzahl auf volle Euro **abgerundet** (amtliche Konvention), Steuerbeträge
  centgenau." Die PHP-Referenz floort korrekt → 336.00 / 336.00 / 327.00; nur
  die **Steuer** stimmte (63.87 / 63.87 / 62.26, Σ = 190.00 exakt). Die
  Handrechnung der Fixture hatte die Euro-Abrundung übersehen.
- **Wo:** `testing/testsuite/fixtures/tax/vat-return-cash-basis-rounding.json`;
  Implementierung `VatReturnProjection::compute` (Floor auf Kennzahlen-Summe).
- **Gewähltes Verhalten:** **Fixture korrigiert** (2026-06-14) — Basis-Erwartungen
  gefloort, Kommentare ehrlich gemacht. PHP unverändert (war spec-konform).
- **Nebenwirkung dokumentiert:** Σ der angezeigten Basen = 336+336+327 = 999 ≠ 1000.
  Die Euro-Abrundung je Zeitraum ist **nicht summenerhaltend**; nur die centgenaue
  Steuer summiert exakt. Das war bisher in keiner Fixture sichtbar (alle früheren
  hatten glatte Euro-Basen). → fachliche Rückfrage RQ-2 (siehe offene-fragen.md).
- **Status:** aufgelöst (Fixture-Fehler, keine Spec-/Code-Änderung).

## SPEC-011: Generalumkehr/§17-Storno — VA-Periodenzuordnung bei Soll-Versteuerung

- **Job:** Verifikation der 2026-06-09-Fixtures gegen die PHP-Referenz (2026-06-14)
- **Was:** `vat-return-reversal` deckte einen **echten Bug** auf. Der
  Soll-Versteuerungs-Zweig der VA-Projektion ordnete *jede* Buchung nach dem
  **Leistungsdatum des Belegs** zu (§ 27 UStG, v0.4). `reverse()` kopiert die
  `voucherId` des Originals (`Ledger.php:428`) — die Stornobuchung erbt damit
  dessen Leistungsdatum. Folge: Original (Q2) und Storno (gebucht Q3) landeten
  **beide in Q2** → saldierten sich auf 0/0; Q3 zeigte nichts. Das Storno wurde
  in der VA unsichtbar.
- **Wo:** `VatReturnProjection::compute` (else-/accrual-Zweig); Spec
  `datenformat.md` („VA-Zuordnung bei Soll-Versteuerung: Periode des
  Leistungsdatums") — schwieg über Generalumkehr.
- **Gewähltes Verhalten:** **PHP gefixt** (2026-06-14) — reversierende Buchungen
  (`reverses !== null`) werden nach **eigenem Buchungsdatum** zugeordnet, nicht
  nach dem geerbten Leistungsdatum. Fachliche Grundlage: § 17 Abs. 1 S. 7 UStG
  (Korrektur im VA-Zeitraum, in dem die Änderung eintritt). Fixture ist damit
  fachlich korrekt; Suite 45/45 grün.
- **Spec:** `datenformat.md` provisorisch ergänzt (Ausnahme Generalumkehr),
  markiert als **vorläufig bis fachliche Klärung** RQ-1.
- **Offene fachliche Frage (RQ-1, für Rechercheagent):** Die pauschale Regel
  „Storno immer in die Korrekturperiode" trifft die **Rückgängigmachung** (§ 17),
  aber möglicherweise **nicht** die Berichtigung einer reinen **Fehlbuchung**
  (die ursprüngliche fehlerhafte Voranmeldung wäre für die Original-Periode zu
  berichtigen). Außerdem § 14c UStG (unrichtiger/unberechtigter Steuerausweis).
  Ob ein einziges `reverse` beide Fälle abbilden darf oder das Datenmodell den
  Anlass signalisieren muss → siehe offene-fragen.md RQ-1.
- **Status:** Code + Fixture aufgelöst; **Spec-Semantik vorläufig**, Klärung offen.

---

> **Status 2026-06-14:** SPEC-010 (Fixture-Fehler, korrigiert) und SPEC-011 (PHP-Bug,
> gefixt; Spec-Semantik vorläufig) aus der Verifikation der beiden 2026-06-09-
> Steuer-Fixtures. Suite jetzt **45 Fixtures, 45/45 grün** gegen den In-Memory-Kern,
> PHPStan max, 93 Unit-Tests. Zwei fachliche Rückfragen (RQ-1, RQ-2) für den
> Rechercheagenten in `40-domaenenmodell/offene-fragen.md`. Weitere Findings
> (z. B. aus der Node-Portierung) hier anhängen.

## SPEC-C01: Zeitstempel-Serialisierung nicht kanonisch über Implementierungen — ✅ GELÖST

- **Job:** Node-M4 (SF-15 Cross-Test, beide Richtungen)
- **Was:** PHP und Node serialisierten die Zeitstempel `recordedAt` (Buchung) und
  `at` (Audit) **unterschiedlich**: PHP als ATOM mit erhaltenem Offset und ohne
  Millisekunden (`2026-06-07T12:00:00+02:00`), Node via `toISOString` als UTC mit
  Millisekunden (`2026-06-07T10:00:00.000Z`). **Gleicher Moment, andere
  Schreibweise.** Auffällig erst im bidirektionalen Cross-Test: in PHP→Node reicht
  Node PHPs String wörtlich durch (passt), in Node→PHP reformatiert PHP beim Lesen
  über `DateTimeImmutable` → die Inline-Felder *und* die abgeleiteten
  `manifest.contentHashes` (sha256 über die Roh-Stream-Bytes) divergierten. Die
  Konformitätssuite toleriert es (normalisierter Vergleich); strikte Cross-Impl-
  Byte-Gleichheit nicht.
- **Wo:** `determinismus.md` (Zeitstempel-Format war nicht festgelegt); PHP
  `JournalEntry`/`AuditRecord`, Node `recordedAt`/`at`.
- **Gewähltes Verhalten / Auflösung (2026-06-20):** Kanonisches Format eingeführt —
  UTC, RFC 3339 mit fester Millisekunden-Stelle und `Z` (byte-identisch zu JS
  `toISOString`). PHP: neuer Helper `Summae\Core\Shared\Timestamp::canonical()`,
  genutzt für `recordedAt` (JournalEntry + DB-Spalte `recorded_at`), `at`
  (AuditRecord) und `exportedAt` (journalExport). Node erzeugte das Format bereits.
  Der bidirektionale Cross-Test vergleicht seither den **vollständigen**
  journalExport **byte-genau** (inkl. contentHashes + exportedAt), ohne Ausnahme.
- **Spec (erledigt 2026-06-20):** In `determinismus.md` §4 normativ festgeschrieben
  (RFC 3339, UTC `Z`, feste Millisekunden, byte-identisch zu JS `toISOString`) — jetzt
  Vertrag für jede künftige Runtime, nicht nur gelebte Konvention. Kein Fixture pinnte
  die Zeitstempel, daher keine Konformitätsänderung.
- **Status:** vollständig aufgelöst — Code (PHP + Node, 44/44 byte-genau in beide
  Richtungen) **und** Spec (`determinismus.md` §4).

> **Status 2026-06-20:** SPEC-C01 aus der Node-M4-Cross-Test-Arbeit. Erstes
> Finding der **Cross-Achse** (Format-Parität zweier Runtimes), nicht der Rechen-
> Achse. Damit ist diese Datei die Obermenge — die lokale Kopie im Code-Repo
> (`summae/implementations/php/SPEC-FINDINGS.md`) bleibt der dortige Eskalationspunkt,
> muss aber keine exklusiven Findings mehr halten.
