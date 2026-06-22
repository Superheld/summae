import { describe, it, expect } from 'vitest';
import { FixtureRunner } from '../src/fixture-runner.js';
import { SuiteRunner } from '../src/suite-runner.js';
import type { Fixture } from '../src/fixture-loader.js';
import { type Subject, type SubjectFactory, SubjectError } from '../src/subject.js';

/** Minimal subject: posts accounts into a map, projects a balance list. */
class FakeSubject implements Subject {
  private readonly balances = new Map<string, number>();
  private failNext: string | null = null;

  setup(setup: Record<string, unknown>): void {
    if (typeof setup.fail === 'string') {
      throw new SubjectError(setup.fail);
    }
  }

  execute(op: string, input: Record<string, unknown>): Record<string, unknown> {
    if (op === 'boom') {
      throw new Error('unexpected crash');
    }
    if (op === 'reject') {
      throw new SubjectError(typeof input.code === 'string' ? input.code : 'E_X');
    }
    if (op === 'credit') {
      const account = String(input.account);
      const amount = typeof input.amount === 'number' ? input.amount : 0;
      this.balances.set(account, (this.balances.get(account) ?? 0) + amount);
      return { account, balance: this.balances.get(account)! };
    }
    throw new SubjectError('E_NOT_IMPLEMENTED');
  }

  project(name: string, _params: Record<string, unknown>): Record<string, unknown> {
    if (name === 'balances') {
      return {
        rows: [...this.balances.entries()]
          .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
          .map(([account, balance]) => ({ account, balance })),
      };
    }
    throw new SubjectError('E_NOT_IMPLEMENTED');
  }
}

const fixture = (over: Partial<Fixture>): Fixture => ({
  name: 'fake',
  file: 'fake.json',
  setup: {},
  steps: [],
  projections: [],
  ...over,
});

describe('FixtureRunner', () => {
  const runner = new FixtureRunner();

  it('runs setup → steps → projections and checks expect (subset)', () => {
    const result = runner.run(
      fixture({
        steps: [
          { op: 'credit', input: { account: '1200', amount: 100 }, expect: { result: { balance: 100 } } },
        ],
        projections: [
          { name: 'balances', expect: { rows: [{ account: '1200', balance: 100 }] } },
        ],
      }),
      new FakeSubject(),
    );
    expect(result.status).toBe('pass');
    expect(result.diffs).toEqual([]);
  });

  it('accepts expected errors (expect.error)', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'reject', input: { code: 'E_ENTRY_UNBALANCED' }, expect: { error: 'E_ENTRY_UNBALANCED' } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('pass');
  });

  it('reports wrong error code as diff', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'reject', input: { code: 'E_OTHER' }, expect: { error: 'E_ENTRY_UNBALANCED' } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('fail');
    expect(result.diffs).toHaveLength(1);
  });

  it('treats unexpected exceptions as crash', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'boom', input: {}, expect: { result: {} } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('crash');
    expect(result.crashReason).toContain('boom');
  });

  it('reports diff when success expected but error came', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'reject', input: { code: 'E_X' }, expect: { result: { account: '1200' } } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('fail');
  });
});

describe('SuiteRunner — double-run determinism', () => {
  const factory: SubjectFactory = { create: () => new FakeSubject() };

  it('runs the fixtures and reports no determinism breaks with a stable subject', () => {
    const fixtures = [
      fixture({
        name: 'a',
        steps: [{ op: 'credit', input: { account: '1200', amount: 100 }, expect: { result: { balance: 100 } } }],
      }),
      fixture({
        name: 'b',
        steps: [{ op: 'reject', input: { code: 'E_X' }, expect: { error: 'E_X' } }],
      }),
    ];

    const suite = new SuiteRunner(factory).run(fixtures);

    expect(suite.results.map((r) => r.status)).toEqual(['pass', 'pass']);
    expect(suite.determinismBreaks).toEqual([]);
  });

  it('filters by name part', () => {
    const fixtures = [fixture({ name: 'alpha' }), fixture({ name: 'beta' })];
    const suite = new SuiteRunner(factory).run(fixtures, 'alph');
    expect(suite.results).toHaveLength(1);
    expect(suite.results[0]!.fixture).toBe('alpha');
  });
});
