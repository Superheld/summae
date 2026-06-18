# @summae/runner (Node)

Konformitäts-Fixture-Runner: führt die geteilte Testsuite (`testsuite/` im
Repo-Root, Einbahnstraße aus der Wissensbasis) gegen ein **Subject** aus und
prüft nach dem Runner-Kontrakt (`testsuite/README.md`).

**Stand:** alle 45 Fixtures grün gegen das `CoreSubject` (@summae/core,
In-Memory-Ports), Doppellauf byte-deterministisch.

## Befehle

```bash
pnpm fixtures                 # ganze Suite, Bericht (PASS/FAIL/CRASH)
pnpm fixtures -- --strict     # exit ≠ 0, wenn nicht alles grün / Determinismus bricht
pnpm fixtures -- --filter=vat # nur Fixtures, deren Name den Teilstring enthält
pnpm test                     # vitest — inkl. conformance.test.ts (s. u.)
```

(`pnpm fixtures` läuft via `tsx runner/bin/run-fixtures.ts`.)

## Bausteine

- **`Subject`** (`src/subject.ts`) — das Prüfobjekt: `setup` / `execute(op, input)` /
  `project(name, params)`. Fachfehler werden als `SubjectError` mit exaktem
  `E_*`-Code geworfen; alles andere gilt als Crash.
- **`CoreSubject`** (`src/subject/core-subject.ts`) — Subject über `@summae/core`:
  baut den Mandanten aus dem `setup`-Block, routet über `TenantOperations`,
  übersetzt `DomainError → SubjectError`. Eine neue Runtime/Anbindung implementiert
  nur dieses Interface.
- **`FixtureRunner`** — eine Fixture: `setup → steps → projections`.
- **`SuiteRunner`** — ganze Suite + Doppellauf-Determinismus (UUID-Normalisierung
  auf Auftrittsindex).
- **`Comparator` / `PlaceholderBag`** — Teilmengen-Vergleich und `$V1`/`$E1`-Mechanik.

## Regressionsschutz

`runner/expected-green.txt` listet die aktuell grün erwarteten Fixtures;
`runner/test/conformance.test.ts` lässt die Suite laufen und nagelt diese Liste
(plus Doppellauf-Determinismus) als Teil von `pnpm test` fest.
