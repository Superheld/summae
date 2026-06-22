# @superheld/summae-core (Node)

Framework-free accounting core in TypeScript: GoBD double-entry, EÜR,
VAT, fixed assets, cost accounting — parity with the PHP reference. Dual build (ESM + CJS +
types), single runtime dependency: `big.js`. Target platform Node ≥ 22.

```bash
pnpm add @superheld/summae-core      # or npm i / yarn add
```

```ts
import { Tenant, Currency, TenantOperations, SystemClock, UuidV7IdGenerator } from '@superheld/summae-core';

const clock  = new SystemClock();
const tenant = Tenant.inMemory('Demo GmbH', Currency.of('EUR'), clock, new UuidV7IdGenerator(clock));
const ops    = new TenantOperations(tenant);
ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
const susa = ops.project('trialBalance', { fiscalYear: 2026, throughPeriod: 12 });
```

**📖 Full documentation** — installation, initialization, complete
API reference (all operations & projections), value objects, error catalog:
**[summae handbook](https://github.com/Superheld/summae/blob/main/docs/handbuch/README.md)**.

> Build from the repo: `pnpm build` (tsup → `dist/`). In the workspace the
> `exports` point to the TS source (vitest/tsx without a build); on publish
> `publishConfig` overrides to `dist/`.

License: MIT.
