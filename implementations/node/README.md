# summae — Node/TypeScript-Implementierung

Zweite Runtime neben der PHP-Referenz, gegen **denselben Vertrag**: dieselbe
Konformitäts-Suite, identisches Datenformat, byte-identischer Determinismus.
Ziel ist Parität (siehe `../../00-projekt/developer-roadmap-stage2.md` in der
Wissensbasis) und am Ende Cross-Kompatibilität mit den PHP-Daten (SF-15).

> Stand: **M3 erreicht** — alle **45/45** Konformitäts-Fixtures grün gegen den
> In-Memory-Port, Doppellauf byte-deterministisch. Shared Kernel, Ledger,
> Open-Items, Tax, EÜR/USt-VA, Mappings (Bilanz/GuV), Assets, Costing, Partner,
> createTenant und Export (GoBD-Z3/DATEV) sind portiert. Offen: Persistenz-
> Adapter + CLI (M4) und der Cross-Test gegen PHP-Daten (SF-15/M-Cross).

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
pnpm test         # vitest — Unit + Konformitäts-Fixtures (conformance.test.ts)
pnpm fixtures     # Konformitätssuite gegen den Core (--strict / --filter=name)
pnpm typecheck    # tsc --noEmit, strict
pnpm lint         # eslint (u. a. Riegel: kein Framework-Import im Core)
```

## Nutzung & Doku

- `packages/core/README.md` — öffentliche API (`TenantOperations`), lauffähiges
  Beispiel und Status der externen Konsumierbarkeit (Build/Publish kommt mit M4).
- `runner/README.md` — Runner-Befehle, Subject-Kontrakt, Regressionsschutz.

> **Heute:** workspace-intern nutzbar (vitest/tsx). Als publiziertes npm-Paket
> noch nicht eingerichtet — `@summae/core` ist `private` ohne Build.
