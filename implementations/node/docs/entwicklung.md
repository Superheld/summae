# Entwicklung (Node)

## Setup

pnpm-Workspace, lokal — **kein Docker nötig** (Node ≥ 22).

```bash
pnpm install
pnpm typecheck     # tsc --noEmit
pnpm lint          # eslint
pnpm test          # vitest (Unit + Konformität)
pnpm fixtures      # Konformitäts-Runner (tsx); --strict = Doppellauf byte-identisch
pnpm build         # tsup pro Paket (ESM + CJS + .d.ts)
```

`make sync` (Repo-Root) aktualisiert `testsuite/` + die `pack-library/` aus der Wissensbasis.

## Was grün sein muss (= CI)

- **`pnpm typecheck`** sauber (Pendant zu „PHPStan level max").
- **`pnpm lint`** sauber.
- **`pnpm test`** grün (vitest).
- **Konformität strict** gegen beide Subjects — alle Fixtures grün **und** Doppellauf byte-identisch:

  ```bash
  pnpm fixtures --strict                     # In-Memory-Kern
  pnpm fixtures --strict --subject=database  # Knex-Adapter (better-sqlite3, lokal)
  ```

`runner/expected-green.txt` ist der Regressionsschutz: ohne `--strict` darf nichts dort Gelistetes
rot werden. **Fixture-Stände nicht hier hartkodieren** — sie driften; der aktuelle Stand kommt aus
`pnpm fixtures`.

## Konventionen

- **TypeScript `strict`** inkl. `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`,
  `verbatimModuleSyntax` (`tsconfig.base.json`) — **nicht aufweichen.**
- **ESM** (`"type": "module"`), Node ≥ 22, pnpm-Workspace.
- **Geld nie als `number`** → `big.js` (`Money`), half-up von Null weg (kaufmännisch, *kein*
  banker's). Siehe [konformitaet.md](konformitaet.md).
- **Kern framework-frei:** kein Web-/DB-Import in `packages/core/**` — eslint `no-restricted-imports`
  erzwingt es. Adapter sind eigene Pakete (`knex`, `cli`).
- **Buchungsdatum zonenlos** (`CalendarDate`, keine Zeit/UTC-Shift).
- Bewusst ungenutzte Bindungen mit `_`-Präfix.
- Deutschsprachige Kommentare/Doku, englische API-/Klassennamen.

## Branch- & Commit-Workflow

- **Nie direkt auf `main`/`develop`.** Pro Aufgabe ein Branch (`job/…`, `chore/…`, `fix/…`).
- Ein Commit je abgeschlossener, grüner Einheit; Message nennt Job-/Themen-ID und was fachlich
  passiert ist (nicht „WIP"). Merge per `--no-ff`, wenn grün.

## Eine neue Operation / Projektion hinzufügen

Das **Rezept** ist sprachneutral (Root-`CLAUDE.md`, „Bau-Konventionen"). In Node konkret:

1. **Modell-/Spec-Doku der Wissensbasis frisch lesen** (`40-domaenenmodell/…`, `50-spezifikation/…`).
2. Fachlogik in `packages/core` bauen (Service/Aggregat/Projektion), gegen In-Memory-Port + vitest-Unit-Tests.
3. Im Dispatcher `composition/tenant-operations.ts` einen `case` in `execute`/`project` ergänzen
   (eine Stelle für CLI + Runner).
4. Bei neuem Persistenzbedarf: Port (`port.ts`) + In-Memory- **und** Knex-Adapter, `schema-installer.ts` erweitern.
5. **In PHP spiegeln** (gleicher `case`, gleiche Prüfreihenfolge) — Byte-Parität ist Vertrag.
6. Grün: `pnpm typecheck`/`lint`/`test` + `pnpm fixtures --strict` (beide Subjects) **und**
   `make cross` (SF-15, PHP↔Node byte-gleich).

## Spec-Änderung kommt rein (Retrofit)

1. `make sync` — neue/geänderte Fixtures + Schema + `pack-library/` holen.
2. `pnpm fixtures` — sehen, was rot wird (kontrolliertes Versagen, kein Crash).
3. Spec-Dateien der Wissensbasis frisch lesen (nicht aus dem Gedächtnis).
4. Anpassen bis grün; bei Widerspruch Spec/Fixture → [`../SPEC-FINDINGS.md`](../SPEC-FINDINGS.md),
   **nicht die Fixture biegen**.

## Determinismus-Hooks (wichtig fürs Testen)

`Clock` und `IdGenerator` sind injizierbar (`packages/core/src/shared/`). Der Konformitäts-Runner
nutzt `FixedClock` + `DeterministicIdGenerator` (Zähler statt Zufall), damit der Doppellauf
byte-identisch ist. Produktion nutzt `SystemClock` + `UuidV7IdGenerator`. **Schreib Tests nie gegen
`new Date()`/`Math.random()`.**
