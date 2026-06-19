# @superheld/summae-core (Node)

Framework-freier Rechnungswesen-Kern in TypeScript: GoBD-Doppik, EÜR,
Umsatzsteuer, Anlagen, KLR — Parität zur PHP-Referenz. Dual-Build (ESM + CJS +
Typen), einzige Laufzeit-Abhängigkeit: `big.js`. Zielplattform Node ≥ 22.

```bash
pnpm add @superheld/summae-core      # oder npm i / yarn add
```

```ts
import { Tenant, Currency, TenantOperations, SystemClock, UuidV7IdGenerator } from '@superheld/summae-core';

const clock  = new SystemClock();
const tenant = Tenant.inMemory('Demo GmbH', Currency.of('EUR'), clock, new UuidV7IdGenerator(clock));
const ops    = new TenantOperations(tenant);
ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
const susa = ops.project('trialBalance', { fiscalYear: 2026, throughPeriod: 12 });
```

**📖 Vollständige Dokumentation** — Installation, Initialisierung, komplette
API-Referenz (alle Operationen & Projektionen), Value Objects, Fehlerkatalog:
**[summae-Handbuch](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.

> Build aus dem Repo: `pnpm build` (tsup → `dist/`). Im Workspace zeigen die
> `exports` auf die TS-Source (vitest/tsx ohne Build); beim Publish überschreibt
> `publishConfig` auf `dist/`.

Lizenz: MIT.
