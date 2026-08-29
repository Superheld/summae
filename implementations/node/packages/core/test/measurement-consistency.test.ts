import { describe, expect, it } from 'vitest';
import {
  CostingRun,
  Currency,
  InMemoryCostingRunRepository,
  Money,
  PeriodRef,
  Uuid,
} from '../src/index.js';
import { MeasurementConsistencyProjection } from '../src/policies/projection/measurement-consistency.js';

/**
 * The cases the fixture cannot reach, and one of them matters more than it looks.
 *
 * `costing/measurement-consistency` drives the real path — two years, a changed election, a run
 * with no basis. What it cannot exercise is the shape of the answer when there is nothing to
 * compare, and `consistent: true` over an empty set is a *claim*: a caller that renders a green
 * badge from that field will render it for books that were never valued at all. So the empty case
 * is pinned here, together with the two boundaries the fixture only passes through once — a draft
 * that must not be reported, and two runs whose components differ only in order, which is the same
 * measurement and must not count as a change.
 *
 * The SAME cases live in the PHP MeasurementConsistencyTest.
 */

type Component = { id: string; treatment: string; included: boolean };

function costingRun(year: number, period: number, status: string, components: Component[] | null): CostingRun {
  const eur = Currency.of('EUR');
  return CostingRun.restore(
    Uuid.fromString(`00000000-0000-7000-8000-${String(year * 100 + period).padStart(12, '0')}`),
    new PeriodRef(year, period),
    1,
    status,
    new Map(),
    new Map(),
    Money.zero(eur),
    'step_ladder',
    [],
    [],
    components === null
      ? null
      : { total: '0.00', components: components.map((component) => ({ ...component, amount: '0.00' })) },
  );
}

function compute(...runs: CostingRun[]): Record<string, unknown> {
  const repository = new InMemoryCostingRunRepository();
  for (const run of runs) repository.add(run);
  return new MeasurementConsistencyProjection(repository).compute({});
}

describe('measurementConsistency', () => {
  it('says consistent over an empty set, and says there was nothing to compare', () => {
    const result = compute();
    expect(result.runs).toEqual([]);
    expect(result.withoutBasis).toEqual([]);
    expect(result.changes).toEqual([]);
    // True, and only readable together with an empty `runs`. Named here so a caller that turns this
    // field into a badge has been warned by a test rather than by an auditor.
    expect(result.consistent).toBe(true);
  });

  it('does not report a draft at all', () => {
    const result = compute(costingRun(2026, 1, 'draft', [{ id: 'material', treatment: 'mandatory', included: true }]));
    expect(result.runs).toEqual([]);
    expect(result.consistent).toBe(true);
  });

  it('treats the same components in a different order as the same measurement', () => {
    const result = compute(
      costingRun(2026, 1, 'released', [
        { id: 'material', treatment: 'mandatory', included: true },
        { id: 'admin', treatment: 'optional', included: true },
      ]),
      costingRun(2027, 1, 'released', [
        { id: 'admin', treatment: 'optional', included: true },
        { id: 'material', treatment: 'mandatory', included: true },
      ]),
    );

    expect(result.changes).toEqual([]);
    expect(result.consistent).toBe(true);
    expect((result.runs as Array<{ included: string[] }>)[0]?.included).toEqual(['admin', 'material']);
  });

  it('never reports a mandatory component as an election', () => {
    const result = compute(
      costingRun(2026, 1, 'released', [
        { id: 'material', treatment: 'mandatory', included: true },
        { id: 'research', treatment: 'forbidden', included: false },
      ]),
    );

    const first = (result.runs as Array<{ included: string[]; elected: string[] }>)[0];
    expect(first?.included).toEqual(['material']);
    // A pack that promotes a component from optional to mandatory must not read as the tenant
    // having changed its mind, which is what an `elected` list built from `included` would do.
    expect(first?.elected).toEqual([]);
  });
});
