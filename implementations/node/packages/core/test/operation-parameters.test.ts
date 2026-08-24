import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  API_OPERATIONS,
  Currency,
  DeterministicIdGenerator,
  DomainError,
  FixedClock,
  OPERATION_PARAMETERS,
  Tenant,
  TenantOperations,
} from '../src/index.js';

/**
 * Drift guard for the operation input contract — the write-side twin of
 * `projection-parameters.test.ts` (F-9).
 *
 * Same reasoning, one level more serious: the core cannot read
 * `testing/testsuite/schema/api-parameters.json` (framework-free, no file I/O), so it carries the
 * table as a constant, and a copy nobody compares drifts. Where a drifted *projection* parameter
 * returns a wrong number, a drifted *operation* input writes one into the books.
 *
 * The second assertion is the one that keeps the guard honest: an operation missing from the table
 * is not validated at all, silently, because `validateOperationInput` leaves unknown names to the
 * dispatcher. Holding the table's key set against `API_OPERATIONS` means "not declared" cannot
 * quietly mean "not checked".
 */
const here = dirname(fileURLToPath(import.meta.url));
const schemaPath = join(here, '..', '..', '..', '..', '..', 'testing', 'testsuite', 'schema', 'api-parameters.json');

const schema = JSON.parse(readFileSync(schemaPath, 'utf8')) as {
  operations: Record<string, Record<string, Record<string, unknown>>>;
};

/** `$comment` is documentation, not contract — see the projection twin. */
function withoutComments(
  operations: Record<string, Record<string, Record<string, unknown>>>,
): Record<string, Record<string, Record<string, unknown>>> {
  return Object.fromEntries(
    Object.entries(operations).map(([name, params]) => [
      name,
      Object.fromEntries(
        Object.entries(params).map(([param, spec]) => [
          param,
          Object.fromEntries(Object.entries(spec).filter(([key]) => key !== '$comment')),
        ]),
      ),
    ]),
  );
}

function freshOps(): TenantOperations {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory('Inputs', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
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

describe('operation input contract', () => {
  it('declares exactly the operations the schema declares', () => {
    expect(Object.keys(OPERATION_PARAMETERS).sort()).toEqual(Object.keys(schema.operations).sort());
  });

  it('declares every input with the same type and flags as the schema', () => {
    expect(OPERATION_PARAMETERS).toEqual(withoutComments(schema.operations));
  });

  it('declares every operation the API publishes', () => {
    // An operation the table does not know is not validated, and nothing else would say so.
    expect(Object.keys(OPERATION_PARAMETERS).sort()).toEqual([...API_OPERATIONS].sort());
  });

  it('rejects an undeclared input instead of ignoring it', () => {
    // The real-world shapes. `usefulLifeYears` looks right, means nothing, and used to leave the
    // pack's lookup in charge of a useful life the caller thought they had set — summae's own
    // audit-trail test passed it for months. `role` on a partner is the same mistake: the field
    // is called `kind`.
    expect(errorCodeOf(() => freshOps().execute('acquireAsset', { usefulLifeYears: 5 }))).toBe('E_INPUT_INVALID');
    expect(errorCodeOf(() => freshOps().execute('createPartner', { name: 'Kunde AG', role: 'customer' }))).toBe(
      'E_INPUT_INVALID',
    );
  });

  it('rejects a numeric input passed as a string rather than ignoring it', () => {
    // A form posts "30", every handler read `typeof value === 'number'`, and the value was not
    // rejected but DROPPED — the documented default stood in its place and nothing was said.
    expect(errorCodeOf(() => freshOps().execute('createPartner', { name: 'X', paymentTermsDays: '30' }))).toBe(
      'E_INPUT_INVALID',
    );
    expect(errorCodeOf(() => freshOps().execute('runDepreciation', { fiscalYear: '2026' }))).toBe('E_INPUT_INVALID');
  });

  it('rejects an amount passed as a number rather than as Money', () => {
    // `proceeds: 2000` was read with an is-object check and silently became "no proceeds" — a
    // disposal booked as if the asset had been scrapped for nothing.
    expect(
      errorCodeOf(() => freshOps().execute('disposeAsset', { assetId: 'x', disposedOn: '2026-06-30', proceeds: 2000 })),
    ).toBe('E_INPUT_INVALID');
  });

  it('leaves an absent optional input to its documented default', () => {
    expect(errorCodeOf(() => freshOps().execute('runDepreciation', { fiscalYear: 2026, period: null }))).toBe(
      'NO_ERROR',
    );
  });

  it('leaves an unknown operation name to the dispatcher', () => {
    expect(errorCodeOf(() => freshOps().execute('doesNotExist', { whatever: 1 }))).toBe('E_NOT_IMPLEMENTED');
  });
});
