# Runtime-Leitfaden — eine weitere Sprach-Runtime bauen

> **Ein Dokument, kein Vorwissen nötig.** Dies ist der vollständige Einstieg für einen Coding-Agenten, der eine **weitere Sprach-Runtime** von Summae baut — egal ob Python, Rust, Go oder etwas anderes. Es backt alle Spec-Stände bis **v0.6** ein — du musst die historischen Delta-Notizen (`archiv/SPEC-UPDATE-v0.3…v0.6.md`) **nicht** lesen; sie waren Zwischenstände der PHP-Entwicklung und sind in der konsolidierten Spezifikation aufgegangen.
>
> Es gibt bereits **zwei fertige, konforme Referenzen**: die **PHP/Laravel-Referenz** (`ABSCHLUSSBERICHT.md`) und die **Node/TypeScript-Runtime** — beide Goldstandard, der Cross-Test zwischen ihnen ist bidirektional grün. Deine Implementierung ist fertig, wenn sie denselben Vertrag erfüllt und denselben Datenbestand wie diese beiden lesen/fortschreiben kann (Cross-Kompatibilität).
>
> Stand: 2026-06-20.

## Auftrag in einem Satz

Baue eine neue Sprach-Runtime, die **dieselbe Konformitäts-Testsuite** besteht — gegen einen In-Memory-Port und gegen mindestens einen Persistenz-Adapter — und den Datenbestand der bestehenden Referenzen (PHP, Node) lesen, fortschreiben und identisch auswerten kann (SF-15).

---

## Was gebaut wird

Eine einbettbare **Bibliothek** (keine Anwendung) für GoBD-konforme Buchführung (Doppik), EÜR, Umsatzsteuer, Anlagen und KLR. Laufzeitübergreifend mit **identischer API und identischem Datenformat**: Daten aus einer Implementierung können von jeder anderen gelesen und fortgeschrieben werden. UI, Workflows, Übermittlung baut die einbettende App — das ist bewusst draußen (`30-anforderungen/out-of-scope.md`).

Architektonisch (benannt seit v0.6): **Substrat** (jurisdiktionsfreie Buchungs-Algebra) / **Politiksorten** (Constraint · Projektion · Expansion) / **Pack** (Länderdaten + Regeln). **DE ist das erste Pack, nicht der Scope.** Schichtenzuordnung: `40-domaenenmodell/jurisdiction-profil.md`.

## Der Vertrag (nicht verhandelbar)

1. **`testing/testsuite/`** ist der Kompatibilitätsvertrag: **45 JSON-Fixtures** + Runner-Kontrakt (`testing/testsuite/README.md`). Fertig = alle Fixtures grün **und** kompletter Doppellauf byte-identisch. **Fixtures werden niemals editiert**, um sie passend zu machen — eine falsch erscheinende Fixture ist ein Befund (siehe Eskalation).
2. **`50-spezifikation/`** ist normativ, Stand **v0.5/0.6**:
   - `datenformat.md` (v0.5) — Published Language, JSON/JSONL, String-Dezimal, UUIDv7
   - `api.md` (v0.5) — Operationen, Eingaben, Invarianten, **Prüfreihenfolge der Fehler**
   - `determinismus.md` (v0.5) — Rundung, Sortierung, Zeit (Pflichtlektüre, s. u.)
   - `fehlerkatalog.md` (v0.5) — **34 Codes**, jeder mit Fixture
   - `schema/format.schema.json` — `$id` bleibt 0.4 (v0.5-Felder sind additiv)
3. **`20-glossar/glossar.md`** liefert alle Namen (EN-Spalte = API-/Klassen-/Feldnamen). Keine eigenen Namen erfinden; idiomatische Casing-Anpassung je Sprache ist erlaubt, Semantik und Namen sind bindend.

**Aktueller Konformitätsstand (Messlatte):** 45/45 Fixtures grün · 34/34 Fehlercodes · 26/26 Standardfälle (**SF-15** seit der Node-Runtime + bidirektionalem Cross-Test erfüllt) · 5/5 Determinismus-Pflichtfälle. Quelle: `testing/testsuite/abdeckung.md`.

## Leseliste (in dieser Reihenfolge, ~30–40 Min.)

1. `testing/testsuite/README.md` + zwei Fixtures querlesen (`core/post-and-invariants.json`, `tax/tax-expansion.json`) — das Zielbild
2. `50-spezifikation/api.md` und `datenformat.md`
3. `50-spezifikation/determinismus.md` — hier stehen die häufigsten Cross-Runtime-Fehler
4. `40-domaenenmodell/ledger-modell.md` — Aggregate, Invarianten, Events
5. **`80-implementierung/ABSCHLUSSBERICHT.md`, Abschnitt „Adapter-Annahmen"** — die 7 Punkte unten; nehmen dir die teuren Entscheidungen ab
6. Bei Bedarf je Thema: `tax-modell.md`, `assets-modell.md`, `costing-modell.md`, `euer-projektions-beweis.md` (Regeln R1–R6; der Wegwerf-Prototyp `60-prototyp/archiv/euer_projektion.py` führt sie einmalig vor — die Regeln leben produktiv in beiden Referenzen)
7. Hintergrund nur bei Unklarheit: `10-fachwissen/`, `00-projekt/entscheidungen.md`

## Architektur-Vorgaben (wie PHP)

- **Kern framework-frei** (`@summae/core` o. ä.): keine Framework-Abhängigkeit im Kern. Framework-Adapter (NestJS/Express, Prisma/Knex bzw. SQLAlchemy) sind separate Pakete.
- **Hexagonal:** Persistenz hinter einem Port (`LedgerRepository` etc.); Kern komplett gegen einen **In-Memory-Port** entwickeln und testen, Adapter danach.
- **Schicht-Trennung von Tag 1:** gesetzesfreier Kern getrennt von **`pack-de`** (Steuerschlüssel, SKR, Mappings, AfA-Tabellen, GWG-Grenzen, Rundungs-Policy-Werte als versionierte **Daten**). PHP hat DE-first gebaut und die Naht nachträglich benannt — du kannst sie sauber ziehen. Lackmustest: zitiert Kern-Code einen Paragraphen → falsche Schicht.
- **Journal append-only; Salden sind Projektionen** — nie einen Saldo speichern.
- **Geld dezimal-exakt** (Node: big.js/decimal.js; Python: `decimal.Decimal` + `ROUND_HALF_UP`), **half-up away-from-zero**, nie native Floats. `Money` ist ein Value Object mit `add/subtract/negate/compare/allocate` (largest-remainder).
- **Determinismus-Hooks injizierbar** (Clock, IdGenerator), damit der Doppellauf inkl. SHA-256-Strom-Hashes byte-identisch ist.
- **Fehler:** exakt die Codes aus `fehlerkatalog.md`, exakte **Prüfreihenfolge** aus `api.md`.

## Repo-Layout (Mehr-Implementierungs-Plan)

```
summae/implementations/
├── php/        ← Referenz, fertig (Goldstandard)
├── node/       ← Referenz, fertig (Goldstandard #2)
│   ├── packages/core/      (framework-frei)
│   ├── packages/<adapter>/ (NestJS/Express + Prisma/Knex)
│   ├── packages/cli/
│   ├── testsuite/          (Quelle: Fixtures + schema/ + fehlerkatalog.md, append-only)
│   └── runner/             (Fixture-Runner)
└── <deine-runtime>/  ← gleiche Struktur (python, rust, go, …)
```

Die Testsuite liegt seit 2026-08-23 direkt in `testing/testsuite/` und wird dort auch geschrieben — die Einbahnstraße aus einer externen Wissensbasis ist entfallen, weil die Wissensbasis selbst ins Repo gezogen ist (`knowledge/`). Geblieben ist die Regel, auf die es ankommt: **Fixtures sind append-only** — Verhaltensänderung = neue Fixture, nie eine stille Änderung an einer bestehenden.

## Reihenfolge & Meilensteine (runtime-agnostisch)

Analog zum abgeschlossenen PHP-Backlog (`archiv/JOBS.md`, sinngemäß als Reihenfolge-Vorlage), test-first:

`Shared Kernel (Money/IDs/Kanonisierung) → Fixture-Runner → Ledger-Kern → Offene Posten → Basis-Projektionen → Tax → EÜR/USt-VA → Mappings → Assets → Costing → Export → Framework-Adapter → CLI → Cross-Test gegen PHP`

- **M1** — buchfähiger Kern (`core`-Fixtures grün)
- **M2** — voller Fibu-Umfang inkl. EÜR/Bilanz (`tax` + `projections` grün)
- **M3** — **alle 45 Fixtures grün + Doppellauf deterministisch** (In-Memory) → Vertrag erfüllt
- **M4** — Persistenz-Adapter + gleichsprachige CLI nutzbar (zweiter Runner-Lauf gegen Adapter)
- **M-Cross** — **SF-15 grün** gegen die bestehenden Referenzen (PHP ↔ Node ist bereits grün) → Cross-Kompatibilität scharf

## Die 7 Adapter-Annahmen (aus der PHP-Referenz — vorab klären)

Diese Punkte hat PHP konkret entschieden; spiegle sie, sonst bricht später SF-15:

1. **Maschinell erzeugte Buchungen sind sofort `finalized`** (Asset-Zugang/-Abgang, AfA-Läufe) — sonst scheitert `closeFiscalYear`. Seit v0.6 Spec-Regel.
2. **Regelmodul-Daten sind App-/Pack-Schicht-Daten** (Steuerschlüssel, Profile, Mappings, Dimensionsregeln, GWG-Grenzen): werden dem Mandanten beim Aufbau übergeben, nicht in der Adapter-DB verwaltet. Die CLI hält sie in `summae.json`.
3. **Persistenzgranularität:** Aggregat-Innereien (Buchungszeilen, Perioden, Settlements, AfA-Lebenslauf) als JSON-Dokumente am Aggregat — **die Published Language ist die Persistenzform**. Andere Runtimes lesen dieselben Tabellen (JSON-Spalten in `datenformat.md`-Form).
4. **Eindeutigkeit per DB-Constraint:** Kontonummer je Mandant, `sequenceNumber` je (Mandant, GJ) sind Unique-Indizes.
5. **Costing-Läufe:** released Läufe gehören vor Cross-Implementation-Austausch in einen eigenen Strom (`costingRuns.jsonl`, im Format vorgesehen).
6. **Determinismus-Hooks:** Clock + IdGenerator injizierbar; der Runner nutzt feste Uhr + Zähler-IDs für byte-identische Doppelläufe inkl. Hashes.
7. **Geld:** HALF_UP = kaufmännisch, von Null weg; Largest-Remainder mit Gleichstand → erster. Die Fixtures decken die Fallen ab.

## Determinismus-Fallen (häufigste Abweichungs-Quelle)

- **Rundung:** half-up away-from-zero, **USt pro Beleg je Steuersatz**. Beispiele in `determinismus.md` §2 (2.225 → 2.23; 100/3 → 33.34/33.33/33.33; AfA-Monatsraten 1–28 je 27,78 / 29–36 je 27,77, Σ exakt 1.000,00).
- **Sortierung:** nach **Codepoints** (führende Nullen!), nicht locale-abhängig — beeinflusst Strom-Hashes und `allocate`.
- **Zahlentypen:** nie native Floats; String-Dezimal im Format, exakte Dezimal-Lib im Code.
- **Pack vs. Mechanik:** Der Determinismus *selbst* ist global garantiert; die konkreten Politikwerte (Rundungsmodus, Steuer-Granularität, Währungsskala) sind **DE-Pack-Werte** — heute fest verdrahtet (half-up / pro Beleg / EUR-2), eigene Format-Felder kommen erst mit dem zweiten Pack.

## Cross-Kompatibilität (SF-15 — der eigentliche Mehrwert)

SF-15 ist der Standardfall, der zwei Runtimes braucht. Akzeptanz: **Ein von einer bestehenden Referenz erzeugter Datenbestand (Published-Language-Form) wird von dir gelesen, fortgeschrieben und ausgewertet — identische Projektionsergebnisse; und umgekehrt.** Protokoll in `testing/testsuite/README.md`. PHP ↔ Node ist bereits bidirektional grün (Strang 2 der Produkt-Roadmap erfüllt); deine Runtime tritt gegen beide an.

## Vorläufige & bewusst offene Bereiche (nicht stolpern)

- **RQ-1 — Storno-VA-Periodenzuordnung (vorläufig!):** Reversierende Buchungen (`reverses !== null`) werden in der USt-VA bei Soll-Versteuerung nach **eigenem Buchungsdatum** zugeordnet, nicht nach geerbtem Leistungsdatum (§ 17 UStG; PHP-Fix aus SPEC-011). Die Spec-Semantik ist **provisorisch bis fachliche Klärung** — übernimm das PHP-Verhalten, aber erwarte mögliche Präzisierung. Details: `40-domaenenmodell/offene-fragen.md` RQ-1.
- **RQ-2 — Euro-Abrundung nicht summenerhaltend:** Bemessungsgrundlagen je Kennzahl auf volle Euro abgerundet (amtliche Konvention), Steuer centgenau → Σ der angezeigten Basen kann abweichen (z. B. 336+336+327 = 999). Korrekt, aber bewusst beobachtet (SPEC-010). `offene-fragen.md` RQ-2.
- **Ohne Fixture, daher nicht zwingend (PHP hat sie offen gelassen):** degressive AfA/Methodenwechsel (`declining` — Mechanik beschrieben, wartet auf Fixture), `systemDocumentation` (Verfahrensdoku), `importDatevBatch` (DATEV-Rückweg). DATEV-EXTF-Headerformat gegen aktuelle DATEV-Doku verifizieren.
- **Per Spec vertagt:** Kommune-Paket, Fremdwährung (v2), `previousEntryHash`.

## Eskalation (wichtig)

Spec-Lücke/Widerspruch beim Bauen → **nicht raten, nicht Fixture biegen.** In `80-implementierung/SPEC-FINDINGS.md` im Format `F-0xx` anhängen (Was / Wo / Gewähltes Verhalten / Vorschlag) und mit dem nächstplausiblen Verhalten weiterbauen. Die Wissensbasis arbeitet Findings als nächste Spec-Version ein und löst eine Retrofit-Welle für **alle** Runtimes aus (so geschehen mit SPEC-001…SPEC-009 aus PHP). **Node-typische Findings sind v. a. bei Rundung/Zahlentypen erwartbar** — genau dort härtest du den Determinismus-Anhang.

> Historie nur zur Einordnung: SPEC-001…SPEC-009 sind in der Spec aufgelöst (Zusammenfassung im `ABSCHLUSSBERICHT.md`), SPEC-010/SPEC-011 aus der Fixture-Verifikation 2026-06-14 ebenfalls, und SPEC-C01 (kanonisches Zeitstempel-Format, RFC 3339/UTC/`Z`) aus dem Node-Cross-Test 2026-06-20. Du baust gegen die **konsolidierte** Spec — die Findings-Datei ist dein Eskalationskanal nach vorn, kein Pflicht-Lesestoff rückwärts.

## Weitere Runtimes (Python, Rust, Go, …)

Gleiches Dokument, gleiche Suite, gleicher Vertrag — nur die Sprach-Mittel ändern sich (Python: `decimal.Decimal` + `ROUND_HALF_UP`; Rust: `rust_decimal`; jeweils eine exakte Dezimal-Lib, nie native Floats). Das SF-15-Muster ist durch PHP ↔ Node etabliert und der Determinismus-Anhang durch die Node-Findings (inkl. SPEC-C01) abgehärtet — du trittst gegen zwei eingespielte Referenzen an. Reihenfolge-Hinweise: `00-projekt/developer-roadmap-stage2.md`.
