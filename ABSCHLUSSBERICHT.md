# Abschlussbericht — PHP-Referenzimplementierung (JOB-014)

Stand: 2026-06-08. Backlog JOB-000 bis JOB-013 plus eingeschobener
Retrofit JOB-V04 (Spec v0.4) vollständig abgearbeitet.

## Vertragsstatus

| Meilenstein | Kriterium | Status |
|---|---|---|
| M1 | buchfähiger GoBD-Kern, Suite-Teil „core" grün | ✅ |
| M2 | voller Fibu-Umfang inkl. EÜR/Bilanz | ✅ |
| M3 | **alle 40 Fixtures grün + Doppellauf deterministisch** | ✅ (In-Memory UND Eloquent) |
| M4 | Laravel-Adapter + CLI nutzbar | ✅ |

- Konformitätssuite: **40/40 grün, 0 Crashes, Doppellauf byte-identisch** —
  gegen den In-Memory-Kern, gegen Eloquent/SQLite und gegen Eloquent/Postgres 16.
- Qualität: PHPStan level max ohne Fehler, 93 Unit-/Smoke-Tests grün,
  Exporte validieren gegen `format.schema.json` (draft 2020-12).
- Spec-Stand bei Abnahme: Datenformat v0.4, Testsuite 40 Fixtures
  (v0.2 → v0.3 → v0.4 wurden während der Implementierung nachgezogen).

## NF-7 Performance (Messung 2026-06-08, Docker/ARM, In-Memory-Port)

| Anforderung | Richtwert | Gemessen |
|---|---|---|
| NF-7.1 Einzelbuchung inkl. Tax-Expansion (postVoucher) | < 50 ms | **3,5 ms** |
| NF-7.2 SuSa über GJ mit 100.000 Buchungen | Sekunden, nicht Minuten | **0,34 s** |
| NF-7.2 EÜR-Projektion über denselben Bestand | Sekunden | **0,53 s** |
| NF-7.3 Konformitätssuite komplett (inkl. Doppellauf) | Minutenbereich | **0,07 s** (In-Memory) / **0,19 s** (Eloquent/SQLite) |

Massendurchsatz `post`: ~0,013 ms/Buchung (77k Buchungen/s); Peak-Memory
bei 100k Buchungen in-memory: 310 MB (Adapter-Betrieb hält das Journal
nicht im Speicher). Reproduktion: `php runner/bin/benchmark.php 100000`.

## SPEC-FINDINGS (konsolidiert, Details in SPEC-FINDINGS.md)

| # | Befund | Vorschlag |
|---|---|---|
| F-001 | Kein Code für *unbekannte* voucherId | festschreiben oder `E_VOUCHER_UNKNOWN` |
| F-002 | `E_ENTRY_NOT_FINALIZED` in api.md, nicht im Katalog; Fixture storniert `entered` | Fußnote auflösen |
| F-003 | Kein Code für Jahresabschluss mit nicht festgeschriebenen Buchungen | Code definieren |
| F-004 | Konten-Auflösung für Asset-Buchungen unspezifiziert (Gegenkonto/AfA/GWG) | Regelmodul-Schlüssel |
| F-005 | journal-export-z3 ↔ audit-trail ↔ Schema: Manifest-Streams & formatVersion widersprüchlich | Fixture + Schema auf v0.4 ziehen |
| F-006 | `E_COSTING_RUN_UNKNOWN` fehlt im Katalog | aufnehmen + Fixture |
| F-007 | balanceSheet-Seitenzuordnung (Aktiva/Passiva) per Konvention Wurzelreihenfolge | `side`-Attribut am Mapping |

Dazu zwei Beobachtungen ohne eigene Nummer: (a) Fixture-Zählung in
JOBS.md/Wissensbasis-README („17") ist mehrfach überholt; (b) Generalumkehr
kopiert `taxTag.baseMoney` unverändert — die VA-Projektion negiert die
Basis bei negativen Steuerzeilen (dokumentiert in VatReturnProjection),
das sollte die Spec explizit machen.

## Adapter-Annahmen (für die Node-Portierung)

1. **Maschinell erzeugte Buchungen werden sofort festgeschrieben**
   (Asset-Zugang/-Abgang, AfA-Läufe) — sonst scheitert `closeFiscalYear`
   in edge-errors. GoBD-konform, aber Spec-würdig.
2. **Regelmodul-Daten sind App-Schicht-Daten:** Steuerschlüssel, Profile,
   Mappings, Dimensionsregeln, GWG-Grenzen werden dem Mandanten beim
   Aufbau übergeben und nicht in der Adapter-Datenbank verwaltet
   (Versionierung/Pinning ist App-Sache; die CLI hält sie in `rw.json`).
3. **Persistenzgranularität:** Aggregat-Innereien (Buchungszeilen,
   Perioden, Settlements, AfA-Lebenslauf) als JSON-Dokumente am Aggregat —
   die Published Language ist die Persistenzform. Node kann dieselben
   Tabellen lesen (`rw_*`, JSON-Spalten in datenformat.md-Form).
4. **Eindeutigkeit per DB-Constraint:** Kontonummer je Mandant und
   sequenceNumber je (Mandant, GJ) sind Unique-Indizes — der
   Repository-Kontrakt aus dem Modell, vom Adapter zugesichert.
5. **Costing-Läufe** leben derzeit im Service (prozesslokal) — released
   Läufe gehören vor Cross-Implementation-Austausch in einen eigenen
   Strom (`costingRuns.jsonl` ist im Format vorgesehen).
6. **Determinismus-Hooks:** Clock und IdGenerator sind injizierbar;
   der Konformitätsrunner nutzt feste Uhr + Zähler-IDs, damit der
   Doppellauf inkl. SHA-256-Strom-Hashes byte-identisch ist. Node braucht
   dieselben Hooks.
7. **Geld:** brick/math mit HALF_UP (= kaufmännisch, von Null weg);
   Node-Pendant muss away-from-zero-Rundung und Largest-Remainder mit
   Gleichstand→erster exakt spiegeln (Fixtures decken die Fallen ab).

## Was bewusst offen bleibt

- DATEV-EXTF-Headerformat: gegen aktuelle DATEV-Doku zu verifizieren
  (datenformat.md, Phase-4-Hinweis) — der Stapel-/Stammdaten-Export
  liefert die spezifizierten Felder.
- `systemDocumentation` (Verfahrensdoku, v0.4) und `importDatevBatch`:
  als Fähigkeit spezifiziert, ohne Fixture — nicht implementiert.
- Degressive AfA/Methodenwechsel (v0.4): Mechanik beschrieben, ohne
  Fixture — AfA-Plan unterstützt linear (und Pool); `declining` folgt,
  sobald eine Fixture das Verhalten festnagelt.
- Kommune-Paket, Fremdwährung (v2), `previousEntryHash`: per Spec vertagt.
