import { describe, expect, it } from 'vitest';
import { Currency, DeterministicIdGenerator, FixedClock, Tenant, TenantOperations } from '../src/index.js';

/**
 * `journalExport`'s field catalogue describes the streams COMPLETELY (IMPL-038).
 *
 * The catalogue is the self-description a GoBD Z3 data set owes an auditor: name, type, meaning per
 * field. Until 2026-08-28 it was a *selection* without saying so — 4 of the account's 8 fields, 2 of
 * the voucher's 12, 4 of the audit record's 9, no `voucherDate` on the posting, and the `partners`
 * stream missing entirely. Somebody reading the description and the data side by side found fields
 * in the data the description does not mention, which is precisely the situation a self-describing
 * data set exists to prevent.
 *
 * Nothing caught it: `io/journal-export-z3-current` pins `fieldCatalogIncluded` — a boolean — and
 * the catalogue is outside the content hashes, so it could drift as far as it liked while the whole
 * gate stayed green.
 *
 * The export below is built to carry every optional field on purpose. That is what makes the second
 * assertion work in the other direction as well: adding a field to a record without describing it
 * fails here, and describing a field the export cannot carry fails here too.
 *
 * PHP twin: FieldCatalogCompletenessTest.
 */

type Rows = Array<Record<string, unknown>>;
type Field = { name: string; type: string; meaning: string };

/** An export in which every optional field of every stream carries a value. */
function richExport(): Record<string, unknown> {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory('Prüfer GmbH', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  const ops = new TenantOperations(tenant);

  ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
  ops.execute('createAccount', {
    number: '1200',
    name: 'Bank',
    type: 'asset',
    subtype: 'bank',
    validFrom: '2026-01-01',
    validTo: '2026-12-31',
  });
  ops.execute('createAccount', { number: '8400', name: 'Erlöse', type: 'revenue' });
  ops.execute('createAccount', { number: '10000', name: 'Kunde AG', type: 'asset', subtype: 'ar' });

  const partner = ops.execute('createPartner', {
    name: 'Kunde AG',
    kind: 'customer',
    vatId: 'DE123456789',
    paymentTermsDays: 30,
    accountNumbers: ['10000'],
    address: { city: 'Köln' },
  }) as Record<string, unknown>;

  const voucher = ops.execute('createVoucher', {
    voucher: {
      voucherNumber: 'RE-1',
      voucherDate: '2026-03-01',
      serviceDate: '2026-02-28',
      servicePeriod: { from: '2026-02-01', to: '2026-02-28' },
      economicYear: 2026,
      due: '2026-03-31',
      recurring: false,
      issuer: 'Kunde AG',
      kind: 'invoice',
      partnerId: partner.id as string,
      supplierTaxationMethod: 'accrual',
    },
  }) as Record<string, unknown>;

  const entry = ops.execute('post', {
    entryDate: '2026-03-01',
    voucherId: voucher.id as string,
    text: 'Ausgangsrechnung',
    lines: [
      { account: '1200', side: 'debit', money: { amount: '100.00', currency: 'EUR' } },
      { account: '8400', side: 'credit', money: { amount: '100.00', currency: 'EUR' } },
    ],
  }) as Record<string, unknown>;

  // A reversal, so `reverses` on one entry and `reversedBy` on the other both carry a value.
  ops.execute('reverse', { entryId: entry.id as string, entryDate: '2026-03-02' });

  return ops.project('journalExport', {}) as Record<string, unknown>;
}

function keysIn(rows: Rows): string[] {
  const keys = new Set<string>();
  for (const row of rows) for (const key of Object.keys(row)) keys.add(key);
  return [...keys].sort();
}

function describedNames(fields: Field[]): string[] {
  return fields.map((f) => f.name).sort();
}

describe('the Z3 field catalogue', () => {
  it('is checked against an export that carries all five streams', () => {
    const data = richExport().data as Record<string, Rows>;
    expect(
      Object.keys(data),
      'this test is only worth its assertions if the export really carries all five streams',
    ).toEqual(['journal', 'accounts', 'vouchers', 'partners', 'auditLog']);
  });

  it('describes exactly the streams on the carrier', () => {
    const exported = richExport();
    const data = exported.data as Record<string, Rows>;
    const catalog = exported.fieldCatalog as Record<string, Field[]>;
    const manifest = exported.manifest as Record<string, unknown>;

    expect(Object.keys(catalog)).toEqual(Object.keys(data));
    expect(Object.keys(catalog)).toEqual(manifest.streams);
  });

  it('describes every field in every stream', () => {
    const exported = richExport();
    const data = exported.data as Record<string, Rows>;
    const catalog = exported.fieldCatalog as Record<string, Field[]>;

    for (const [stream, rows] of Object.entries(data)) {
      expect(
        describedNames(catalog[stream] ?? []),
        `stream "${stream}": the self-description and the data must name the same fields — a field in the data ` +
          'the catalogue omits is what an auditor trips over; a field in the catalogue the data cannot carry is ' +
          'the same defect mirrored',
      ).toEqual(keysIn(rows));
    }
  });

  // Every described field says what it is and what it means. A row with an empty `meaning` would
  // satisfy the completeness check above while telling a reader nothing.
  it('gives every described field a type and a meaning', () => {
    const catalog = richExport().fieldCatalog as Record<string, Field[]>;
    const empty: string[] = [];

    for (const [stream, fields] of Object.entries(catalog)) {
      for (const field of fields) {
        for (const key of ['type', 'meaning'] as const) {
          if (typeof field[key] !== 'string' || field[key].trim() === '') empty.push(`${stream}.${field.name} has no ${key}`);
        }
      }
    }

    expect(empty).toEqual([]);
  });
});
