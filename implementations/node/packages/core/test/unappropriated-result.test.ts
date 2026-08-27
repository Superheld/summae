import { describe, expect, it } from 'vitest';
import { Tenant } from '../src/composition/tenant.js';
import { TenantOperations } from '../src/composition/tenant-operations.js';
import { Currency } from '../src/substrate/currency.js';
import { FixedClock } from '../src/substrate/clock.js';
import { DeterministicIdGenerator } from '../src/substrate/id-generator.js';

/**
 * The loss half of `unappropriatedResult`, which the fixtures do not reach.
 *
 * Every appropriation fixture appropriates a profit, because that is what a resolution normally
 * does. The direction rule — the pot decides, the year figure only sizes it (IMPL-033) — is
 * symmetrical, and the cases where it earns its keep are the ones where a year and the pot point
 * different ways. Those are cheaper to build here than as fixtures.
 *
 * The SAME cases live in the PHP UnappropriatedResultTest.
 */
function tenantWithYears(): TenantOperations {
  const clock = FixedClock.at('2028-01-02T09:00:00+01:00');
  const tenant = Tenant.inMemory('Verlust GmbH', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  const ops = new TenantOperations(tenant);

  for (const year of [2026, 2027]) {
    ops.execute('createFiscalYear', { year, start: `${year}-01-01`, end: `${year}-12-31` });
  }
  ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
  ops.execute('createAccount', { number: '2100', name: 'Gewinnvortrag', type: 'equity' });
  ops.execute('createAccount', { number: '2300', name: 'Ergebnisverwendung', type: 'equity', subtype: 'result_allocation' });
  ops.execute('createAccount', { number: '4040', name: 'Erlöse', type: 'revenue' });
  ops.execute('createAccount', { number: '6000', name: 'Aufwand', type: 'expense' });
  tenant.resultAppropriation.setRuleModule({
    resultAppropriation: {
      allocationAccount: '2300',
      targets: { carryForward: { account: '2100', label: 'Verlustvortrag' } },
    },
  });

  return ops;
}

function book(ops: TenantOperations, date: string, account: string, side: 'debit' | 'credit', amount: string): void {
  const voucher = ops.execute('createVoucher', {
    voucher: { voucherNumber: `B-${date}-${account}`, voucherDate: date },
  }) as { id: string };
  ops.execute('post', {
    entryDate: date,
    voucherId: voucher.id,
    text: 'Buchung',
    lines: [
      { account, side, money: { amount, currency: 'EUR' } },
      { account: '1200', side: side === 'debit' ? 'credit' : 'debit', money: { amount, currency: 'EUR' } },
    ],
  });
}

describe('unappropriatedResult with a loss', () => {
  it('reports the loss negative and caps a year by what it lost', () => {
    const ops = tenantWithYears();
    book(ops, '2026-06-01', '6000', 'debit', '500.00');
    book(ops, '2027-06-01', '6000', 'debit', '400.00');

    const report = ops.project('unappropriatedResult', {}) as {
      cumulativeResult: string;
      unappropriated: string;
      byFiscalYear: Array<{ fiscalYear: number; available: string }>;
    };

    expect(report.cumulativeResult).toBe('-900.00');
    expect(report.unappropriated).toBe('-900.00');
    expect(report.byFiscalYear.map((row) => [row.fiscalYear, row.available])).toEqual([
      [2026, '-500.00'],
      [2027, '-900.00'],
    ]);
  });

  it('books a loss the other way round and leaves the rest', () => {
    const ops = tenantWithYears();
    book(ops, '2026-06-01', '6000', 'debit', '500.00');
    const voucher = ops.execute('createVoucher', {
      voucher: { voucherNumber: 'BESCHLUSS-1', voucherDate: '2027-05-20', kind: 'internal' },
    }) as { id: string };

    const result = ops.execute('appropriateResult', {
      fiscalYear: 2026,
      entryDate: '2027-05-20',
      voucherId: voucher.id,
      appropriations: [{ target: 'carryForward', money: { amount: '300.00', currency: 'EUR' } }],
    }) as { remaining: string; entry: { lines: Array<{ account: string; side: string }> } };

    // Positive amount in, and the direction comes from the books: the allocation account in credit.
    expect(result.entry.lines[0]).toMatchObject({ account: '2300', side: 'credit' });
    expect(result.remaining).toBe('-200.00');
    expect((ops.project('unappropriatedResult', {}) as { unappropriated: string }).unappropriated).toBe('-200.00');
  });

  it('offers nothing for a year whose profit a later loss has swallowed', () => {
    const ops = tenantWithYears();
    book(ops, '2026-06-01', '4040', 'credit', '900.00');
    book(ops, '2027-06-01', '6000', 'debit', '1400.00');

    const report = ops.project('unappropriatedResult', {}) as {
      unappropriated: string;
      byFiscalYear: Array<{ fiscalYear: number; available: string }>;
    };

    // The pot is a loss of 500. 2026 earned a profit, so it contributes nothing to appropriate —
    // the loss arose in 2027 and has to be resolved naming 2027.
    expect(report.unappropriated).toBe('-500.00');
    expect(report.byFiscalYear.map((row) => [row.fiscalYear, row.available])).toEqual([
      [2026, '0.00'],
      [2027, '-500.00'],
    ]);
  });
});
