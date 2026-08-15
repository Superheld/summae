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
 * Cross-language pin for the `details` payload of a rejected input.
 *
 * The conformance suite compares the error CODE and nothing else, so two implementations can
 * agree on every fixture and still hand a caller different payloads — which is what happened
 * when these guards were built: a rejected object was reported as `"[object Object]"` here and
 * as `null` on the PHP side, `true` as `"true"` against `"1"`. The rule both languages follow
 * now is the one canonical JSON already draws: strings and safe integers are echoed back,
 * everything else is dropped to null rather than rendered by a cast that differs by language.
 *
 * The SAME table lives in the PHP InputRejectionTest. If one language starts rendering a value
 * differently, that language goes red here.
 */
type Case = {
  readonly label: string;
  readonly value: unknown;
  readonly detail: string | null;
  /** 123 is a perfectly good year — that case belongs to the two `kind` guards only. */
  readonly validYear?: true;
};

const REJECTED: readonly Case[] = [
  { label: 'a string is echoed back so the caller sees their own typo', value: 'bogus', detail: 'bogus' },
  { label: 'a whole number renders the same in both languages', value: 123, detail: '123', validYear: true },
  { label: 'a negative whole number likewise', value: -5, detail: '-5' },
  // Below: everything a plain cast would have rendered differently.
  { label: 'true is "true" here and "1" in PHP — dropped', value: true, detail: null },
  { label: 'false is "false" here and "" in PHP — dropped', value: false, detail: null },
  { label: 'a fractional number is not exactly representable — dropped', value: 2028.5, detail: null },
  { label: 'beyond 2^53 this is no longer an exact integer — dropped', value: 1e21, detail: null },
  { label: 'an object would be "[object Object]" — dropped', value: {}, detail: null },
  { label: 'an array likewise', value: [1, 2], detail: null },
  { label: 'null carries nothing', value: null, detail: null },
];

function freshOps(): TenantOperations {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory('Rejection', Currency.of('EUR'), clock, new DeterministicIdGenerator(clock));
  return new TenantOperations(tenant);
}

function rejectionOf(call: () => unknown): { code: string; details: Record<string, unknown> } {
  try {
    call();
  } catch (error) {
    if (error instanceof DomainError) {
      return { code: error.errorCode, details: error.details };
    }
    throw error;
  }
  throw new Error('expected a rejection, the call succeeded');
}

describe('rejected input reports the same details in both languages', () => {
  describe('createFiscalYear "year"', () => {
    // `null` is "absent" for year, and absent is still a rejection (there is no default year).
    for (const { label, value, detail } of REJECTED.filter((c) => c.validYear !== true)) {
      it(label, () => {
        const { code, details } = rejectionOf(() =>
          freshOps().execute('createFiscalYear', { year: value, start: '2027-01-01', end: '2027-12-31' }),
        );
        expect(code).toBe('E_INPUT_INVALID');
        expect(details.year).toBe(detail);
      });
    }
  });

  describe('openItems "kind"', () => {
    // null/undefined mean "no filter" here, so only the values that are present but wrong apply.
    for (const { label, value, detail } of REJECTED.filter((c) => c.value !== null)) {
      it(label, () => {
        const { code, details } = rejectionOf(() => freshOps().project('openItems', { kind: value }));
        expect(code).toBe('E_INPUT_INVALID');
        expect(details.kind).toBe(detail);
      });
    }
  });

  describe('datevExport "kind"', () => {
    for (const { label, value, detail } of REJECTED.filter((c) => c.value !== null)) {
      it(label, () => {
        const { code, details } = rejectionOf(() =>
          freshOps().project('datevExport', { kind: value, fiscalYear: 2026 }),
        );
        expect(code).toBe('E_INPUT_INVALID');
        expect(details.kind).toBe(detail);
      });
    }
  });
});
