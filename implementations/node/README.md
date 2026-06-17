# summae — Node/TypeScript-Implementierung

Zweite Runtime neben der PHP-Referenz, gegen **denselben Vertrag**: dieselbe
Konformitäts-Suite, identisches Datenformat, byte-identischer Determinismus.
Ziel ist Parität (siehe `../../00-projekt/developer-roadmap-stage2.md` in der
Wissensbasis) und am Ende Cross-Kompatibilität mit den PHP-Daten (SF-15).

> Stand: **M0 — Gerüst**. Noch kein Fachcode; nur Fundament, Decimal-Beweis und
> Fixture-Loader gegen die geteilte Suite. Der Meilenstein-Pfad (M1 Shared
> Kernel → … → M-Cross) steht im Runtime-Handoff der Wissensbasis.

## Stack

| | Wahl | Warum |
|---|---|---|
| Sprache | TypeScript (strict, ESM) | |
| Workspaces | **pnpm** | strikte Dependency-Isolation hält den Kern framework-frei |
| Tests | **vitest** | TS/ESM-nativ, test-first |
| Geld | **big.js** | dezimal-exakt, `roundHalfUp` = away-from-zero; kleine Fläche = wenig Determinismus-Fallen |

## Layout

```
implementations/node/
├── packages/core/   framework-freier Fachkern (@summae/core)
└── runner/          Konformitäts-Fixture-Runner (@summae/runner)
```

CLI und Persistenz-Adapter (NestJS/Express, Prisma/Knex) kommen ab **M4** als
eigene Pakete. Die **Testsuite wird nicht hierher kopiert** — Runner und Tests
lesen die geteilte `testsuite/` im Repo-Root (gepflegt via
`../../bin/sync-testsuite.sh`, Einbahnstraße aus der Wissensbasis).

## Befehle

```bash
pnpm install      # einmalig (Workspace verlinken)
pnpm test         # vitest (Decimal-Beweis + Fixture-Loader-Smoke)
pnpm typecheck    # tsc --noEmit, strict
pnpm lint         # eslint (u. a. Riegel: kein Framework-Import im Core)
```
