# Changelog

Bemerkenswerte Änderungen je Release. Lose an *Keep a Changelog* angelehnt,
Versionierung nach SemVer (0.x: Minor darf brechen).

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
