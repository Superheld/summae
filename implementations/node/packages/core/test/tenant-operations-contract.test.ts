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
 * Contract test for the dispatcher surface (TenantOperations). The runner's behavioral
 * fixtures exercise individual operations with valid input, but they do NOT pin the
 * contract: that every operation/projection named in the API spec resolves to a handler,
 * that an unknown name maps to the defined error, and — across languages — that the
 * surface is identical. A routing gap (a misspelled `case`, a dropped op, PHP/Node drift)
 * must fail loudly here. The SAME two lists live in the PHP TenantOperationsContractTest;
 * if one language's dispatcher drops or renames a case, that language's test goes red.
 */
const OPERATIONS = [
  'expandTax', 'setTaxProfile', 'postVoucher', 'createVoucher', 'post', 'correct',
  'finalize', 'reverse', 'settle', 'closePeriod', 'reopenPeriod', 'closeFiscalYear',
  'createAccount', 'createFiscalYear', 'createPartner', 'updatePartner', 'acquireAsset',
  'disposeAsset', 'runDepreciation', 'allocate', 'setAllocationScheme', 'runCosting',
  'releaseCosting', 'lockAccount', 'importChartOfAccounts', 'importMapping',
] as const;

const PROJECTIONS = [
  'trialBalance', 'openItems', 'accountSheet', 'auditLog', 'assetRegister',
  'costAllocationSheet', 'ecSalesList', 'incomeStatement', 'balanceSheet', 'vatReturn',
  'cashBasisReport', 'journalExport', 'datevExport',
] as const;

function freshOps(): TenantOperations {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory('Contract', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  return new TenantOperations(tenant);
}

// "Resolved to a handler" = the dispatcher did NOT fall through to its E_NOT_IMPLEMENTED
// default. The handler may still reject the empty input with a different error — that
// proves routing worked, which is exactly what this contract pins (not input behavior).
function routesToHandler(call: () => unknown): boolean {
  try {
    call();
    return true;
  } catch (error) {
    return !(error instanceof DomainError && error.errorCode === 'E_NOT_IMPLEMENTED');
  }
}

describe('TenantOperations contract surface', () => {
  it('routes every documented operation to a handler', () => {
    const gaps = OPERATIONS.filter((op) => !routesToHandler(() => freshOps().execute(op, {})));
    expect(gaps, 'every API-spec operation must resolve to a handler').toEqual([]);
  });

  it('routes every documented projection to a handler', () => {
    const gaps = PROJECTIONS.filter((name) => !routesToHandler(() => freshOps().project(name, {})));
    expect(gaps, 'every API-spec projection must resolve to a handler').toEqual([]);
  });

  it('maps an unknown operation to E_NOT_IMPLEMENTED', () => {
    try {
      freshOps().execute('noSuchOperation', {});
      throw new Error('expected a throw');
    } catch (error) {
      expect(error).toBeInstanceOf(DomainError);
      expect((error as DomainError).errorCode).toBe('E_NOT_IMPLEMENTED');
    }
  });

  it('maps an unknown projection to E_NOT_IMPLEMENTED', () => {
    try {
      freshOps().project('noSuchProjection', {});
      throw new Error('expected a throw');
    } catch (error) {
      expect(error).toBeInstanceOf(DomainError);
      expect((error as DomainError).errorCode).toBe('E_NOT_IMPLEMENTED');
    }
  });
});
