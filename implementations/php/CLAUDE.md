# CLAUDE.md — PHP-Implementierung

Sprachspezifische Regeln und Befehle für `implementations/php/`. Projektweite Regeln
(Eiserne Invarianten, Qualitätsrichtlinie, `testsuite/` read-only, Git) stehen im
Root-`CLAUDE.md`.

## Befehle

Alles läuft in Docker — **lokal ist kein PHP nötig.** Make-Targets (im Repo-Root)
sind die Orchestrierung:

```bash
make build      # PHP-8.3-Image bauen (einmalig)
make install    # composer install
make check      # PHPStan (level max) + PHPUnit — exakt das, was CI prüft
make fixtures   # Konformitätssuite gegen den In-Memory-Kern
make test       # nur PHPUnit
make stan       # nur PHPStan level max
make sync       # testsuite/ aus der Wissensbasis spiegeln (Einbahnstraße)
make shell      # Shell im PHP-Container
```

Hinter `make` steht `docker compose run --rm php …`. Direktere Kontrolle
(Working-Dir `/app/implementations/php`):

```bash
# Einzelner Test / eine Suite (Suites: core, laravel, cli, runner)
docker compose run --rm php vendor/bin/phpunit --testsuite core
docker compose run --rm php vendor/bin/phpunit --filter MoneyTest

# Konformitäts-Runner
docker compose run --rm php php runner/bin/run-fixtures.php \
  --strict --subject=core|database --filter=<name> --expected=<datei>
```

`--strict` = alle Fixtures grün **und** Suite-Doppellauf byte-identisch.
`runner/expected-green.txt` = Regressionsschutz (ohne `--strict` darf nichts dort
Gelistetes rot werden). Das Database-Subject braucht Postgres:

```bash
docker compose --profile db up -d postgres
docker compose --profile db run --rm -e SUMMAE_DB_DRIVER=pgsql -e SUMMAE_DB_HOST=postgres \
  php php runner/bin/run-fixtures.php --strict --subject=database
```

## Konventionen

- PHP ≥ 8.3, PSR-12, `declare(strict_types=1)` überall.
- PHPStan **level max** ist nicht verhandelbar (kein `@phpstan-ignore` ohne
  begründenden Kommentar).
- PHP-Namespace `Summae\…` (z. B. `Summae\Core\Ledger`), unabhängig vom
  Composer-Vendor `superheld/`.
- **Pack-Komposition:** Resolver `packages/core/src/Composition/PackResolver.php`; Loader (liest die
  ausgelieferte `pack-library/`) `runner/src/PackLibrary.php`. Module/Manifeste **referenzieren**,
  nicht inline duplizieren.

## Definition of Green (hier)

PHPStan level max ohne Fehler · PHPUnit grün · Konformitätssuite `--strict` gegen
**beide** Subjects (`core` und `database`) inkl. byte-identischem Doppellauf.

## Tiefer: `docs/`

- `docs/architektur.md` — drei Packages, framework-freier Kern, Hexagonal/Ports,
  fachliche Schichten, `TenantOperations` als Einstiegspunkt, Datenfluss einer Buchung.
- `docs/entwicklung.md` — Setup, was CI prüft, Konventionen, Branch-/Commit-Workflow,
  „eine neue Operation/Projektion hinzufügen", Spec-Retrofit, Determinismus-Hooks.
- `docs/konformitaet.md` — der Kompatibilitätsvertrag, wie der Runner arbeitet,
  die häufigsten Cross-Impl-Fallen, der SPEC-FINDINGS-Eskalationsweg.
- `SPEC-FINDINGS.md` — dokumentierte Widersprüche zwischen Spec/Fixture/Modell.

Die **sprachneutralen Bau-Prinzipien** (Pack = primär Daten/Stecker, 1:1-Spiegelung, test-driven,
framework-frei) stehen im Root-`CLAUDE.md`; Patterns-Liste in `docs/architektur.md`, das Rezept
„neue Operation = Service + `case` + Fixture" in `docs/entwicklung.md` — hier nur die PHP-Idiome.
