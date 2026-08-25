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

/**
 * `$comment` is documentation, not contract — see the projection twin.
 *
 * Recursive since SPEC-017: a declaration nests now (`fields`, `element`), and a comment may sit at
 * any depth, because documentation belongs on the thing it concerns.
 */
function withoutComments(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(withoutComments);
  if (value === null || typeof value !== 'object') return value;
  return Object.fromEntries(
    Object.entries(value as Record<string, unknown>)
      .filter(([key]) => key !== '$comment')
      .map(([key, item]) => [key, withoutComments(item)]),
  );
}

/**
 * Every array or object input must say what is inside it — `fields`, `element`, or `opaque` with a
 * reason. Collects the ones that do not, at any depth.
 */
function undeclaredStructures(spec: Record<string, unknown>, path: string, out: string[]): void {
  const type = spec.type;
  if (type === 'array' || type === 'object') {
    const reason = spec.opaque;
    const hasInner = spec.fields !== undefined || spec.element !== undefined;
    if (!hasInner && !(typeof reason === 'string' && reason !== '')) out.push(path);
  }

  const fields = spec.fields;
  if (fields !== null && typeof fields === 'object') {
    for (const [key, field] of Object.entries(fields as Record<string, unknown>)) {
      if (field !== null && typeof field === 'object') {
        undeclaredStructures(field as Record<string, unknown>, `${path}.${key}`, out);
      }
    }
  }

  const element = spec.element;
  if (element !== null && typeof element === 'object') {
    undeclaredStructures(element as Record<string, unknown>, `${path}[]`, out);
  }
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

  /**
   * The guard that makes SPEC-017 stay closed. Declaring the element shapes once fixes today's
   * inputs; without this, the next array added to the contract would be structural and unchecked
   * again, and nothing would say so — which is exactly how the gap arose.
   */
  it('declares what is inside every structural input', () => {
    const undeclared: string[] = [];
    for (const [op, params] of Object.entries(schema.operations)) {
      for (const [name, spec] of Object.entries(params)) {
        undeclaredStructures(spec, `${op}.${name}`, undeclared);
      }
    }
    expect(
      undeclared,
      'an array or object input must declare `element`, `fields` or `opaque` with a reason — ' +
        'otherwise it is structural and silent, which is SPEC-017',
    ).toEqual([]);
  });

  /**
   * The rule reaches all the way down, not one level. `dimension` instead of `dimensions` inside a
   * net line is the reported case: accepted, booked correctly, cost centre gone, no error.
   */
  it('rejects an undeclared key inside a structure', () => {
    expect(
      errorCodeOf(() =>
        freshOps().execute('post', {
          voucherId: 'v',
          entryDate: '2026-01-01',
          lines: [{ account: '1200', side: 'debit', dimension: [] }],
        }),
      ),
    ).toBe('E_INPUT_INVALID');

    expect(
      errorCodeOf(() =>
        freshOps().execute('post', {
          voucherId: 'v',
          entryDate: '2026-01-01',
          lines: [{ account: '1200', dimensions: [{ type: 'costCenter', kode: 'A' }] }],
        }),
      ),
    ).toBe('E_INPUT_INVALID');
  });

  it('rejects a wrongly typed value inside a structure', () => {
    expect(
      errorCodeOf(() =>
        freshOps().execute('post', {
          voucherId: 'v',
          entryDate: '2026-01-01',
          lines: [{ account: '1200', money: 1200 }],
        }),
      ),
    ).toBe('E_INPUT_INVALID');
  });

  it('accepts anything inside an opaque structure', () => {
    expect(
      errorCodeOf(() =>
        freshOps().execute('createPartner', {
          name: 'Kunde AG',
          kind: 'customer',
          address: { street: 'Hauptstr. 1', whatever: { deep: true } },
        }),
      ),
    ).not.toBe('E_INPUT_INVALID');
  });

  it('leaves an unknown operation name to the dispatcher', () => {
    expect(errorCodeOf(() => freshOps().execute('doesNotExist', { whatever: 1 }))).toBe('E_NOT_IMPLEMENTED');
  });
});
