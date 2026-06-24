import { describe, expect, it } from 'vitest';
import {
  Currency,
  DeterministicIdGenerator,
  FixedClock,
  Tenant,
  TenantOperations,
} from '../src/index.js';

/**
 * #32: the AICPA Audit Data Standard (GL) export — the US counterpart to journalExport (GoBD-Z3).
 * Verifies the three ADS streams and the signed-amount convention (debit +, credit −).
 */
describe('auditDataExport (AICPA ADS GL)', () => {
  function tenantWithOneEntry(): TenantOperations {
    const clock = FixedClock.at('2026-06-08T12:00:00+02:00');
    const tenant = Tenant.inMemory('ADS', Currency.of('USD'), clock, new DeterministicIdGenerator(clock));
    const ops = new TenantOperations(tenant);
    ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
    ops.execute('createAccount', { number: '1010', name: 'Cash', type: 'asset', subtype: 'bank' });
    ops.execute('createAccount', { number: '4000', name: 'Sales Revenue', type: 'revenue' });
    const voucher = ops.execute('createVoucher', { voucher: { voucherNumber: 'JE-1', voucherDate: '2026-01-15' } });
    ops.execute('post', {
      entryDate: '2026-01-15',
      voucherId: voucher.id as string,
      text: 'Cash sale',
      lines: [
        { account: '1010', side: 'debit', money: { amount: '100.00', currency: 'USD' } },
        { account: '4000', side: 'credit', money: { amount: '100.00', currency: 'USD' } },
      ],
    });
    return ops;
  }

  it('emits the three ADS streams with signed line amounts', () => {
    const result = tenantWithOneEntry().project('auditDataExport', { fiscalYear: 2026 });

    expect(result.standard).toBe('aicpa-ads-gl');
    expect(result.currency).toBe('USD');

    const journals = result.journals as Array<Record<string, unknown>>;
    expect(journals).toHaveLength(1);
    expect(journals[0]!.effectiveDate).toBe('2026-01-15');
    expect(journals[0]!.source).toBe('JE-1');

    const lines = journals[0]!.glLineItems as Array<Record<string, unknown>>;
    expect(lines).toHaveLength(2);
    const byAccount = Object.fromEntries(lines.map((l) => [l.glAccountNumber, l.transactionAmount]));
    expect(byAccount['1010']).toBe('100.00'); // debit positive
    expect(byAccount['4000']).toBe('-100.00'); // credit negative
    expect(lines[0]!.transactionCurrency).toBe('USD');

    const tb = result.trialBalance as Array<Record<string, unknown>>;
    const tbByAccount = Object.fromEntries(tb.map((r) => [r.glAccountNumber, r.amountEnding]));
    expect(tbByAccount['1010']).toBe('100.00');
    expect(tbByAccount['4000']).toBe('-100.00');

    const accounts = result.accounts as Array<Record<string, unknown>>;
    const acctByNumber = Object.fromEntries(accounts.map((a) => [a.glAccountNumber, a]));
    expect(acctByNumber['1010']).toMatchObject({ glAccountName: 'Cash', accountType: 'asset', accountSubtype: 'bank' });
    expect(acctByNumber['4000']).toMatchObject({ glAccountName: 'Sales Revenue', accountType: 'revenue' });
  });
});
