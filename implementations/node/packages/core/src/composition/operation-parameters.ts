import { DomainError, rejectedValue } from '../domain-error.js';
import { matchesParameterType } from '../policies/projection/parameters.js';
import type { ParameterSpec } from './projection-parameters.js';

/**
 * The input contract of the operations, as data — the write-side twin of
 * `PROJECTION_PARAMETERS` (F-9).
 *
 * The asymmetry this closes was the wrong way round for a long time. Every projection parameter
 * was declared and an undeclared one was `E_INPUT_INVALID` naming the parameter; `execute()` read
 * what it recognised out of the input and ignored the rest. So a typo in a *read* failed loudly
 * and a typo in a *write* was silent — and a write is the one that ends up in the books.
 *
 * Three defects had already come out of that silence: `expandTax` takes `date` where its
 * neighbours take `voucherDate`/`entryDate`, so the wrong name yielded a tax-version error naming
 * a field that was supplied; a wrong `direction` fell through to a default and booked an incoming
 * invoice inverted (IMPL-013, hardened by hand afterwards); and every numeric input was read with
 * a `typeof value === 'number'` check, so a form's `"30"` was not rejected but *ignored*, and the
 * documented default stood in its place.
 *
 * Same table, same source, same drift test as the projections: this is a hand-kept copy of
 * `testing/testsuite/schema/api-parameters.json` (the core reads no files by design) and
 * `operation-parameters.test.ts` asserts the two are equal, in both languages.
 *
 * **What it does not do: enforce `required`.** The flag is declared, and the check stays where it
 * is. An operation missing its subject already fails, and it fails with a better code than this
 * layer could give — `E_VOUCHER_UNKNOWN`, `E_ASSET_UNKNOWN`, `E_ENTRY_NO_VOUCHER` say what is
 * missing; a central `E_INPUT_INVALID` would say less and would overwrite error codes the fixtures
 * pin. The finding is about inputs that are *present and wrong*, and that is what this catches.
 */
export const OPERATION_PARAMETERS: Readonly<Record<string, Readonly<Record<string, ParameterSpec>>>> = {
  post: {
    voucherId: { type: 'string', required: true },
    entryDate: { type: 'date', required: true },
    lines: { type: 'array', required: true },
    text: { type: 'string' },
    actor: { type: 'string' },
  },
  postVoucher: {
    voucher: { type: 'object', required: true },
    taxCode: { type: 'string' },
    direction: { type: 'string' },
    netLines: { type: 'array' },
    lines: { type: 'array' },
    counterAccount: { type: 'string' },
    entryDate: { type: 'date' },
    text: { type: 'string' },
    actor: { type: 'string' },
  },
  createVoucher: {
    voucher: { type: 'object', required: true },
    actor: { type: 'string' },
  },
  correct: {
    entryId: { type: 'string', required: true },
    text: { type: 'string' },
    lines: { type: 'array' },
    actor: { type: 'string' },
  },
  finalize: {
    entryId: { type: 'string' },
    finalizeUntil: { type: 'date' },
    actor: { type: 'string' },
  },
  reverse: {
    entryId: { type: 'string', required: true },
    entryDate: { type: 'date', required: true },
    text: { type: 'string' },
    voucherId: { type: 'string' },
    actor: { type: 'string' },
  },
  settle: {
    entryId: { type: 'string', required: true },
    allocations: { type: 'array', required: true },
    actor: { type: 'string' },
  },
  createAccount: {
    number: { type: 'string', required: true },
    name: { type: 'string', required: true },
    type: { type: 'string', required: true },
    subtype: { type: 'string' },
    status: { type: 'string' },
    actor: { type: 'string' },
  },
  lockAccount: {
    number: { type: 'string', required: true },
    actor: { type: 'string' },
  },
  unlockAccount: {
    number: { type: 'string', required: true },
    actor: { type: 'string' },
  },
  importChartOfAccounts: {
    rows: { type: 'array', required: true },
    format: { type: 'string', acceptedWithoutEffect: true },
    actor: { type: 'string' },
  },
  defineDimensionType: {
    code: { type: 'string', required: true },
    actor: { type: 'string' },
  },
  defineDimensionValue: {
    type: { type: 'string', required: true },
    code: { type: 'string', required: true },
    actor: { type: 'string' },
  },
  createFiscalYear: {
    year: { type: 'integer', required: true },
    start: { type: 'date', required: true },
    end: { type: 'date', required: true },
    actor: { type: 'string' },
  },
  closePeriod: {
    fiscalYear: { type: 'integer', required: true },
    period: { type: 'integer', required: true },
    actor: { type: 'string' },
  },
  reopenPeriod: {
    fiscalYear: { type: 'integer', required: true },
    period: { type: 'integer', required: true },
    actor: { type: 'string' },
  },
  closeFiscalYear: {
    fiscalYear: { type: 'integer', required: true },
    actor: { type: 'string' },
  },
  expandTax: {
    date: { type: 'date', required: true },
    serviceDate: { type: 'date' },
    direction: { type: 'string' },
    taxCode: { type: 'string' },
    netLines: { type: 'array', required: true },
  },
  setTaxProfile: {
    smallBusiness: { type: 'object', required: true },
    reason: { type: 'string', acceptedWithoutEffect: true },
    actor: { type: 'string' },
  },
  importMapping: {
    mapping: { type: 'object', required: true },
    actor: { type: 'string' },
  },
  createPartner: {
    name: { type: 'string', required: true },
    kind: { type: 'string' },
    vatId: { type: 'string' },
    paymentTermsDays: { type: 'integer' },
    accountNumbers: { type: 'array' },
    address: { type: 'object' },
    actor: { type: 'string' },
  },
  updatePartner: {
    partnerId: { type: 'string', required: true },
    name: { type: 'string' },
    kind: { type: 'string' },
    vatId: { type: 'string' },
    paymentTermsDays: { type: 'integer' },
    accountNumbers: { type: 'array' },
    address: { type: 'object' },
    actor: { type: 'string' },
  },
  acquireAsset: {
    name: { type: 'string' },
    assetClass: { type: 'string' },
    assetAccount: { type: 'string', required: true },
    acquisitionCost: { type: 'money', required: true },
    acquiredOn: { type: 'date', required: true },
    voucherId: { type: 'string', required: true },
    gwgChoice: { type: 'string' },
    usefulLifeMonths: { type: 'integer' },
    depreciationMethod: { type: 'string' },
    dimensions: { type: 'array' },
    specialDepreciation: { type: 'boolean' },
    totalUnits: { type: 'integer' },
    actor: { type: 'string' },
  },
  disposeAsset: {
    assetId: { type: 'string', required: true },
    disposedOn: { type: 'date', required: true },
    proceeds: { type: 'money' },
    proceedsAccount: { type: 'string' },
    bankAccount: { type: 'string' },
    voucherId: { type: 'string' },
    actor: { type: 'string' },
  },
  runDepreciation: {
    fiscalYear: { type: 'integer', required: true },
    period: { type: 'integer' },
    actor: { type: 'string' },
  },
  reportAssetUsage: {
    assetId: { type: 'string', required: true },
    fiscalYear: { type: 'integer', required: true },
    units: { type: 'integer', required: true },
    voucherId: { type: 'string' },
    actor: { type: 'string' },
  },
  bookSpecialDepreciation: {
    assetId: { type: 'string', required: true },
    fiscalYear: { type: 'integer', required: true },
    amount: { type: 'money', required: true },
    voucherId: { type: 'string' },
    actor: { type: 'string' },
  },
  writeDownAsset: {
    assetId: { type: 'string', required: true },
    amount: { type: 'money', required: true },
    date: { type: 'date', required: true },
    reason: { type: 'string', required: true },
    voucherId: { type: 'string' },
    actor: { type: 'string' },
  },
  setAllocationScheme: {
    method: { type: 'string' },
    steps: { type: 'array' },
    rates: { type: 'array' },
    productionCost: { type: 'object' },
    actor: { type: 'string' },
  },
  runCosting: {
    fiscalYear: { type: 'integer', required: true },
    period: { type: 'integer', required: true },
    actor: { type: 'string' },
  },
  releaseCosting: {
    runId: { type: 'string', required: true },
    actor: { type: 'string' },
  },
  allocate: {
    total: { type: 'money', required: true },
    weights: { type: 'array', required: true },
  },
};

/**
 * Checks an operation's input against the contract — once, at the dispatcher, instead of inside
 * 31 handlers.
 *
 * The rules are the projections' rules, deliberately: an undeclared key is a caller mistake
 * rather than something to ignore, and a declared key present with the wrong type is rejected
 * rather than coerced. `null` counts as absent, because JSON callers and CLI wrappers write an
 * omitted input both ways and every handler has read it that way since long before this existed.
 *
 * An operation the table does not know is left alone: an unknown name is the dispatcher's own
 * error (`E_NOT_IMPLEMENTED`) and must not be reported as an input problem on the way there.
 * `operation-parameters.test.ts` holds the table's key set against `API_OPERATIONS`, so "not
 * known here" cannot quietly mean "not checked".
 */
export function validateOperationInput(op: string, input: Record<string, unknown>): void {
  const declared = OPERATION_PARAMETERS[op];
  if (declared === undefined) {
    return;
  }

  for (const [key, value] of Object.entries(input)) {
    const spec = declared[key];
    if (spec === undefined) {
      throw new DomainError('E_INPUT_INVALID', `${op}: unknown input "${key}"`, { input: key });
    }
    if (value === undefined || value === null) {
      continue;
    }
    if (!matchesParameterType(value, spec.type)) {
      throw new DomainError('E_INPUT_INVALID', `${op}: input "${key}" must be of type ${spec.type}`, {
        [key]: rejectedValue(value),
      });
    }
  }
}
