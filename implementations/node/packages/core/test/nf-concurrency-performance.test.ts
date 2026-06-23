import { describe, expect, it } from 'vitest';
import {
  Currency,
  DeterministicIdGenerator,
  FixedClock,
  TaxCodeRegistry,
  TaxProfile,
  Tenant,
  TenantOperations,
} from '../src/index.js';

/**
 * Dedicated tests for the two non-functional requirements that a behavioral fixture
 * cannot express (quality-gate obligation 3):
 *
 * - NF-6 (concurrency): the journal assigns unique, gapless, monotonic sequence numbers.
 *   The framework-free in-memory core is single-threaded; OS-level concurrent writing is
 *   the persistence adapter's concern (documented in the handbook). What is testable —
 *   and what NF-6 protects — is that the sequence allocation never duplicates or skips.
 * - NF-7 (performance): a realistic bulk load (10k postings) plus the two heaviest
 *   journal projections stays well within budget. The bound is generous (≈10x the
 *   expected time) on purpose: it must never flake on a loaded CI runner, only catch a
 *   catastrophic regression (e.g. an accidental O(n²) in posting or a projection).
 */
type BulkTenant = { tenant: Tenant; ops: TenantOperations; voucherId: string };

function bulkTenant(): BulkTenant {
  const clock = FixedClock.at('2026-06-08T12:00:00+02:00');
  const tenant = Tenant.inMemory(
    'NF',
    Currency.of('EUR'),
    clock,
    new DeterministicIdGenerator(clock),
    undefined,
    TaxCodeRegistry.fromData([
      {
        code: 'USt19',
        versions: [
          { validFrom: '2024-01-01', validTo: null, rate: '19.00', taxAccount: '1776', reportingKey: '81' },
        ],
      },
    ]),
    TaxProfile.fromData({}),
  );
  const ops = new TenantOperations(tenant);
  ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
  ops.execute('importChartOfAccounts', {
    format: 'datev-csv',
    rows: [
      { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' },
      { number: '8400', name: 'Revenue 19%', type: 'revenue' },
      { number: '1776', name: 'VAT 19%', type: 'liability', subtype: 'tax_out' },
    ],
  });
  const voucher = ops.execute('postVoucher', {
    voucher: { voucherNumber: 'NF-V', voucherDate: '2026-01-02' },
    entryDate: '2026-01-02',
    text: 'Voucher for bulk postings',
    taxCode: 'USt19',
    direction: 'output',
    netLines: [{ account: '8400', money: { amount: '1.00', currency: 'EUR' } }],
    counterAccount: '1200',
  });
  return { tenant, ops, voucherId: voucher.voucherId as string };
}

function postBulk(tenant: Tenant, voucherId: string, count: number): void {
  for (let i = 0; i < count; i++) {
    tenant.ledger.post({
      entryDate: `2026-0${(i % 9) + 1}-15`,
      voucherId,
      text: 'Bulk posting',
      lines: [
        { account: '1200', side: 'debit', money: { amount: '119.00', currency: 'EUR' } },
        { account: '8400', side: 'credit', money: { amount: '100.00', currency: 'EUR' } },
        { account: '1776', side: 'credit', money: { amount: '19.00', currency: 'EUR' } },
      ],
    });
  }
}

describe('NF-6 — journal sequence integrity', () => {
  it('assigns unique, gapless, monotonic sequence numbers', () => {
    const { tenant, voucherId } = bulkTenant();
    postBulk(tenant, voucherId, 1000);

    const seqs = tenant.journal.all().map((entry) => entry.sequenceNumber);
    const contiguous = Array.from({ length: seqs.length }, (_, i) => i + 1);

    // journal.all() is ordered by (fiscalYear, sequenceNumber); all entries are in the
    // same fiscal year, so the numbers must be exactly 1..N — proves unique + gapless +
    // monotonic in one assertion.
    expect(seqs).toEqual(contiguous);
  });
});

describe('NF-7 — bulk performance', () => {
  it('handles 10k postings + trialBalance + cashBasisReport within budget', () => {
    const { tenant, ops, voucherId } = bulkTenant();

    const start = performance.now();
    postBulk(tenant, voucherId, 10_000);
    ops.project('trialBalance', { fiscalYear: 2026, throughPeriod: 12 });
    ops.project('cashBasisReport', { year: 2026, asOf: '2026-12-31' });
    const elapsedMs = performance.now() - start;

    expect(
      elapsedMs,
      `10k postings + trialBalance + cashBasisReport took ${elapsedMs.toFixed(0)}ms (budget 10000ms)`,
    ).toBeLessThan(10_000);
  });
});
