import { describe, expect, it } from 'vitest';
import {
  Currency,
  DeterministicIdGenerator,
  DomainError,
  FixedClock,
  Tenant,
  TenantOperations,
} from '../src/index.js';

/**
 * SPEC-004: the low-value-asset pool period is pack data, not core code.
 *
 * Until v0.6 the core wrote a pooled asset off over a hard-coded five years — one jurisdiction's
 * rule sitting in the law-free substrate, which every other jurisdiction with a pooled de-minimis
 * regime would have inherited without ever saying so. The conformance fixture `gwg-pool-period`
 * pins the same behaviour across both languages; this test covers the half a fixture cannot reach:
 * what happens when the rule data opens a pool range but forgets to say over how long.
 */
function tenantWith(threshold: Record<string, unknown>): TenantOperations {
  const clock = FixedClock.at('2026-06-08T12:00:00+02:00');
  const tenant = Tenant.inMemory('Pool', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  tenant.assetService.setRuleModule({
    gwgThresholds: [threshold],
    usefulLife: [{ assetClass: 'it-hardware', months: 36 }],
    assetAccounts: {
      acquisitionCounterAccount: '1200',
      depreciationExpenseAccount: '4830',
      gwgExpenseAccount: '4855',
    },
  });

  const ops = new TenantOperations(tenant);
  ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
  for (const account of [
    { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' },
    { number: '0480', name: 'Pool', type: 'asset', subtype: 'fixed_asset' },
    { number: '4830', name: 'Depreciation', type: 'expense' },
    { number: '4855', name: 'Low-value write-off', type: 'expense' },
  ]) {
    ops.execute('createAccount', account);
  }
  return ops;
}

function acquire(ops: TenantOperations): Record<string, unknown> {
  const voucher = ops.execute('createVoucher', {
    voucher: { voucherNumber: 'POOL-1', voucherDate: '2026-01-01' },
  }) as { id: string };

  return ops.execute('acquireAsset', {
    name: 'Pooled batch',
    assetClass: 'it-hardware',
    assetAccount: '0480',
    acquisitionCost: { amount: '900.00', currency: 'EUR' },
    acquiredOn: '2026-01-01',
    voucherId: voucher.id,
    gwgChoice: 'auto',
  });
}

const POOL_RANGE = { validFrom: '2018-01-01', validTo: null, immediateMax: '250.00', poolMin: '250.01', poolMax: '1000.00' };

describe('pool period comes from the pack', () => {
  it('spreads the asset over exactly the years the rule data declares', () => {
    const result = acquire(tenantWith({ ...POOL_RANGE, poolYears: 3 }));

    expect(result.route).toBe('pool');
    // 3 × 12, not the 60 months the core used to impose.
    expect(result.usefulLifeMonths).toBe(36);
  });

  it('refuses a pool range that does not say over how long', () => {
    // Not defaulted to five: choosing a number here would put a statute back into the core, which
    // is the finding itself. The schema requires the field next to poolMax, so this path is only
    // reachable with hand-fed rule data that never went through a pack.
    let raised: unknown = null;
    try {
      acquire(tenantWith(POOL_RANGE));
    } catch (error) {
      raised = error;
    }

    expect(raised).toBeInstanceOf(DomainError);
    expect((raised as DomainError).errorCode).toBe('E_PACK_INCOHERENT');
    expect((raised as DomainError).message).toContain('poolYears');
  });
});
