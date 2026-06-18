# summae

Wiederverwendbare Rechnungswesen-Bibliothek: GoBD-konforme Doppik, EÜR,
Umsatzsteuer, Anlagen und KLR — als einbettbare Implementierung, **nicht** als
Anwendung. Mehrere Sprach-Implementierungen mit **identischer API und
identischem Datenformat**, geprüft gegen eine gemeinsame Konformitäts-Suite.

```
summae/
├── testsuite/              Der Kompatibilitätsvertrag: fixtures/ + schema/
│                           (maßgeblich für alle Implementierungen)
├── implementations/
│   ├── php/                PHP-Implementierung  (core · laravel · cli)
│   └── node/               Node/TypeScript-Implementierung (core · runner)
├── compose.yaml, docker/   Docker-Toolchain (PHP)
└── Makefile                Orchestrierung
```

Beide Implementierungen laufen gegen **dieselbe `testsuite/`** und erzeugen
byte-identische Ergebnisse — das ist der Kern des Versprechens.

## Installation

Kern in ein Projekt einbinden:

```bash
# PHP (Composer)
composer require superheld/summae-core
composer require superheld/summae-laravel   # optionaler Laravel-Adapter

# Node (npm/pnpm)
pnpm add @superheld/summae-core
```

Vollständige Anleitung zu Konfiguration, Initialisierung und Nutzung:
**→ [Handbuch](docs/handbuch/README.md)**.

### Paketnamen über die Ökosysteme

Ein Produktname (`summae`), pro Ökosystem dessen Konvention — der Stamm bleibt
gleich, nur das Registry-Präfix unterscheidet sich:

| Rolle | PHP (Composer) | Node (npm) | Python (PyPI) |
|---|---|---|---|
| Kern | `superheld/summae-core` | `@superheld/summae-core` | `summae-core` |
| CLI | `superheld/summae-cli` | `@superheld/summae-cli` | `summae-cli` |
| Framework-Adapter | `superheld/summae-laravel` | `@superheld/summae-nestjs` | `summae-django` |

Die Sprache steckt im Ordner (`implementations/<sprache>/`), nicht im Namen.
Nur der Framework-Adapter heißt je Framework anders; Kern und CLI bleiben uniform.

## Implementierungen

| | Pfad | Stand | Doku |
|---|---|---|---|
| PHP | `implementations/php/` | Referenz, vollständig | [README](implementations/php/README.md) · [Entwickler-Doku](implementations/php/docs/README.md) |
| Node | `implementations/node/` | M3 — 45/45 Fixtures grün | [README](implementations/node/README.md) |

**Nutzer** (Package einbinden, konfigurieren, verwenden) lesen das
[Handbuch](docs/handbuch/README.md). **Mitentwickler** starten bei der
jeweiligen Entwickler-Doku.

## Der Kompatibilitätsvertrag (`testsuite/`)

`testsuite/fixtures/**.json` + `testsuite/schema/` sind die normative Quelle:
jede Implementierung muss alle Fixtures byte-identisch und deterministisch
erfüllen. Fixtures sind **append-only** — eine Verhaltensänderung wird zu einer
neuen Fixture, bestehende werden nie still editiert.

> **Maintainer-Hinweis:** Die Autoren-Heimat der Fixtures liegt in einer
> separaten, internen Wissensbasis. `bin/sync-testsuite.sh` (bzw. `make sync`)
> spiegelt sie hierher — eine Einbahnstraße, ausschließlich für Maintainer.
> Konsumenten und CI brauchen das nie: die committete `testsuite/` ist
> eigenständig und maßgeblich.

## Schnelltest

```bash
# PHP (Docker, kein lokales PHP nötig)
make build && make install
make check                     # PHPStan max + PHPUnit
make fixtures                  # Konformitätssuite gegen den Kern

# Node
cd implementations/node && pnpm install
pnpm test                      # vitest (Unit + Konformität)
pnpm fixtures --strict         # Konformitätssuite, deterministischer Doppellauf
```

## Lizenz

MIT — siehe [LICENSE](LICENSE).
