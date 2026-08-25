import { DomainError, rejectedValue } from '../domain-error.js';
import { matchesParameterType, type ParameterType } from '../policies/projection/parameters.js';

/** What the contract says about one parameter. */
export interface ParameterSpec {
  readonly type: ParameterType;
  /** Declared for completeness; enforcing it is a separate step (see validateProjectionParams). */
  readonly required?: true;
  /** Passed by existing fixtures, read by no implementation — declared so the gap is visible. */
  readonly acceptedWithoutEffect?: true;
}

/**
 * The parameter contract of the projections, as data.
 *
 * This table is a hand-kept copy of `testing/testsuite/schema/api-parameters.json`, the
 * normative source. The core reads no files by design (framework-free, no I/O), so the table
 * has to live in the code — and a copy that nothing compares drifts. `projection-parameters.test.ts`
 * (PHP: `ProjectionParametersTest`) asserts this constant equals that file, in both languages.
 * Change the schema in the knowledge base, mirror it, and the tests tell you what to change here.
 */
export const PROJECTION_PARAMETERS: Readonly<Record<string, Readonly<Record<string, ParameterSpec>>>> = {
  trialBalance: {
    fiscalYear: { type: 'integer', required: true },
    throughPeriod: { type: 'integer' },
    includeZeroBalances: { type: 'boolean' },
  },
  accountSheet: {
    account: { type: 'string', required: true },
    fiscalYear: { type: 'integer', required: true },
    throughPeriod: { type: 'integer' },
  },
  balanceSheet: {
    mapping: { type: 'string', required: true },
    fiscalYear: { type: 'integer' },
    asOf: { type: 'date' },
    incomeMapping: { type: 'string', acceptedWithoutEffect: true },
  },
  incomeStatement: {
    mapping: { type: 'string', required: true },
    fiscalYear: { type: 'integer', required: true },
    fromPeriod: { type: 'integer' },
    throughPeriod: { type: 'integer' },
  },
  cashBasisReport: {
    year: { type: 'integer', required: true },
    mapping: { type: 'string' },
    asOf: { type: 'date' },
  },
  vatReturn: {
    year: { type: 'integer', required: true },
    quarter: { type: 'integer' },
    month: { type: 'integer' },
    asOf: { type: 'date' },
  },
  ecSalesList: {
    year: { type: 'integer', required: true },
    quarter: { type: 'integer' },
  },
  openItems: {
    kind: { type: 'string' },
    partnerId: { type: 'string' },
    asOf: { type: 'date' },
  },
  assetRegister: {
    asOf: { type: 'date' },
  },
  // Filters combine with AND; an absent one filters nothing. The four object filters arrived with
  // F-CORE-036: the auditor's question is about ONE thing, and `from`/`to` alone forced the caller
  // to transport the whole trail in order to discard most of it.
  auditLog: {
    from: { type: 'date' },
    to: { type: 'date' },
    objectType: { type: 'string' },
    objectId: { type: 'string' },
    actor: { type: 'string' },
    action: { type: 'string' },
    offset: { type: 'integer' },
    limit: { type: 'integer' },
  },
  unfinalizedEntries: {
    asOf: { type: 'date' },
    olderThanDays: { type: 'integer' },
    fiscalYear: { type: 'integer' },
  },
  // Takes no parameters: the description is a property of the software, not of a query.
  systemDescription: {},
  // Takes no parameters either, and the reason is the same shape: the chart is what it is. A
  // filter would only save the caller a `filter()` while making "which accounts exist" a question
  // with more than one answer.
  accounts: {},
  journal: {
    fiscalYear: { type: 'integer', required: true },
    fromDate: { type: 'date' },
    toDate: { type: 'date' },
    offset: { type: 'integer' },
    limit: { type: 'integer' },
  },
  fiscalYears: {
    fiscalYear: { type: 'integer' },
  },
  cashJournal: {
    fiscalYear: { type: 'integer', required: true },
  },
  journalExport: {
    fiscalYear: { type: 'integer', required: true },
    format: { type: 'string' },
  },
  datevExport: {
    kind: { type: 'string' },
    fiscalYear: { type: 'integer' },
    fromPeriod: { type: 'integer' },
    throughPeriod: { type: 'integer' },
  },
  auditDataExport: {
    fiscalYear: { type: 'integer', required: true },
    asOf: { type: 'date' },
  },
  costAllocationSheet: {
    runId: { type: 'string', required: true },
    fiscalYear: { type: 'integer' },
    period: { type: 'integer' },
  },
  costingRuns: {
    fiscalYear: { type: 'integer' },
    period: { type: 'integer' },
  },
  overheadRates: {
    runId: { type: 'string', required: true },
  },
  productionCost: {
    runId: { type: 'string', required: true },
  },
  // No parameters, for the third time and the same reason as systemDescription and accounts: a
  // tenant has exactly one configuration. There is nothing to select, and a filter would turn
  // "what is this tenant set up as" into a question with more than one answer.
  tenantConfiguration: {},
};

/**
 * Checks a projection's params against the contract — once, at the dispatcher, instead of
 * 25 times inside the projections.
 *
 * Two rules, both about telling "absent" from "wrong":
 *   - a parameter that is not declared is a caller mistake, not something to ignore. Ignoring
 *     it turned a misspelled `fiscalYear` on vatReturn into a plausible ANNUAL figure where a
 *     quarter was asked for, and `includeZeroBalance` into a flag that did nothing.
 *   - a declared parameter present with the wrong type is rejected, never coerced. `2026.4` is
 *     not a year; whoever meant "year 2026, period 4" has to say which.
 * Absent keeps the documented default — that is not this function's business, it is the
 * reader's (`parameters.ts`).
 *
 * `null` counts as absent: JSON callers and CLI wrappers write an omitted parameter both ways,
 * and every projection has treated it as absent since long before this contract existed.
 */
export function validateProjectionParams(name: string, params: Record<string, unknown>): void {
  const declared = PROJECTION_PARAMETERS[name];
  // An unknown projection name is the dispatcher's own error (E_NOT_IMPLEMENTED); it must not
  // be reported as an input problem on the way there.
  if (declared === undefined) {
    return;
  }

  for (const [key, value] of Object.entries(params)) {
    const spec = declared[key];
    if (spec === undefined) {
      throw new DomainError('E_INPUT_INVALID', `${name}: unknown parameter "${key}"`, { parameter: key });
    }
    if (value === undefined || value === null) {
      continue;
    }
    if (!matchesParameterType(value, spec.type)) {
      throw new DomainError('E_INPUT_INVALID', `${name}: parameter "${key}" must be of type ${spec.type}`, {
        [key]: rejectedValue(value),
      });
    }
  }
}
