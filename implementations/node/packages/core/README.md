# @superheld/summae-core (Node)

Framework-freier Rechnungswesen-Kern in TypeScript — die gesamte Buchführungs-
logik (GoBD-Doppik, EÜR, USt-VA, Anlagen, KLR), portiert in Parität zur
PHP-Referenz. **M3 erreicht:** alle 45 Konformitäts-Fixtures grün gegen den
In-Memory-Port, Doppellauf byte-deterministisch.

## Installation

```bash
pnpm add @superheld/summae-core      # oder npm i / yarn add
```

Das Paket wird dual ausgeliefert — **ESM** (`import`) und **CJS** (`require`),
mit Typdeklarationen. Einzige Laufzeit-Abhängigkeit: `big.js`. Zielplattform ist
**Node ≥ 22** (der Kern nutzt `node:crypto` für UUIDv7 und GoBD-Hash).

```ts
import { Money, TenantOperations } from '@superheld/summae-core';
```

> **Build aus dem Repo:** `pnpm build` (tsup → `dist/` mit ESM+CJS+`.d.ts`). Im
> Workspace selbst zeigen die `exports` auf die TS-Source — vitest/tsx
> transpilieren on-the-fly, ein separater Build ist für die Entwicklung nicht
> nötig. Beim Publish überschreibt `publishConfig` die Felder auf `dist/`.

## Öffentliche API

Generischer Einstieg ist der **Dispatcher** `TenantOperations` — Operationen und
Projektionen mit Namen exakt nach `api.md`:

```ts
const ops = new TenantOperations(tenant);
ops.execute('post', input);             // Schreiboperationen
ops.project('trialBalance', params);    // lesende Projektionen
```

Operationen: `post`, `postVoucher`, `correct`, `finalize`, `reverse`, `settle`,
`closePeriod`/`reopenPeriod`/`closeFiscalYear`, `createAccount`/`createFiscalYear`,
`lockAccount`, `importChartOfAccounts`, `importMapping`, `expandTax`/`setTaxProfile`,
`acquireAsset`/`disposeAsset`/`runDepreciation`, `setAllocationScheme`/`runCosting`/
`releaseCosting`, `createPartner`/`updatePartner`, `createTenant`.

Projektionen: `trialBalance`, `accountSheet`, `auditLog`, `openItems`, `vatReturn`,
`incomeStatement`, `balanceSheet`, `cashBasisReport`, `assetRegister`,
`costAllocationSheet`, `ecSalesList`, `journalExport`, `datevExport`.

Daneben exportiert das Paket die Value Objects (`Money`, `Currency`,
`CalendarDate`, `AccountNumber`, `Uuid`, `canonicalJson`) und Aggregate.

## Beispiel (im Workspace, via tsx)

```ts
import {
  CalendarDate, Currency, DeterministicIdGenerator, FixedClock,
  Tenant, TenantOperations, Voucher,
} from '@superheld/summae-core';

// Determinismus-Hooks injizierbar (Produktion: SystemClock + UuidV7IdGenerator).
const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
const tenant = Tenant.inMemory('Demo GmbH', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
const ops = new TenantOperations(tenant);

ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
ops.execute('createAccount', { number: '1200', name: 'Bank',      type: 'asset',     subtype: 'bank' });
ops.execute('createAccount', { number: '8400', name: 'Erlöse',    type: 'revenue' });
ops.execute('createAccount', { number: '1776', name: 'USt 19%',   type: 'liability', subtype: 'tax_out' });

const voucher = new Voucher({ id: tenant.ids.next(), voucherNumber: 'AR-001', voucherDate: CalendarDate.of('2026-03-05') });
tenant.vouchers.add(voucher);

const posted = ops.execute('post', {
  entryDate: '2026-03-05', voucherId: voucher.id.value, text: 'Barverkauf',
  lines: [
    { account: '1200', side: 'debit',  money: { amount: '119.00', currency: 'EUR' } },
    { account: '8400', side: 'credit', money: { amount: '100.00', currency: 'EUR' } },
    { account: '1776', side: 'credit', money: { amount: '19.00',  currency: 'EUR' } },
  ],
});

console.log(posted.sequenceNumber, posted.status);                  // 1 entered
console.log(ops.project('trialBalance', { fiscalYear: 2026, throughPeriod: 12 }));
```

Ausführen aus einem Paket, das `@superheld/summae-core` als Dependency hat (z. B. `runner/`):
`pnpm exec tsx pfad/zur/datei.ts`.

## Prinzipien

- **Journal append-only; Salden sind Projektionen** — nie einen Saldo speichern.
- **Geld nie als Float** (`Money` auf `big.js`, half-up away-from-zero, `allocate`
  largest-remainder).
- **Determinismus** (`Clock`/`IdGenerator` injizierbar; gleiche Eingabe →
  byte-identisches Ergebnis).

Normative Quelle ist die Konformitäts-Suite (`testsuite/` im Repo-Root);
die PHP-Referenz ist der Goldstandard.
