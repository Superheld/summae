# rechnungswesen

Wiederverwendbare Rechnungswesen-Bibliothek: GoBD-konforme Doppik, EÜR,
Umsatzsteuer, Anlagen und KLR — als einbettbare Implementierung, **nicht** als
Anwendung. Mehrere Sprach-Implementierungen mit **identischer API und
identischem Datenformat**, geprüft gegen eine gemeinsame Konformitäts-Suite.

```
rechnungswesen/
├── testsuite/              Der Kompatibilitätsvertrag: fixtures/ + schema/
│                           (geteilt von allen Implementierungen)
├── implementations/
│   └── php/                PHP-Implementierung (Composer-Packages core/laravel/cli)
│       └── …               eigene README + docs/ dort
├── bin/sync-testsuite.sh   Testsuite aus der Wissensbasis spiegeln (Einbahnstraße)
├── compose.yaml, docker/   gemeinsame Docker-Toolchain
└── Makefile                Orchestrierung
```

Geplant ist eine zweite Implementierung (`implementations/node/`) gegen
dieselbe `testsuite/`.

## Normative Quelle

Spezifikation, Domänenmodell und die *Autoren-Heimat* der Fixtures liegen in
der **Wissensbasis** (separates Schwester-Repo „Rechnungswesen"). Die
Konformitäts-Suite wird von dort hierher gespiegelt:

```bash
make sync   # Wissensbasis/70-testsuite  →  testsuite/   (read-only Kopie)
```

Fixtures werden hier **nie editiert** — Widersprüche gehen über
`implementations/php/SPEC-FINDINGS.md` zurück in die Wissensbasis.

## Implementierungen

| | Pfad | Doku |
|---|---|---|
| PHP | `implementations/php/` | [README](implementations/php/README.md) · [Entwickler-Doku](implementations/php/docs/README.md) |
| Node | `implementations/node/` | *geplant* |

**Nutzer** (Package in ein Laravel-Projekt einbinden, Konfiguration) lesen die
PHP-README. **Mitentwickler** starten bei der Entwickler-Doku.

## Schnelltest (PHP, Docker)

```bash
make build && make install     # einmalig
make sync                      # Testsuite holen
make check                     # PHPStan max + PHPUnit
make fixtures                  # Konformitätssuite gegen den Kern
```

## Lizenz

MIT — siehe [LICENSE](LICENSE).
