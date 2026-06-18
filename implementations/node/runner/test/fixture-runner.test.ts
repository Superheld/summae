import { describe, it, expect } from 'vitest';
import { FixtureRunner } from '../src/fixture-runner.js';
import { SuiteRunner } from '../src/suite-runner.js';
import type { Fixture } from '../src/fixture-loader.js';
import { type Subject, type SubjectFactory, SubjectError } from '../src/subject.js';

/** Minimal-Subject: bucht Konten in eine Map, projiziert eine Saldenliste. */
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
      throw new Error('unerwarteter Absturz');
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

  it('läuft setup → steps → projections und prüft expect (Teilmenge)', () => {
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

  it('akzeptiert erwartete Fehler (expect.error)', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'reject', input: { code: 'E_ENTRY_UNBALANCED' }, expect: { error: 'E_ENTRY_UNBALANCED' } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('pass');
  });

  it('meldet falschen Fehlercode als Diff', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'reject', input: { code: 'E_OTHER' }, expect: { error: 'E_ENTRY_UNBALANCED' } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('fail');
    expect(result.diffs).toHaveLength(1);
  });

  it('behandelt unerwartete Exceptions als Crash', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'boom', input: {}, expect: { result: {} } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('crash');
    expect(result.crashReason).toContain('boom');
  });

  it('meldet Diff, wenn Erfolg erwartet, aber Fehler kam', () => {
    const result = runner.run(
      fixture({ steps: [{ op: 'reject', input: { code: 'E_X' }, expect: { result: { account: '1200' } } }] }),
      new FakeSubject(),
    );
    expect(result.status).toBe('fail');
  });
});

describe('SuiteRunner — Doppellauf-Determinismus', () => {
  const factory: SubjectFactory = { create: () => new FakeSubject() };

  it('läuft die Fixtures und meldet keine Determinismus-Brüche bei stabilem Subject', () => {
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

  it('filtert nach Namensteil', () => {
    const fixtures = [fixture({ name: 'alpha' }), fixture({ name: 'beta' })];
    const suite = new SuiteRunner(factory).run(fixtures, 'alph');
    expect(suite.results).toHaveLength(1);
    expect(suite.results[0]!.fixture).toBe('alpha');
  });
});
