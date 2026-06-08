# Entwickler-Dokumentation

Doku **für Mitentwickler** an `rechnungswesen-php` — nicht für Nutzer des
Packages (die finden alles in den Package-READMEs unter `packages/*/README.md`).

| Dokument | Inhalt |
|---|---|
| [architektur.md](architektur.md) | Schichten, Packages, Ports & Adapter, Datenfluss — *warum* es so geschnitten ist |
| [entwicklung.md](entwicklung.md) | Setup (Docker), Tests/PHPStan/Fixtures, Branch-/Job-Workflow, Konventionen |
| [konformitaet.md](konformitaet.md) | Der Kompatibilitätsvertrag: Fixture-Suite, Determinismus, SPEC-FINDINGS |

## Die wichtigste Regel zuerst

Die **normative Quelle** ist nicht dieser Code, sondern die Wissensbasis
(Schwester-Repo „Rechnungswesen"): Spezifikation, Domänenmodell und die
Konformitäts-Fixtures. Diese Implementierung ist *konform*, wenn alle Fixtures
grün sind. Fixtures werden hier **nie editiert** — Widersprüche gehen nach
[`SPEC-FINDINGS.md`](../SPEC-FINDINGS.md) und fließen über die Wissensbasis
zurück (siehe [konformitaet.md](konformitaet.md)).

## Schnelleinstieg für Neue

1. `packages/core/` lesen — der framework-freie Fachkern, hier passiert alles Fachliche.
2. `docs/architektur.md` — wie core / laravel / cli zusammenhängen.
3. `make check` laufen lassen (Docker) — PHPStan + Tests müssen grün sein.
4. `make fixtures` — die Konformitätssuite gegen den Kern.
