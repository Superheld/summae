# Job-Backlog — PHP-Referenzimplementierung

Reihenfolge ist Abhängigkeitsreihenfolge. Akzeptanz = genannte Fixtures grün (sofern Runner existiert) + PHPStan/Tests grün. Vor jedem Job: zugehöriges Modell-Dokument lesen (Spalte „Lektüre").

| # | Job | Inhalt | Akzeptanz | Lektüre |
|---|---|---|---|---|
| 000 | Repo-Setup | Monorepo `rechnungswesen-php/` nach Briefing-Layout; Composer-Packages `core`, `laravel`, `cli`; CI (PHPStan max, PHPUnit); Testsuite-Sync-Skript von der Wissensbasis | CI grün auf leerem Gerüst; `testsuite/` synchronisiert | AGENT-BRIEFING |
| 001 | Shared Kernel | `Money` (brick/math; half-up; `add/subtract/negate/compare/allocate` mit largest-remainder), `AccountNumber`, `PeriodRef`, `VoucherRef`, `DimensionValue`, UUIDv7-Erzeugung, kanonisches JSON (RFC 8785) | Unit-Tests inkl. Determinismus-Fälle aus `determinismus.md` §2 (2.225→2.23; 100/3 = 33.34/33.33/33.33) | determinismus.md |
| 002 | Fixture-Runner | Liest `testsuite/fixtures/**.json`, baut In-Memory-Mandanten aus `setup`, führt `steps` + `projections` aus, Platzhalter (`$V1`, `$E1`…), Teilmengen-Vergleich, Fehlercode-Vergleich; Doppellauf-Determinismus-Check | Runner läuft, alle Fixtures FAILEN kontrolliert (rot, nicht crash) | testing/testsuite/README.md |
| 003 | Ledger-Kern | Aggregate `Account`, `FiscalYear`/`Period`, `Voucher`, `JournalEntry` (Lines als VOs); Operationen `post` (Prüfreihenfolge!), `correct`, `finalize`/`finalizeUntil`, `reverse`, `closePeriod`/`reopenPeriod`/`closeFiscalYear`, `createAccount`, `importChartOfAccounts`; In-Memory-Port | Fixtures: post-and-invariants, post-malformed, finalize-reverse-period, period-ordering, accounts-and-import | ledger-modell.md, api.md |
| 004 | Offene Posten | `OpenItem`-Erzeugung bei AR/AP-Buchung, `settle` mit Teilausgleich | open-items-settlement | ledger-modell.md |
| 005 | Basis-Projektionen | `trialBalance`, `accountSheet`; deterministische Sortierung (Codepoints!), `asOf` | Projektionsteile der Fixtures aus 003/004 | determinismus.md §3 |
| 006 | Tax | `TaxCode` (Regelversionen), `TaxProfile` (zeitabhängig), `expandTax` (side-effect-free, Rundung pro Beleg), `setTaxProfile`, Komposition `postVoucher`, `createTenant` mit Profil | tax-expansion, small-business-switch, create-tenant-profile | tax-modell.md |
| 007 | EÜR + USt-VA | `cashBasisReport` (Regeln R1–R6: OP-Verkettung, 10-Tage-Regel, USt erfolgswirksam, AfA, neutral), `vatReturn` (Kennzahlen via taxTag; Ist-Versteuerung folgt settlements) | cash-basis-ten-day-rule, vat-return | euer-projektions-beweis.md + 60-prototyp/euer_projektion.py |
| 008 | Mappings | Mapping-Import (Vollständigkeits-/Überlappungsprüfung), `balanceSheet`, `incomeStatement` | balance-sheet-mapping | datenformat.md v0.2 |
| 009 | Assets | `Asset`/`AssetPool`, GWG-Weiche (Regelmodul-Grenzen), `acquireAsset`, `disposeAsset`, `runDepreciation` (idempotent, pro rata), `assetRegister` | gwg-and-depreciation, edge-errors (Asset-Teile) | assets-modell.md |
| 010 | Costing | Dimensionsregeln (Pflichtdimensionen), `setAllocationScheme` (Zyklusprüfung), `runCosting`/`releaseCosting`, `costAllocationSheet` | allocation-run, allocate-largest-remainder, edge-errors (Rest) | costing-modell.md |
| 011 | Export | `journalExport` (GoBD Z3: Manifest, SHA-256-Strom-Hashes, Feldkatalog), Schema-Validierung der Exporte gegen `schema/format.schema.json` | journal-export-z3 + Export validiert gegen Schema | datenformat.md |
| 012 | Laravel-Adapter | ServiceProvider, Eloquent-Persistenz-Adapter (Port-Implementierung), Migrationen, Config; Kern bleibt unberührt | komplette Suite grün gegen Eloquent-Adapter (zweiter Runner-Lauf) | — |
| 013 | CLI | Alle Operationen + Projektionen als CLI (`rw post …`, `rw report …`), Ausgaben JSON (maschinenlesbar, LLM-Operator als Zielnutzer), Exit-Codes = Fehlercodes | Smoke-Tests; SF-02 per CLI in einem Aufruf | api.md (F-IO-003) |
| 014 | Abschlussbericht | FINDINGS-OPEN.md konsolidieren; Performance-Messung gegen NF-7; Liste der Adapter-Annahmen für Node-Portierung | Bericht liegt vor | nicht-funktional.md |

## Meilensteine

- **M1 (nach 005):** buchfähiger GoBD-Kern — Suite-Teil „core" grün
- **M2 (nach 008):** voller Fibu-Funktionsumfang inkl. EÜR/Bilanz — „tax" + „projections" grün
- **M3 (nach 011):** alle Fixtures grün + Doppellauf — **Vertrag erfüllt** (Suite wuchs während der Umsetzung auf 43 Fixtures/34 Codes; PHP-Referenz erfüllt sie, `ABSCHLUSSBERICHT.md`)
- **M4 (nach 013):** Laravel + CLI nutzbar — Paket veröffentlichbar

## Danach (separater Auftrag, nicht Teil dieses Backlogs)

Node-Implementierung gegen dieselbe Suite (dann wird SF-15 scharf geschaltet: Cross-Test Datenbestand PHP ↔ Node); Python danach.
