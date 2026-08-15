import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  Currency,
  DeterministicIdGenerator,
  DomainError,
  FixedClock,
  PROJECTION_PARAMETERS,
  Tenant,
  TenantOperations,
} from '../src/index.js';

/**
 * Drift guard for the parameter contract.
 *
 * `testing/testsuite/schema/api-parameters.json` is the normative source; the core cannot read
 * it (framework-free, no file I/O), so it carries the same table as a constant. A copy nobody
 * compares is a copy that drifts — a parameter added to the schema and forgotten in the code
 * would be rejected as "unknown" by the very implementation that is supposed to accept it, and
 * nothing would say so. This test is the comparison, and its PHP twin (`ProjectionParametersTest`)
 * makes the same assertion on the same file, so the two languages cannot drift apart either.
 */
const here = dirname(fileURLToPath(import.meta.url));
const schemaPath = join(here, '..', '..', '..', '..', '..', 'testing', 'testsuite', 'schema', 'api-parameters.json');

const schema = JSON.parse(readFileSync(schemaPath, 'utf8')) as {
  projections: Record<string, Record<string, Record<string, unknown>>>;
};

function freshOps(): TenantOperations {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory('Parameters', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  return new TenantOperations(tenant);
}

function errorCodeOf(call: () => unknown): string {
  try {
    call();
  } catch (error) {
    return error instanceof DomainError ? error.errorCode : 'NOT_A_DOMAIN_ERROR';
  }
  return 'NO_ERROR';
}

describe('projection parameter contract', () => {
  it('declares exactly the projections the schema declares', () => {
    expect(Object.keys(PROJECTION_PARAMETERS).sort()).toEqual(Object.keys(schema.projections).sort());
  });

  it('declares every parameter with the same type and flags as the schema', () => {
    expect(PROJECTION_PARAMETERS).toEqual(schema.projections);
  });

  it('rejects an undeclared parameter instead of ignoring it', () => {
    // The real-world shape: vatReturn takes year/quarter, and `fiscalYear` used to be swallowed,
    // so a caller asking for a quarter got the whole year back without a word.
    const error = errorCodeOf(() => freshOps().project('vatReturn', { year: 2026, fiscalYear: 2026 }));
    expect(error).toBe('E_INPUT_INVALID');
  });

  it('rejects a declared parameter of the wrong type', () => {
    expect(errorCodeOf(() => freshOps().project('trialBalance', { fiscalYear: 2026.4 }))).toBe('E_INPUT_INVALID');
    expect(errorCodeOf(() => freshOps().project('trialBalance', { fiscalYear: '2026' }))).toBe('E_INPUT_INVALID');
    expect(errorCodeOf(() => freshOps().project('trialBalance', { fiscalYear: 2026, includeZeroBalances: 'yes' }))).toBe(
      'E_INPUT_INVALID',
    );
  });

  it('accepts a whole number written with a decimal point — JSON cannot tell them apart', () => {
    expect(errorCodeOf(() => freshOps().project('trialBalance', { fiscalYear: 2026.0 }))).toBe('NO_ERROR');
  });

  it('leaves an absent optional parameter to its documented default', () => {
    expect(errorCodeOf(() => freshOps().project('trialBalance', { fiscalYear: 2026 }))).toBe('NO_ERROR');
    expect(errorCodeOf(() => freshOps().project('trialBalance', { fiscalYear: 2026, throughPeriod: null }))).toBe(
      'NO_ERROR',
    );
  });

  it('leaves an unknown projection name to the dispatcher', () => {
    // Validation must not answer "unknown parameter" for a projection that does not exist —
    // the routing error is the more specific one and has to win.
    expect(errorCodeOf(() => freshOps().project('doesNotExist', { whatever: 1 }))).toBe('E_NOT_IMPLEMENTED');
  });
});
