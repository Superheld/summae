# Briefing für den Implementierungs-Agenten

Du baust die **Referenzimplementierung (PHP)** eines Rechnungswesen-Packages nach fertiger Spezifikation. Dieses Dokument ist dein Einstieg; es setzt keinerlei Vorwissen über das Projekt voraus.

## Was gebaut wird

Eine Package-Familie, die GoBD-konforme Buchführung (Doppik), EÜR, Umsatzsteuer, Anlagen und KLR beherrscht — als **einbettbare Bibliothek**, nicht als Anwendung. Laufzeitübergreifend (erste Implementierungen PHP, dann Node, Python — das Format ist runtime-offen) mit **identischer API und identischem Datenformat**: Daten aus einer Implementierung können von jeder anderen gelesen und fortgeschrieben werden.

> **Hinweis (2026-06-08):** Die **PHP/Laravel-Referenz ist abgeschlossen** (`ABSCHLUSSBERICHT.md`). Dieses Briefing bleibt gültig für die **nächste Runtime (Node)** und als Referenz. Spec-Stand: v0.5, Testsuite 43 Fixtures / 34 Fehlercodes.

## Der Vertrag (nicht verhandelbar)

1. **`testing/testsuite/`** ist der Kompatibilitätsvertrag: 17 JSON-Fixtures + Runner-Kontrakt (`testing/testsuite/README.md`). Deine Implementierung ist fertig, wenn alle Fixtures grün sind und ein kompletter Doppellauf identische Ergebnisse liefert. **Fixtures werden niemals editiert**, um sie passend zu machen — wenn eine Fixture falsch erscheint, ist das ein Befund und wird gemeldet (siehe Eskalation).
2. **`50-spezifikation/`** ist normativ: `datenformat.md` (v0.2), `api.md` (Operationen, Fehlercodes, Prüfreihenfolge!), `determinismus.md` (Rundung half-up, USt pro Beleg, allocate largest-remainder, Sortierung), `fehlerkatalog.md`, `schema/format.schema.json`.
3. **`20-glossar/glossar.md`**: Die EN-Spalte liefert alle API-/Klassen-/Feldnamen. Keine eigenen Namen erfinden.

## Leseliste (in dieser Reihenfolge, ~30 Minuten)

1. `testing/testsuite/README.md` + zwei Fixtures querlesen (`core/post-and-invariants.json`, `tax/tax-expansion.json`) — das Zielbild
2. `50-spezifikation/api.md` und `datenformat.md`
3. `50-spezifikation/determinismus.md` — die häufigsten Implementierungsfehler stehen hier
4. `40-domaenenmodell/ledger-modell.md` — Aggregate, Invarianten, Events
5. Bei Bedarf je Job: `tax-modell.md`, `assets-modell.md`, `costing-modell.md`, `euer-projektions-beweis.md` (Regeln R1–R6; dazu lauffähiger Prototyp `60-prototyp/euer_projektion.py`)
6. Hintergrund nur bei Unklarheit: `10-fachwissen/`, `00-projekt/entscheidungen.md`

## Architektur-Vorgaben

- **Kern framework-frei:** `rechnungswesen/core` ist reines PHP (≥ 8.3), kein Laravel im Kern. Laravel-Integration ist ein separates Adapter-Package (JOB-012). Das spiegelt sich später in Node (`core` + `nestjs/express`-Adapter) und Python.
- **Hexagonal:** Persistenz hinter einem Port (`LedgerRepository` etc.); der Kern wird komplett gegen eine In-Memory-Implementierung des Ports entwickelt und getestet. Eloquent kommt erst im Adapter.
- **Drei Schichten fachlich:** Kern-Engine (kennt kein Gesetz) / Regelmodule (Steuersätze, Kontenrahmen, Mappings als versionierte **Daten**) / App (nicht unser Problem). Lackmustest: Zitiert Code einen Paragraphen → falsche Schicht.
- **Journal append-only; Salden sind Projektionen** — nie einen Saldo speichern.
- **Geld:** BCMath/dezimal-exakt, half-up, nie Float. `Money` ist ein Value Object mit `add/subtract/allocate`.
- **Fehler:** exakt die Codes aus `fehlerkatalog.md`, Prüfreihenfolge aus `api.md`.

## Repo-Layout (Mehr-Implementierungs-Plan)

```
<neben diesem Ordner anlegen>
rechnungswesen-php/        ← dein Arbeitsbereich (JOB-000)
├── packages/core/         (rechnungswesen/core — framework-frei)
├── packages/laravel/      (rechnungswesen/laravel — Adapter)
├── packages/cli/          (JOB-013)
├── testsuite/             (Kopie von testing/testsuite/ — read-only, Sync-Skript)
└── runner/                (Fixture-Runner, JOB-002)
rechnungswesen-node/       ← später, gleiche Struktur
rechnungswesen-python/     ← später
```

Die Testsuite wird aus `Rechnungswesen (1)/testing/testsuite/` synchronisiert (Einbahnstraße: Wissensbasis → Implementierung).

## Arbeitsweise

- Jobs in `JOBS.md` in Reihenfolge; jeder Job nennt seine Akzeptanz-Fixtures. Test-first: Runner zuerst (JOB-002), dann ist jeder weitere Job „mache Fixtures X, Y grün".
- Konventionen: PHPStan level max, PHPUnit, PSR-12. Keine Abhängigkeit im Kern außer einer Decimal-Lib (brick/math empfohlen).
- Commits je Job, Commit-Message referenziert Job-ID.

## Eskalation (wichtig)

Die Spec ist v0.2 — Lücken sind möglich. Wenn Spec, Fixture und Modell sich widersprechen oder etwas fehlt: **nicht raten, nicht die Fixture ändern.** Stattdessen in `rechnungswesen-php/SPEC-FINDINGS.md` dokumentieren (Was, Wo, Vorschlag) und mit dem nächstplausiblen Verhalten weiterbauen. Diese Findings fließen zurück in die Wissensbasis (Entscheidungslog).
