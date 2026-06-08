# Entwicklung

## Setup

Alles läuft in Docker — lokal ist **kein PHP** nötig.

```bash
make build      # PHP-8.3-Image bauen (einmalig; bcmath + pdo_pgsql)
make install    # composer install
make check      # PHPStan (level max) + PHPUnit — das prüft auch die CI
make fixtures   # Konformitätssuite gegen den In-Memory-Kern
make shell      # Shell im Container
make sync       # Testsuite + Schema aus der Wissensbasis aktualisieren
```

Postgres wird nur für den Eloquent-Konformitätslauf gebraucht:

```bash
docker compose --profile db up -d postgres
docker compose --profile db run --rm -e RW_DB_DRIVER=pgsql -e RW_DB_HOST=postgres \
  php php runner/bin/run-fixtures.php --strict --subject=eloquent
```

## Was grün sein muss (= CI)

- **PHPStan level max**, keine Fehler (`vendor/bin/phpstan analyse`).
- **PHPUnit** (`vendor/bin/phpunit`).
- **Konformitätssuite strict** gegen beide Subjects:
  `php runner/bin/run-fixtures.php --strict` und `--strict --subject=eloquent`
  — alle Fixtures grün **und** Doppellauf byte-identisch.

Die `expected-green.txt` im `runner/` ist der Regressionsschutz: ohne
`--strict` darf nichts, was dort gelistet ist, rot werden.

## Konventionen

- **PSR-12**, PHP ≥ 8.3, `declare(strict_types=1)` überall.
- **PHPStan level max** ist nicht verhandelbar — kein `@phpstan-ignore` ohne
  Begründung im Kommentar.
- **Kein `use Illuminate\…` in `packages/core`.** (Siehe architektur.md.)
- **Namespace `Rechnungswesen\…`** (Domäne), unabhängig vom Composer-Vendor.
- **Geld nie als Float**, Daten nie als `DateTime` mit Zeit fürs Buchungsdatum
  (zonenlose `CalendarDate`). Siehe konformitaet.md.
- Deutschsprachige Kommentare/Doku, englische API-/Klassennamen (aus dem
  Glossar der Wissensbasis).

## Branch- & Commit-Workflow

- **Nie direkt auf `main`.** Pro Aufgabe ein Branch (`job/…`, `chore/…`, `fix/…`).
- Ein Commit je abgeschlossener Einheit; Commit-Message nennt die Job-/Themen-ID
  und was fachlich passiert ist (nicht nur „WIP").
- Merge nach `main` per `--no-ff`, wenn die Einheit grün ist.

## Eine neue Operation / Projektion hinzufügen

1. **Modell-Doku in der Wissensbasis lesen** (`40-domaenenmodell/…`,
   `50-spezifikation/…`) — frisch, die Spec lebt.
2. Fachlogik in `packages/core` bauen (Aggregat/Service/Projektion), gegen
   In-Memory-Port und mit Unit-Tests.
3. Im Dispatcher `TenantOperations` verdrahten (eine Stelle für CLI + Runner).
4. Falls neuer Eloquent-Persistenzbedarf: Port + In-Memory- **und**
   Eloquent-Adapter ergänzen, `SchemaInstaller` erweitern.
5. `make check` + `make fixtures` (beide Subjects) grün.

## Spec-Änderung kommt rein (Retrofit)

1. `make sync` — neue/geänderte Fixtures + Schema holen.
2. `make fixtures` — sehen, was rot wird (kontrolliertes Versagen, kein Crash).
3. Spec-Dateien der Wissensbasis frisch lesen (nicht aus dem Gedächtnis).
4. Anpassen bis grün; bei Widerspruch zwischen Spec/Fixture →
   [`SPEC-FINDINGS.md`](../SPEC-FINDINGS.md), nicht die Fixture biegen.

## Determinismus-Hooks (wichtig fürs Testen)

`Clock` und `IdGenerator` sind injizierbar. Der Konformitäts-Runner nutzt
`FixedClock` + `DeterministicIdGenerator` (Zähler statt Zufall), damit der
Doppellauf inkl. SHA-256-Strom-Hashes byte-identisch ist. Produktion nutzt
`SystemClock` + `UuidV7IdGenerator`. Schreib Tests nie gegen `now()`/Zufall.
