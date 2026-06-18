# summae — Node/TypeScript-Implementierung

Zweite Runtime neben der PHP-Referenz, gegen **denselben Vertrag**: dieselbe
Konformitäts-Suite (`testsuite/` im Repo-Root), identisches Datenformat,
byte-identischer Determinismus. Ziel ist volle Parität und am Ende
Cross-Kompatibilität mit den PHP-Daten.

> Stand: **M3 erreicht** — alle **45/45** Konformitäts-Fixtures grün gegen den
> In-Memory-Port, Doppellauf byte-deterministisch. Shared Kernel, Ledger,
> Open-Items, Tax, EÜR/USt-VA, Mappings (Bilanz/GuV), Assets, Costing, Partner,
> createTenant und Export (GoBD-Z3/DATEV) sind portiert. Offen: Persistenz-
> Adapter + CLI (M4) und der Cross-Test gegen die PHP-Daten.

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
├── packages/core/   framework-freier Fachkern (@superheld/summae-core)
└── runner/          Konformitäts-Fixture-Runner (@superheld/summae-runner)
```

CLI und Persistenz-Adapter (NestJS/Express, Prisma/Knex) kommen ab **M4** als
eigene Pakete. Die **Testsuite wird nicht hierher kopiert** — Runner und Tests
lesen die geteilte `testsuite/` im Repo-Root.

## Befehle

```bash
pnpm install      # einmalig (Workspace verlinken)
pnpm test         # vitest — Unit + Konformitäts-Fixtures (conformance.test.ts)
pnpm fixtures     # Konformitätssuite gegen den Core (--strict / --filter=name)
pnpm typecheck    # tsc --noEmit, strict
pnpm lint         # eslint (u. a. Riegel: kein Framework-Import im Core)
```

## Nutzung & Doku

- **[Handbuch](../../docs/handbuch/README.md)** — Installation, Initialisierung,
  Konfiguration und Nutzung (sprachübergreifend, mit Node-Beispielen).
- `packages/core/README.md` — öffentliche API (`TenantOperations`), lauffähiges
  Beispiel.
- `runner/README.md` — Runner-Befehle, Subject-Kontrakt, Regressionsschutz.
