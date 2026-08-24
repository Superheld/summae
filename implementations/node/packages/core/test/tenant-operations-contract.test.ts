import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  API_OPERATIONS,
  API_PROJECTIONS,
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
  'releaseCosting', 'lockAccount', 'unlockAccount', 'importChartOfAccounts', 'importMapping',
  'writeDownAsset', 'bookSpecialDepreciation', 'reportAssetUsage',
  'defineDimensionType', 'defineDimensionValue', 'deactivatePartner', 'reactivatePartner',
] as const;

const PROJECTIONS = [
  'trialBalance', 'openItems', 'accountSheet', 'auditLog', 'unfinalizedEntries', 'assetRegister',
  'costAllocationSheet', 'ecSalesList', 'incomeStatement', 'balanceSheet', 'vatReturn',
  'cashJournal',
  'cashBasisReport', 'journalExport', 'datevExport', 'auditDataExport', 'systemDescription',
  'overheadRates', 'productionCost', 'accounts', 'fiscalYears', 'journal', 'costingRuns',
] as const;

/**
 * The dispatcher's own routing table, read off its source.
 *
 * The lists above are an oracle for "everything published resolves". They cannot answer the
 * other direction — that nothing resolves which is *not* published — because a `switch` has no
 * runtime shape to enumerate. So the test reads the file: `execute` routes everything up to
 * `project`, `project` everything after it, and each `case '<name>':` is one routed name.
 *
 * The direction matters. summae had seven routed names in neither published list
 * (`writeDownAsset`, `bookSpecialDepreciation`, `reportAssetUsage`, `defineDimensionType`,
 * `defineDimensionValue`, `overheadRates`, `productionCost`) — finished, documented, fixture-covered
 * capabilities that `systemDescription` did not admit to. An embedding app whose contract test holds
 * every call against the published list cannot call them at all, which is what an app reported. A
 * surface larger than its declaration passes a green suite in both languages as long as only one
 * direction is asked.
 */
function routedNames(): { operations: string[]; projections: string[] } {
  const source = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '..', 'src', 'composition', 'tenant-operations.ts'),
    'utf8',
  );
  // Bounded to the switch block itself, not to the rest of the file: a nested switch in a
  // helper below would otherwise read as a routed name.
  const cases = (head: string): string[] => {
    const start = source.indexOf(head);
    const end = source.indexOf('\n    }\n', start);
    return [...source.slice(start, end).matchAll(/case '([A-Za-z]+)':/g)].map((match) => match[1]!);
  };

  return { operations: cases('switch (op) {'), projections: cases('switch (name) {') };
}

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

describe('the published API surface equals the dispatcher surface', () => {
  // systemDescription publishes the operation and projection lists as part of the technical
  // system documentation (F-IO-007). The literal lists at the top of this file are an
  // independent oracle: they come from the API spec, not from the code. Comparing the two
  // means a name dropped from the published list and a `case` dropped from the dispatcher
  // cannot cancel each other out and leave the description quietly lying.
  it('publishes exactly the operations this contract pins', () => {
    expect([...API_OPERATIONS].sort()).toEqual([...OPERATIONS].sort());
  });

  it('publishes exactly the projections this contract pins', () => {
    expect([...API_PROJECTIONS].sort()).toEqual([...PROJECTIONS].sort());
  });

  it('publishes every operation the dispatcher routes', () => {
    const undeclared = routedNames().operations.filter((name) => !(API_OPERATIONS as readonly string[]).includes(name));
    expect(undeclared, 'a routed operation that systemDescription does not publish cannot be called by a caller that trusts the published list').toEqual([]);
  });

  it('publishes every projection the dispatcher routes', () => {
    const undeclared = routedNames().projections.filter((name) => !(API_PROJECTIONS as readonly string[]).includes(name));
    expect(undeclared, 'a routed projection that systemDescription does not publish is a surface larger than its declaration').toEqual([]);
  });

  it('reads the dispatcher source it claims to read', () => {
    // Without this, a renamed file or a switch turned into a lookup table would make both tests
    // above pass on an empty list — the guard would be gone and nothing would say so.
    const routed = routedNames();
    expect(routed.operations.length).toBe(API_OPERATIONS.length);
    expect(routed.projections.length).toBe(API_PROJECTIONS.length);
  });

  it('describes itself without parameters and names its own limits', () => {
    const description = freshOps().project('systemDescription', {}) as Record<string, unknown>;
    expect(description.formatVersion).toBeTypeOf('string');
    expect(Array.isArray(description.invariants)).toBe(true);
    expect((description.invariants as unknown[]).length).toBeGreaterThan(5);
    const notProvided = description.notProvided as string[];
    expect(notProvided.some((line) => line.includes('never verified'))).toBe(true);
    expect((description.auditTrail as Record<string, unknown>).actorIsAuthenticated).toBe(false);
  });
});
