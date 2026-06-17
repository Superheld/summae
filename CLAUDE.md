# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Was das ist

**summae** ist eine einbettbare Rechnungswesen-Bibliothek (GoBD-Doppik, EÜR,
Umsatzsteuer, Anlagen, KLR) — **keine Anwendung**. Mehrere Sprach-Implementierungen
sollen *identische API und identisches Datenformat* haben; geprüft wird das über
eine sprachneutrale Konformitäts-Suite (`testsuite/`). Aktuell existiert nur die
PHP-Referenzimplementierung; Node/Python sind geplant.

Repo-Layout:
- `testsuite/` — der Kompatibilitätsvertrag: `fixtures/**.json` + `schema/`. Geteilt von allen Implementierungen.
- `implementations/php/` — PHP-Monorepo (Packages `core`, `laravel`, `cli` + `runner/`). Eigene README + `docs/`.
- `Makefile`, `compose.yaml`, `docker/` — gemeinsame Docker-Toolchain.

## Befehle

Alles läuft in Docker — **lokal ist kein PHP nötig**. Make-Targets sind die Orchestrierung:

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

Hinter `make` steht `docker compose run --rm php …`. Für direktere Kontrolle (Working-Dir ist `/app/implementations/php`):

```bash
# Einzelnen Test / eine Testsuite (Suites: core, laravel, cli, runner)
docker compose run --rm php vendor/bin/phpunit --testsuite core
docker compose run --rm php vendor/bin/phpunit --filter MoneyTest

# Konformitäts-Runner — Flags:
docker compose run --rm php php runner/bin/run-fixtures.php \
  --strict --subject=core|eloquent --filter=<name> --expected=<datei>
```

`--strict` = alle Fixtures grün **und** Suite-Doppellauf byte-identisch.
`runner/expected-green.txt` ist der Regressionsschutz: ohne `--strict` darf nichts dort Gelistetes rot werden.

Eloquent-Subject braucht Postgres:
```bash
docker compose --profile db up -d postgres
docker compose --profile db run --rm -e SUMMAE_DB_DRIVER=pgsql -e SUMMAE_DB_HOST=postgres \
  php php runner/bin/run-fixtures.php --strict --subject=eloquent
```

## Architektur (das große Bild)

Detailliert in `implementations/php/docs/` (`architektur.md`, `konformitaet.md`, `entwicklung.md`) — hier nur das Wesentliche zum schnellen Produktivwerden.

**Hexagonal, drei Packages.** `core` ist der framework-freie Fachkern (gesamte
Buchführungslogik, einzige Abhängigkeit `brick/math`). `laravel` ist ein dünner
Adapter (Eloquent-Persistenz, ServiceProvider, Migrationen) — **keine Fachlogik,
kein `use Illuminate\…` im Core**. `cli` ist das Terminal-Werkzeug.

**Ports & Adapter.** Der Core definiert Interfaces in `core/src/Port/`
(`AccountRepository`, `JournalRepository`, …). Zwei Adapter-Sätze:
`core/src/InMemory/` (Tests, Konformitätsläufe, CLI) und
`laravel/src/Repository/` (Eloquent, persistiert Aggregate als JSON in `summae_*`-Tabellen).
Ein Mandant entsteht via `Tenant::inMemory(...)` bzw. `EloquentTenantFactory::build(...)` — derselbe `Tenant`, nur andere Ports.

**Ein Einstiegspunkt für alle Operationen:**
`core/src/Composition/TenantOperations.php` ist der Dispatcher für *alle* Ops
(`post`, `postVoucher`, `settle`, …) und Projektionen (`trialBalance`,
`vatReturn`, `journalExport`, …) — Namen exakt nach API-Spec. CLI **und**
Konformitäts-Runner nutzen denselben Dispatcher. Neue Operation → hier verdrahten.

**Lesen läuft nie über gespeicherte Salden.** Jede SuSa/Bilanz/EÜR/USt-Voranmeldung
wird aus dem Journal neu berechnet (`core/src/Projection/`).

## Eiserne Invarianten (nicht verletzen)

- **Journal append-only; Salden sind Projektionen.** Nie einen Saldo speichern.
- **Geld nie als Float.** `Money` auf `brick/math`, kaufmännisch half-up (von Null weg, *kein* banker's rounding), `allocate` mit Largest-Remainder.
- **Determinismus.** Gleiche Eingabe → byte-identisches Ergebnis (Rundung, Sortierung nach Unicode-Codepoints, kanonisches JSON RFC 8785). `Clock`/`IdGenerator` sind injizierbar — Tests **nie** gegen `now()`/Zufall schreiben; der Runner nutzt `FixedClock` + `DeterministicIdGenerator`.
- **Buchungsdatum zonenlos** (`CalendarDate`, kein `DateTime` mit Zeit/UTC-Shift).

## testsuite/ ist read-only

Fixtures sind die normative Quelle und leben in der **Wissensbasis** (Schwester-Repo
„Rechnungswesen"). Sie werden per `make sync` hierher gespiegelt und **hier nie
editiert**. Fixtures sind append-only: Verhaltensänderung = neue Fixture, nie
stilles Editieren. Widerspruch zwischen Spec/Fixture/Modell → **nicht raten, nicht
die Fixture biegen**, sondern in `implementations/php/SPEC-FINDINGS.md` dokumentieren
und mit dem nächstplausiblen Verhalten weiterbauen.

## Konventionen

- PHP ≥ 8.3, PSR-12, `declare(strict_types=1)` überall. PHPStan **level max** ist nicht verhandelbar (kein `@phpstan-ignore` ohne begründenden Kommentar).
- PHP-Namespace ist `Summae\…` (z. B. `Summae\Core\Ledger`), unabhängig vom Composer-Vendor `superheld/`.
- Deutschsprachige Kommentare/Doku, englische API-/Klassennamen.
- Git: **nie direkt auf `main`** — pro Aufgabe ein Branch (`job/…`, `chore/…`, `fix/…`); Merge nach `main` per `--no-ff`, wenn grün.

## Definition of Green (= CI)

PHPStan level max ohne Fehler · PHPUnit grün · Konformitätssuite `--strict` gegen
**beide** Subjects (`core` und `eloquent`) inkl. byte-identischem Doppellauf.
