import { describe, expect, it } from 'vitest';
import {
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
 */

const TENANT = { name: 'Audit GmbH', baseCurrency: 'EUR' };

function freshOps(): TenantOperations {
  const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
  const tenant = Tenant.inMemory(TENANT.name, Currency.of(TENANT.baseCurrency), clock, new DeterministicIdGenerator(clock));
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
  // --- partners & open items ----------------------------------------------
  {
    op: 'createPartner',
    objectType: 'partner',
    action: 'created',
    run: (ops) => void ops.execute('createPartner', { number: 'D-1000', name: 'Kunde AG', role: 'customer' }),
  },
  {
    op: 'updatePartner',
    objectType: 'partner',
    action: 'updated',
    run: (ops) => {
      const partner = ops.execute('createPartner', {
        number: 'D-1000',
        name: 'Kunde AG',
        role: 'customer',
      }) as Record<string, unknown>;
      ops.execute('updatePartner', { partnerId: String(partner.id), name: 'Kunde SE' });
    },
  },
];

function auditRecords(ops: TenantOperations): Array<Record<string, unknown>> {
  const log = ops.project('auditLog', {}) as Record<string, unknown>;
  const records = log.records;
  return Array.isArray(records) ? (records as Array<Record<string, unknown>>) : [];
}

describe('audit-trail completeness contract', () => {
  it.each(AUDITED.map((c) => [c.op, c] as const))(
    '%s leaves an audit record',
    (_op, testCase) => {
      const ops = freshOps();
      testCase.run(ops);
      const match = auditRecords(ops).filter(
        (r) => r.objectType === testCase.objectType && r.action === testCase.action,
      );
      expect(
        match.length,
        `${testCase.op} must write an audit record ${testCase.objectType}/${testCase.action} — ` +
          `a state change without a trace is a GoBD defect, not a missing convenience`,
      ).toBeGreaterThan(0);
    },
  );

  it('records carry actor, timestamp and object identity', () => {
    const ops = freshOps();
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
    const ops = freshOps();
    ops.execute('createAccount', { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' });
    expect(auditRecords(ops)[0]?.actor).toBe('system');
  });

  it('every state-changing dispatcher operation is covered by this list', () => {
    // The guard against the guard: a new mutating operation must be added above, or this
    // fails. Read-only names are listed explicitly so that adding one does not silently
    // widen the exemption.
    const READ_ONLY = new Set(['expandTax']);
    const declared = new Set(AUDITED.map((c) => c.op));
    const mutating = [
      'expandTax', 'setTaxProfile', 'postVoucher', 'createVoucher', 'post', 'correct',
      'finalize', 'reverse', 'settle', 'closePeriod', 'reopenPeriod', 'closeFiscalYear',
      'createAccount', 'createFiscalYear', 'createPartner', 'updatePartner', 'acquireAsset',
      'disposeAsset', 'runDepreciation', 'allocate', 'setAllocationScheme', 'runCosting',
      'releaseCosting', 'lockAccount', 'importChartOfAccounts', 'importMapping',
    ].filter((op) => !READ_ONLY.has(op));

    const uncovered = mutating.filter((op) => !declared.has(op));
    expect(
      uncovered,
      'these operations change state but no audit-completeness case claims them — ' +
        'add a case above, or move the operation to READ_ONLY with a reason',
    ).toEqual(UNCOVERED_KNOWN);
  });
});

/**
 * Operations that mutate but are not yet pinned here. Each one is a gap, not an exemption:
 * they post through the ledger (so the journalEntry trace exists) but write no record of
 * their own — `acquireAsset` leaves "journalEntry/created" and nothing saying an asset was
 * acquired. Shrinking this list is the work; growing it needs a reason in the commit.
 */
const UNCOVERED_KNOWN: readonly string[] = [
  'postVoucher',
  'createVoucher',
  'settle',
  'acquireAsset',
  'disposeAsset',
  'runDepreciation',
  'allocate',
  'runCosting',
  'releaseCosting',
  'importChartOfAccounts',
];
