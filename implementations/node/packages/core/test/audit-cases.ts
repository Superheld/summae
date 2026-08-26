import { describe, expect, it } from 'vitest';
import {
  API_OPERATIONS,
  AUDITED_EVENTS,
  Currency,
  DeterministicIdGenerator,
  FixedClock,
  Tenant,
  TenantOperations,
} from '../src/index.js';

/**
 * Contract test for audit-trail completeness (F-CORE-014, F-CORE-020; GoBD Rz. 107 ff.).
 *
 * The behavioural fixtures prove that individual operations produce the right numbers.
 * They do NOT prove that a *state-changing* operation leaves a trace: a fixture only sees
 * what it asserts, so an operation that silently mutates bookkeeping-relevant state and
 * writes no audit record passes every fixture in the suite. That is exactly how
 * `setTaxProfile`, `importMapping`, `setAllocationScheme` and all four period operations
 * went unlogged while F-CORE-014 counted as covered — the one fixture backing it
 * (`core/audit-trail.json`) exercises accounts only.
 *
 * So this test enumerates the operations instead of sampling them: every entry below runs
 * for real and must add at least one audit record with the stated objectType and action.
 * An operation added later without a trace fails here, in the language it was added in.
 *
 * The SAME list lives in the PHP AuditTrailContractTest. Read-only operations (projections,
 * `expandTax`) are deliberately absent: they change nothing, so there is nothing to log.
 *
 * **Not a test file — a case library.** `describeAuditContract` is called twice: once from
 * `packages/core/test/audit-trail-contract.test.ts` against an in-memory tenant, and once from
 * `packages/knex/test/audit-trail-contract.test.ts` against real persistence. The second binding is
 * the point. This enumeration used to exist only for `Tenant.inMemory`, which is the one
 * construction summae does not ship, so it could not see the defect class that actually occurred:
 * `DatabaseTenantFactory` takes the audit writer as an OPTIONAL argument and used to leave it off
 * for three services, so the tax profile, the asset events and the costing runs wrote no record at
 * all behind a database while every in-memory test stayed green (0.12.0). An optional dependency
 * makes that silent — nothing fails to compile, nothing warns, the trail is merely thinner in the
 * setup that counts.
 */

/** How a binding builds the tenant under test. */
export type TenantBuilder = (clock: FixedClock) => Tenant;

const TENANT = { name: 'Audit GmbH', baseCurrency: 'EUR' };

export function inMemoryTenant(clock: FixedClock): Tenant {
  return Tenant.inMemory(TENANT.name, Currency.of(TENANT.baseCurrency), clock, new DeterministicIdGenerator(clock));
}

function freshOps(buildTenant: TenantBuilder): TenantOperations {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = buildTenant(clock);
  // The asset operations need the pack data they would normally be composed with; without it
  // `acquireAsset` fails on the missing useful life instead of on the thing under test.
  tenant.assetService.setRuleModule({
    usefulLife: [{ assetClass: 'machinery', months: 60 }],
    // The two allowances the asset operations need before they can be audited at all: an elected
    // special depreciation needs a declared one to draw on, and output-based depreciation needs
    // the method to be offered.
    specialDepreciation: [{ validFrom: '2024-01-01', validTo: null, rate: '40.00', years: 5 }],
    assetAccounts: {
      acquisitionCounterAccount: '1200',
      depreciationExpenseAccount: '4830',
      accumulatedDepreciationAccount: '0400',
      gwgExpenseAccount: '4930',
      disposalGainAccount: '8400',
      disposalLossAccount: '4930',
    },
  });
  // The appropriation plug, for the same reason: without it the operation refuses with
  // E_APPROPRIATION_UNSUPPORTED instead of reaching the trail.
  tenant.resultAppropriation.setRuleModule({
    resultAppropriation: {
      allocationAccount: '2300',
      targets: { carryForward: { account: '2100', label: 'Gewinnvortrag' } },
    },
  });
  return new TenantOperations(tenant);
}

/** Accounts, a fiscal year and a voucher — the ground state most operations need. */
function seed(ops: TenantOperations): { voucherId: string } {
  ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
  ops.execute('createAccount', { number: '4930', name: 'Bürobedarf', type: 'expense' });
  ops.execute('createAccount', { number: '1600', name: 'Verbindlichkeiten', type: 'liability' });
  ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' });
  const voucher = ops.execute('createVoucher', {
    voucher: { voucherNumber: 'ER-2026-001', voucherDate: '2026-01-20' },
  }) as Record<string, unknown>;
  return { voucherId: String(voucher.id) };
}

function postOne(ops: TenantOperations, voucherId: string, date = '2026-01-20'): string {
  const result = ops.execute('post', {
    entryDate: date,
    voucherId,
    text: 'Bürobedarf',
    lines: [
      { account: '4930', side: 'debit', money: { amount: '240.00', currency: 'EUR' } },
      { account: '1200', side: 'credit', money: { amount: '240.00', currency: 'EUR' } },
    ],
  }) as Record<string, unknown>;
  return String(result.id);
}

/** The asset most of the asset cases need — one place, so a changed input shape moves once. */
function acquire(ops: TenantOperations, voucherId: string, extra: Record<string, unknown> = {}): string {
  ops.execute('createAccount', { number: '0400', name: 'Maschinen', type: 'asset' });
  ops.execute('createAccount', { number: '4830', name: 'AfA', type: 'expense' });
  const asset = ops.execute('acquireAsset', {
    name: 'Maschine',
    assetClass: 'machinery',
    assetAccount: '0400',
    acquisitionCost: { amount: '5000.00', currency: 'EUR' },
    acquiredOn: '2026-01-15',
    usefulLifeMonths: 60,
    voucherId,
    ...extra,
  }) as Record<string, unknown>;
  return String(asset.id);
}

type Case = {
  readonly op: string;
  readonly objectType: string;
  readonly action: string;
  readonly run: (ops: TenantOperations) => void;
};

const AUDITED: readonly Case[] = [
  // --- ledger -------------------------------------------------------------
  {
    op: 'createAccount',
    objectType: 'account',
    action: 'created',
    run: (ops) => void ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' }),
  },
  {
    op: 'lockAccount',
    objectType: 'account',
    action: 'locked',
    run: (ops) => {
      seed(ops);
      ops.execute('lockAccount', { number: '4930' });
    },
  },
  {
    op: 'unlockAccount',
    objectType: 'account',
    action: 'unlocked',
    run: (ops) => {
      seed(ops);
      ops.execute('lockAccount', { number: '4930' });
      ops.execute('unlockAccount', { number: '4930' });
    },
  },
  {
    op: 'defineDimensionType',
    objectType: 'dimensionType',
    action: 'created',
    run: (ops) => void ops.execute('defineDimensionType', { code: 'costCenter' }),
  },
  {
    op: 'defineDimensionValue',
    objectType: 'dimensionValue',
    action: 'created',
    run: (ops) => {
      ops.execute('defineDimensionType', { code: 'costCenter' });
      ops.execute('defineDimensionValue', { type: 'costCenter', code: 'K100' });
    },
  },
  {
    op: 'post',
    objectType: 'journalEntry',
    action: 'created',
    run: (ops) => void postOne(ops, seed(ops).voucherId),
  },
  {
    op: 'correct',
    objectType: 'journalEntry',
    action: 'corrected',
    run: (ops) => {
      const entryId = postOne(ops, seed(ops).voucherId);
      ops.execute('correct', { entryId, text: 'Bürobedarf Januar' });
    },
  },
  {
    op: 'finalize',
    objectType: 'journalEntry',
    action: 'finalized',
    run: (ops) => {
      postOne(ops, seed(ops).voucherId);
      ops.execute('finalize', { finalizeUntil: '2026-01-31' });
    },
  },
  {
    op: 'reverse',
    objectType: 'journalEntry',
    action: 'reversed',
    run: (ops) => {
      const entryId = postOne(ops, seed(ops).voucherId);
      ops.execute('reverse', { entryId, entryDate: '2026-01-25', text: 'Storno' });
    },
  },
  // --- periods: the operations GoBD Rz. 107 ff. cares about most -----------
  {
    op: 'createFiscalYear',
    objectType: 'fiscalYear',
    action: 'created',
    run: (ops) => void ops.execute('createFiscalYear', { year: 2026, start: '2026-01-01', end: '2026-12-31' }),
  },
  {
    op: 'closePeriod',
    objectType: 'period',
    action: 'closed',
    run: (ops) => {
      seed(ops);
      ops.execute('closePeriod', { fiscalYear: 2026, period: 1 });
    },
  },
  {
    // Reopening a closed period is the single most audit-relevant act in the whole API:
    // it takes back a lock. It used to leave no trace at all.
    op: 'reopenPeriod',
    objectType: 'period',
    action: 'reopened',
    run: (ops) => {
      seed(ops);
      ops.execute('closePeriod', { fiscalYear: 2026, period: 1 });
      ops.execute('reopenPeriod', { fiscalYear: 2026, period: 1 });
    },
  },
  {
    op: 'closeFiscalYear',
    objectType: 'fiscalYear',
    action: 'closed',
    run: (ops) => {
      const { voucherId } = seed(ops);
      postOne(ops, voucherId);
      ops.execute('finalize', { finalizeUntil: '2026-12-31' });
      for (let period = 1; period <= 12; period++) ops.execute('closePeriod', { fiscalYear: 2026, period });
      ops.execute('closeFiscalYear', { fiscalYear: 2026 });
    },
  },
  {
    // The resolution is an ordinary entry, so it is the entry that is audited — which is the
    // point: an appropriation must be as traceable as any other posting, not a side channel.
    op: 'appropriateResult',
    objectType: 'journalEntry',
    action: 'created',
    run: (ops) => {
      const { voucherId } = seed(ops);
      ops.execute('createAccount', { number: '2100', name: 'Gewinnvortrag', type: 'equity' });
      ops.execute('createAccount', { number: '2300', name: 'Ergebnisverwendung', type: 'equity', subtype: 'result_allocation' });
      ops.execute('createAccount', { number: '8400', name: 'Erlöse', type: 'revenue' });
      ops.execute('post', {
        entryDate: '2026-01-20',
        voucherId,
        text: 'Erlös',
        lines: [
          { account: '1200', side: 'debit', money: { amount: '900.00', currency: 'EUR' } },
          { account: '8400', side: 'credit', money: { amount: '900.00', currency: 'EUR' } },
        ],
      });
      ops.execute('appropriateResult', {
        fiscalYear: 2026,
        entryDate: '2026-06-30',
        voucherId,
        appropriations: [{ target: 'carryForward', money: { amount: '900.00', currency: 'EUR' } }],
      });
    },
  },
  // --- tenant-level configuration (F-CORE-014 "Steuerschlüssel, Profile") --
  {
    op: 'setTaxProfile',
    objectType: 'taxProfile',
    action: 'changed',
    run: (ops) => void ops.execute('setTaxProfile', { smallBusiness: { validFrom: '2026-01-01', value: true } }),
  },
  {
    op: 'importMapping',
    objectType: 'mapping',
    action: 'imported',
    run: (ops) => {
      seed(ops);
      ops.execute('importMapping', {
        mapping: {
          id: 'test-bilanz',
          kind: 'balance-sheet',
          nodes: [
            { key: 'assets', label: 'Aktiva', side: 'assets', accounts: ['1200'] },
            { key: 'liabilities', label: 'Passiva', side: 'liabilitiesAndEquity', accounts: ['1600'] },
          ],
        },
      });
    },
  },
  {
    op: 'setAllocationScheme',
    objectType: 'allocationScheme',
    action: 'changed',
    run: (ops) =>
      void ops.execute('setAllocationScheme', {
        method: 'step_ladder',
        steps: [{ sender: 'HK1', receivers: [{ code: 'K1', share: '1' }] }],
      }),
  },
  // --- partners -----------------------------------------------------------
  {
    op: 'createPartner',
    objectType: 'partner',
    action: 'created',
    run: (ops) => void ops.execute('createPartner', { name: 'Kunde AG', kind: 'customer' }),
  },
  {
    op: 'updatePartner',
    objectType: 'partner',
    action: 'updated',
    run: (ops) => {
      const partner = ops.execute('createPartner', {
        name: 'Kunde AG',
        kind: 'customer',
      }) as Record<string, unknown>;
      ops.execute('updatePartner', { partnerId: String(partner.id), name: 'Kunde SE' });
    },
  },
  {
    op: 'deactivatePartner',
    objectType: 'partner',
    action: 'deactivated',
    run: (ops) => {
      const partner = ops.execute('createPartner', { name: 'Kunde AG', kind: 'customer' }) as Record<string, unknown>;
      ops.execute('deactivatePartner', { partnerId: String(partner.id) });
    },
  },
  {
    op: 'reactivatePartner',
    objectType: 'partner',
    action: 'reactivated',
    run: (ops) => {
      const partner = ops.execute('createPartner', { name: 'Kunde AG', kind: 'customer' }) as Record<string, unknown>;
      ops.execute('deactivatePartner', { partnerId: String(partner.id) });
      ops.execute('reactivatePartner', { partnerId: String(partner.id) });
    },
  },
  // --- vouchers, settlements, assets, costing ------------------------------
  {
    op: 'createVoucher',
    objectType: 'voucher',
    action: 'created',
    run: (ops) => void seed(ops),
  },
  {
    op: 'postVoucher',
    objectType: 'voucher',
    action: 'created',
    run: (ops) => {
      seed(ops);
      ops.execute('postVoucher', {
        voucher: { voucherNumber: 'ER-2026-002', voucherDate: '2026-01-21' },
        entryDate: '2026-01-21',
        text: 'Direktbuchung',
        lines: [
          { account: '4930', side: 'debit', money: { amount: '100.00', currency: 'EUR' } },
          { account: '1200', side: 'credit', money: { amount: '100.00', currency: 'EUR' } },
        ],
      });
    },
  },
  {
    op: 'settle',
    objectType: 'openItem',
    action: 'settled',
    run: (ops) => {
      const { voucherId } = seed(ops);
      ops.execute('createAccount', { number: '1400', name: 'Forderungen', type: 'asset', subtype: 'ar' });
      ops.execute('createAccount', { number: '8400', name: 'Erlöse', type: 'revenue' });
      ops.execute('post', {
        entryDate: '2026-01-20',
        voucherId,
        text: 'Rechnung',
        lines: [
          { account: '1400', side: 'debit', money: { amount: '119.00', currency: 'EUR' } },
          { account: '8400', side: 'credit', money: { amount: '119.00', currency: 'EUR' } },
        ],
      });
      // The payment is an ordinary posting; `settle` then points that entry at the open item.
      const payment = ops.execute('post', {
        entryDate: '2026-01-25',
        voucherId,
        text: 'Zahlung',
        lines: [
          { account: '1200', side: 'debit', money: { amount: '119.00', currency: 'EUR' } },
          { account: '1400', side: 'credit', money: { amount: '119.00', currency: 'EUR' } },
        ],
      }) as Record<string, unknown>;
      const items = ops.project('openItems', {}) as { items?: Array<Record<string, unknown>> };
      const itemId = String((items.items ?? [])[0]?.id);
      ops.execute('settle', {
        entryId: String(payment.id),
        allocations: [{ openItemId: itemId, money: { amount: '119.00', currency: 'EUR' } }],
      });
    },
  },
  {
    op: 'importChartOfAccounts',
    objectType: 'account',
    action: 'created',
    run: (ops) =>
      void ops.execute('importChartOfAccounts', {
        rows: [{ number: '4980', name: 'Sonstiges', type: 'expense' }],
      }),
  },
  {
    op: 'acquireAsset',
    objectType: 'asset',
    action: 'acquired',
    run: (ops) => {
      const { voucherId } = seed(ops);
      ops.execute('createAccount', { number: '0400', name: 'Maschinen', type: 'asset' });
      ops.execute('createAccount', { number: '4830', name: 'AfA', type: 'expense' });
      ops.execute('acquireAsset', {
        name: 'Maschine',
        assetClass: 'machinery',
        assetAccount: '0400',
        acquisitionCost: { amount: '5000.00', currency: 'EUR' },
        acquiredOn: '2026-01-15',
        usefulLifeMonths: 60,
        voucherId,
      });
    },
  },
  {
    op: 'disposeAsset',
    objectType: 'asset',
    action: 'disposed',
    run: (ops) => {
      const { voucherId } = seed(ops);
      ops.execute('createAccount', { number: '0400', name: 'Maschinen', type: 'asset' });
      ops.execute('createAccount', { number: '4830', name: 'AfA', type: 'expense' });
      const asset = ops.execute('acquireAsset', {
        name: 'Maschine',
        assetClass: 'machinery',
        assetAccount: '0400',
        acquisitionCost: { amount: '5000.00', currency: 'EUR' },
        acquiredOn: '2026-01-15',
        usefulLifeMonths: 60,
        voucherId,
      }) as Record<string, unknown>;
      ops.execute('disposeAsset', { assetId: String(asset.id), disposedOn: '2026-06-30', voucherId });
    },
  },
  {
    op: 'writeDownAsset',
    objectType: 'asset',
    action: 'writtenDown',
    run: (ops) => {
      const { voucherId } = seed(ops);
      const asset = acquire(ops, voucherId);
      ops.execute('writeDownAsset', {
        assetId: asset,
        amount: { amount: '1000.00', currency: 'EUR' },
        date: '2026-06-30',
        reason: 'Wasserschaden',
        voucherId,
      });
    },
  },
  {
    op: 'bookSpecialDepreciation',
    objectType: 'asset',
    action: 'specialDepreciationBooked',
    run: (ops) => {
      const { voucherId } = seed(ops);
      const asset = acquire(ops, voucherId, { specialDepreciation: true });
      ops.execute('bookSpecialDepreciation', {
        assetId: asset,
        fiscalYear: 2026,
        amount: { amount: '500.00', currency: 'EUR' },
        voucherId,
      });
    },
  },
  {
    op: 'reportAssetUsage',
    objectType: 'asset',
    action: 'usageReported',
    run: (ops) => {
      const { voucherId } = seed(ops);
      const asset = acquire(ops, voucherId, {
        totalUnits: 100000,
        depreciationMethod: 'units_of_production',
      });
      ops.execute('reportAssetUsage', { assetId: asset, fiscalYear: 2026, units: 10000, voucherId });
    },
  },
  {
    op: 'runDepreciation',
    objectType: 'depreciationRun',
    action: 'completed',
    run: (ops) => {
      seed(ops);
      ops.execute('runDepreciation', { fiscalYear: 2026, period: 12 });
    },
  },
  {
    op: 'runCosting',
    objectType: 'costingRun',
    action: 'created',
    run: (ops) => {
      seed(ops);
      ops.execute('runCosting', { fiscalYear: 2026, period: 1 });
    },
  },
  {
    op: 'releaseCosting',
    objectType: 'costingRun',
    action: 'released',
    run: (ops) => {
      seed(ops);
      const run = ops.execute('runCosting', { fiscalYear: 2026, period: 1 }) as Record<string, unknown>;
      ops.execute('releaseCosting', { runId: String(run.runId) });
    },
  },
];


/**
 * The recipe behind `openItem/cancelled` — not an operation of its own.
 *
 * It is what `reverse` leaves behind when the entry it undoes had created an open item. Published
 * as an audited event and, until this recipe existed, never observed by any test.
 */
function reverseSettled(ops: TenantOperations): void {
  const { voucherId } = seed(ops);
  ops.execute('createAccount', { number: '1400', name: 'Forderungen', type: 'asset', subtype: 'ar' });
  ops.execute('createAccount', { number: '8400', name: 'Erlöse', type: 'revenue' });
  const invoice = ops.execute('post', {
    entryDate: '2026-01-20',
    voucherId,
    text: 'Rechnung',
    lines: [
      { account: '1400', side: 'debit', money: { amount: '119.00', currency: 'EUR' } },
      { account: '8400', side: 'credit', money: { amount: '119.00', currency: 'EUR' } },
    ],
  }) as Record<string, unknown>;
  ops.execute('reverse', { entryId: String(invoice.id), entryDate: '2026-01-26', text: 'Storno' });
}

function auditRecords(ops: TenantOperations): Array<Record<string, unknown>> {
  const log = ops.project('auditLog', {}) as Record<string, unknown>;
  const records = log.records;
  return Array.isArray(records) ? (records as Array<Record<string, unknown>>) : [];
}

/** Every audit record every recipe produces, from a fresh tenant each time. */
function allObservedRecords(buildTenant: TenantBuilder): Array<Record<string, unknown>> {
  const runs: Array<(ops: TenantOperations) => void> = [reverseSettled, ...AUDITED.map((c) => c.run)];
  return runs.flatMap((run) => {
    const ops = freshOps(buildTenant);
    run(ops);
    return auditRecords(ops);
  });
}

function pairsOf(records: Array<Record<string, unknown>>): string[] {
  return [...new Set(records.map((r) => `${String(r.objectType)}/${String(r.action)}`))].sort();
}

/**
 * Operations that mutate but are not yet pinned here. The list is EMPTY, and keeping it that
 * way is the point: every state-changing operation in the dispatcher now writes a record of
 * its own kind, not merely the `journalEntry/created` its postings leave behind. An entry
 * here needs a reason in the commit that adds it.
 */
const UNCOVERED_KNOWN: readonly string[] = [];

export function describeAuditContract(label: string, buildTenant: TenantBuilder): void {
  describe(`audit-trail completeness contract (${label})`, () => {
    it.each(AUDITED.map((c) => [c.op, c] as const))('%s leaves an audit record', (_op, testCase) => {
      const ops = freshOps(buildTenant);
      testCase.run(ops);
      const match = auditRecords(ops).filter(
        (r) => r.objectType === testCase.objectType && r.action === testCase.action,
      );
      expect(
        match.length,
        `${testCase.op} must write an audit record ${testCase.objectType}/${testCase.action} — ` +
          `a state change without a trace is a GoBD defect, not a missing convenience`,
      ).toBeGreaterThan(0);
    });

    it('records carry actor, timestamp and object identity', () => {
      const ops = freshOps(buildTenant);
      seed(ops);
      ops.execute('closePeriod', { fiscalYear: 2026, period: 1, actor: 'bruce' });

      const record = auditRecords(ops).find((r) => r.action === 'closed');
      expect(record, 'closePeriod must be in the log').toBeDefined();
      expect(record?.actor).toBe('bruce');
      expect(record?.at).toBe('2026-06-07T10:00:00.000Z');
      expect(typeof record?.objectId).toBe('string');
      expect(record?.changes).toBeTypeOf('object');
    });

    it('an absent actor is recorded as the system, never as empty', () => {
      const ops = freshOps(buildTenant);
      ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
      expect(auditRecords(ops)[0]?.actor).toBe('system');
    });

    /**
     * The published event list is what an auditor reads as "this is what the trail records".
     * Nothing held it against reality, and it had already fallen behind once (0.11.0). Both
     * directions, like `capabilities`: under-reporting hides a recorded event, over-reporting
     * promises one that never appears — and a Verfahrensdokumentation that claims more than the
     * software does is worse than one that claims less.
     */
    it('publishes exactly the events the operations actually record', () => {
      const published = [
        ...new Set(AUDITED_EVENTS.flatMap((e) => e.actions.map((a) => `${e.objectType}/${a}`))),
      ].sort();
      const observed = pairsOf(allObservedRecords(buildTenant));

      expect(
        observed.filter((pair) => !published.includes(pair)),
        'the trail writes these events and systemDescription does not publish them — a description ' +
          'that under-reports lets a recorded event look like one that never happens',
      ).toEqual([]);
      expect(
        published.filter((pair) => !observed.includes(pair)),
        'systemDescription publishes these events and no operation produces them — either the ' +
          'claim is stale or a recipe is missing; both are findings',
      ).toEqual([]);
    });

    /**
     * The published invariant says a record carries "actor, timestamp, object and before/after
     * values". It was true of most records and not of all: accounts, postings and partners wrote
     * an empty diff while vouchers, fiscal years, dimensions and costing runs wrote `from: null`.
     * A creation is a change from nothing, and writing it as nothing is what made the claim false.
     */
    it('every record carries a before/after diff', () => {
      const empty = allObservedRecords(buildTenant).filter((r) => {
        const changes = r.changes;
        return changes === null || typeof changes !== 'object' || Object.keys(changes).length === 0;
      });

      expect(
        pairsOf(empty),
        'these events record no before/after values, which systemDescription promises they do',
      ).toEqual([]);
    });

    it('every state-changing dispatcher operation is covered by this list', () => {
      // The guard against the guard: a new mutating operation must be added above, or this
      // fails. Read-only names are listed explicitly so that adding one does not silently
      // widen the exemption.
      // `allocate` distributes an amount by largest remainder and returns the parts —
      // pure computation, no journal effect (see the dispatcher). Nothing to log.
      const READ_ONLY = new Set(['expandTax', 'allocate']);
      const declared = new Set(AUDITED.map((c) => c.op));
      // Taken from the PUBLISHED surface rather than from a list of its own. A hand-kept copy is a
      // third place to forget an operation, and it had already fallen behind by seven names: the
      // ones the dispatcher routed without publishing (F-14) were mutating, unlisted here, and
      // therefore exempt from the completeness check without anyone deciding that.
      const mutating = [...API_OPERATIONS].filter((op) => !READ_ONLY.has(op));

      const uncovered = mutating.filter((op) => !declared.has(op));
      expect(
        uncovered,
        'these operations change state but no audit-completeness case claims them — ' +
          'add a case above, or move the operation to READ_ONLY with a reason',
      ).toEqual(UNCOVERED_KNOWN);
    });
  });
}
