# CLAUDE.md — Node/TypeScript-Implementierung

Sprachspezifische Regeln und Befehle für `implementations/node/`. Projektweite Regeln
(Eiserne Invarianten, Qualitätsrichtlinie, `testsuite/` read-only, Git) stehen im
Root-`CLAUDE.md`.

**Stand:** Fachkern (`packages/core`) vollständig — 45/45 Fixtures grün gegen den
In-Memory-Port. **M4 erledigt:** Persistenz-Adapter `@superheld/summae-knex`
(Knex + better-sqlite3), 45/45 Fixtures auch gegen `--subject=database`; der
**SF-15-Cross-Test** (`make cross`) bestätigt byte-identischen `journalExport` über
die Sprachgrenze; und die gleichsprachige CLI `@superheld/summae-cli` (`summae
init|op|report`, persistente SQLite, Exit-Codes = Fehlerkatalog). Pakete: `core`,
`knex`, `cli` + `runner`.

## Befehle

pnpm-Workspace, lokal (kein Docker nötig):

```bash
pnpm install
pnpm test          # vitest (Unit + Konformitäts-Test)
pnpm test:watch    # vitest im Watch-Modus
pnpm typecheck     # tsc --noEmit
pnpm lint          # eslint
pnpm build         # tsup pro Package (ESM + CJS + .d.ts)
pnpm fixtures      # Konformitäts-Runner (tsx); --strict = Doppellauf byte-identisch
```

## Konventionen

- Node ≥ 22, ESM (`"type": "module"`), pnpm-Workspace.
- TypeScript `strict` inkl. `noUncheckedIndexedAccess`, `exactOptionalPropertyTypes`,
  `verbatimModuleSyntax` (`tsconfig.base.json`) — **nicht aufweichen.**
- **Geld nie als `number`** → `big.js` (Money), gleiche half-up-Regel wie PHP
  (von Null weg, *kein* banker's rounding).
- **Kern framework-frei:** in `packages/core/**` keine Web-/DB-Frameworks und keine
  DB-Treiber — eslint `no-restricted-imports` erzwingt das. Strukturelles Pendant zu
  „kein `use Illuminate\…` im Core". Adapter werden eigene Pakete (ab M4).
- **Persistenz (M4):** über **Knex** als Schema-/Query-Builder (direktes Pendant zu
  `illuminate/database` der PHP-Seite) mit **better-sqlite3** als sqlite-Treiber, **pg**
  für Postgres. Ziel ist bit-genaues Treffen des `summae_*`-Schemas der PHP-Seite
  (geteilte DB, siehe Qualitätsrichtlinie im Root-`CLAUDE.md`).
- Tests mit **vitest**; Determinismus wie projektweit gefordert (injizierbare
  Clock/IdGenerator, Runner nutzt `FixedClock` + `DeterministicIdGenerator`).
- Bewusst ungenutzte Bindungen mit `_`-Präfix kennzeichnen.

## Definition of Green (hier)

`pnpm typecheck` + `pnpm lint` sauber (Pendant zu „PHPStan level max") · `pnpm test`
grün · `pnpm fixtures --strict` (alle Fixtures grün + byte-identischer Doppellauf).

## Publish

`@superheld/summae-core` liegt auf npm. Dev-Exports zeigen auf die TS-Source
(vitest/tsx ohne Build); `publishConfig` schaltet beim Packen auf `dist/`.
Release-Ablauf: `RELEASING.md` (Repo-Root).
