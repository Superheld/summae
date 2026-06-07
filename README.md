# rechnungswesen-php

PHP-Referenzimplementierung der Rechnungswesen-Spezifikation: GoBD-konforme
Doppik, EÜR, Umsatzsteuer, Anlagen und KLR — als einbettbare Bibliothek.
Aus Nutzersicht **ein** Laravel-Package: `composer require rechnungswesen/laravel`.

Normative Quelle ist die Wissensbasis (Schwester-Repo): Spezifikation v0.2,
Konformitäts-Testsuite, Domänenmodell. Einstieg dort:
`80-implementierung/AGENT-BRIEFING.md` und `JOBS.md`.

## Struktur

| Pfad | Inhalt |
|---|---|
| `packages/core/` | `rechnungswesen/core` — framework-freier Fachkern (PHP ≥ 8.3, einzige Abhängigkeit: brick/math) |
| `packages/laravel/` | `rechnungswesen/laravel` — ServiceProvider, Eloquent-Adapter, Migrationen (JOB-012) |
| `packages/cli/` | `rechnungswesen/cli` — CLI, JSON-Ausgaben (JOB-013) |
| `runner/` | Fixture-Runner für die Konformitätssuite (JOB-002) |
| `testsuite/` | Kopie der Konformitäts-Fixtures — **read-only**, via `make sync` |
| `SPEC-FINDINGS.md` | Befunde gegen Spec/Fixtures (Eskalationsweg aus dem Briefing) |

## Entwicklung

Alles läuft in Docker, lokal ist kein PHP nötig:

```bash
make build      # PHP-8.3-Image bauen (einmalig)
make install    # composer install
make check      # PHPStan (level max) + PHPUnit — das prüft auch die CI
make sync       # Testsuite aus der Wissensbasis aktualisieren (Einbahnstraße)
make shell      # Shell im Container
```

Postgres wird erst ab JOB-012 gebraucht: `docker compose --profile db up -d`
(Port 54329, User/DB/Passwort: `rechnungswesen`).

## Eiserne Regeln

1. **Fixtures werden nie editiert.** Widerspruch gefunden → `SPEC-FINDINGS.md`.
2. **Kern bleibt framework-frei.** Kein `Illuminate\*` in `packages/core`.
3. **Journal append-only, Salden sind Projektionen.** Nie einen Saldo speichern.
4. **Geld nie als Float.** `Money` auf brick/math, half-up, allocate largest-remainder.
5. Namen kommen aus dem Glossar (EN-Spalte), Fehlercodes aus dem Fehlerkatalog.
