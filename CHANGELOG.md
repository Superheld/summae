# Changelog

Bemerkenswerte Änderungen je Release. Lose an *Keep a Changelog* angelehnt,
Versionierung nach SemVer (0.x: Minor darf brechen).

## 0.3.1 — 2026-06-23

Intern + Doku — **keine API-/Verhaltensänderung** (Byte-Parität unverändert bewiesen).

### Intern / Wartbarkeit
- **`core/src` nach der Architektur strukturiert**: `substrate/` (Substrat) · `ledger/`
  (Orchestrator) · `records/` · `policies/{expansion,projection,constraint}/` ·
  `composition/` · `partner/` · ports/adapters. Die Substrat-Grenze („importiert nichts
  von oben") ist **mechanisch erzwungen** (Node eslint, PHP Arch-Test).
- **Testabdeckung** als Kennzahl + Floor (Kern-Zeilen ≥ 88 %), **fest im Testlauf** beider
  Sprachen. PHP fährt die volle Konformitäts-Suite jetzt auch unter PHPUnit
  (`ConformanceTest`), sodass sie in die Coverage zählt (pcov im Image).

### Doku
- Nutzer-**Handbuch** und **Entwickler-Docs** beider Sprachen **auf Englisch** und auf den
  aktuellen Stand gebracht: Architektur-Modell **Substrat → Politiksorten (Sockel/Stecker) →
  Pack**, Dependency Inversion (der Kern importiert nie ein Pack), die umgesetzte
  Verzeichnisstruktur. Hartkodierte Fixture-Stände entfernt.

## 0.3.0 — 2026-06-22

### Packs (sprachübergreifend, byte-parität PHP↔Node)
- **Neu: Pack-Komposition.** Ein `PackResolver` (reine Funktion) löst ein Manifest +
  seine Module zu *einem* `ruleModules`-Bündel auf, das die Engine isst. Mandant per
  Pack-Wahl, **einmalig beim Anlegen, gepinnt, kein Override** — `createTenant(pack: "…")`.
- **Neu: ausgelieferte Pack-Bibliothek** (`pack-library/`) mit inhaltsbasiertem Loader.
  Packs sind **self-contained** — jedes hält seine eigenen Module (`pack-library/<pack>/`),
  kein geteiltes `modules/`, kein Aufeinander-Aufbauen.
- **Neu: `default-pack`** (neutraler, kontenarmer Rahmen) und **`de-pack`** (Deutschland):
  eigener Kontenrahmen, USt 19/7 · §13b Reverse-Charge · innergem. Lieferung · unentgeltliche
  Wertabgabe · Skonto, Bilanz (§266) / GuV (§275), AfA/GWG, Rechnungsabgrenzung, Policy.
  Vollständig konformitäts-getestet inkl. End-to-End-Jahresgang und USt-Voranmeldung.
- **`packPolicy`** parametrisiert die Engine jurisdiktionsfrei: `currencyScale` → `Currency`,
  `taxRoundingGranularity` → `TaxService`.
- **Neu: `createVoucher`-Operation** — Beleg anlegen ohne zu buchen (Andockpunkt u. a. für AfA).

### CLI
- `summae init --pack <id>` wählt ein Pack aus der Bibliothek (`--pack-library`,
  `--first-fiscal-year`) — die Pack-Wahl vom Frontend aus.

### Doku
- Sprachneutrales Modell **Kern/Substrat → Politiksorten → Pack** mit eindeutiger
  `kind`→Politiksorte-Zuordnung und „Pack von Hand schreiben"-Anleitung; Bau-Konventionen
  und Quality-Gate in den CLAUDE-Dateien; Node-`docs/` nachgezogen.

### CI
- Split-Workflow-Token-Fix (Subtree-Split läuft turnkey über den Workflow).

## 0.2.0 — 2026-06-20

### Node (M4)
- **Neu: `@superheld/summae-knex`** — Datenbank-Adapter (Knex als Schema-/Query-Builder
  + better-sqlite3 / pg). Trifft das geteilte `summae_*`-Schema der PHP-Referenz, sodass
  PHP- und Node-Packages **denselben Datenbestand** teilen können.
- **Neu: `@superheld/summae-cli`** — Terminal-Werkzeug (`summae init|op|report`),
  JSON-Ein/Ausgabe, persistenter SQLite-Arbeitsbereich.
- `@superheld/summae-core`: `Tenant.fromPorts` (Mandant aus beliebigen Ports) +
  `restore`-Methoden für FiscalYear/OpenItem/Asset.

### Sprachübergreifend
- **SF-15 Cross-Test (bidirektional)**: PHP↔Node auf geteilter SQLite; `journalExport`
  **byte-identisch in beide Richtungen** (`make cross`, in CI erzwungen).
- **F-CROSS-001 gelöst**: kanonisches Zeitstempel-Format (UTC, RFC 3339, feste
  Millisekunden, `Z`) über alle Implementierungen.
- CI deckt jetzt **PHP + Node + Cross-Test** ab (vorher nur PHP).

### PHP
- **Breaking** (`superheld/summae-laravel`): Adapter-Klassen `Eloquent*` → `Database*`
  (rollenbasiert benannt; sie nutzten nie das Eloquent-ORM, nur den
  `illuminate/database`-Query-Builder). Runner-Subject `eloquent` → `database`.
- Zeitstempel im kanonischen Format (F-CROSS-001).

## 0.1.0 — 2026-06-18

- Erstes öffentliches Release. PHP-Referenz (`superheld/summae-{core,laravel,cli}`)
  auf Packagist + `@superheld/summae-core` (Node) auf npm. 45/45 Konformitäts-Fixtures,
  zentrales Handbuch (`docs/handbuch`).
