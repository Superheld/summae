import { DeterministicIdGenerator, FixedClock } from '@superheld/summae-core';
import { diff } from './comparator.js';
import type { Fixture } from './fixture-loader.js';
import { PlaceholderBag } from './placeholder-bag.js';
import { crashed, type FixtureResult, passOrFail, type TraceEntry } from './result.js';
import { type Subject, SubjectError } from './subject.js';

/**
 * Runs a fixture against a fresh subject (runner contract,
 * testsuite/README.md): setup → steps → projections.
 *
 * Domain errors of the subject (SubjectError) are expected results;
 * any other exception is a crash of the implementation.
 */
export class FixtureRunner {
  run(fixture: Fixture, subject: Subject): FixtureResult {
    const bag = new PlaceholderBag();
    // Deterministic placeholder IDs: the double run must yield byte-identical
    // traces. Own time component, so that subject-internal IDs never collide.
    const ids = new DeterministicIdGenerator(FixedClock.at('2026-06-07T00:00:00+00:00'));
    const freshId = (): string => ids.next().value;
    const diffs: string[] = [];
    const trace: TraceEntry[] = [];

    try {
      const setup = bag.resolve(fixture.setup, freshId) as Record<string, unknown>;
      subject.setup(setup);
    } catch (error) {
      if (error instanceof SubjectError) {
        return passOrFail(fixture.name, [`setup: ${error.errorCode} (${error.message})`], []);
      }
      return crashed(fixture.name, `setup: ${reason(error)}`, []);
    }

    for (const [index, step] of fixture.steps.entries()) {
      const op = step.op;
      if (typeof op !== 'string') {
        return crashed(fixture.name, `steps[${index}]: op missing`, trace);
      }
      const label = `steps[${index}] ${op}`;

      let outcome: Record<string, unknown>;
      try {
        const input = bag.resolve(step.input ?? {}, freshId) as Record<string, unknown>;
        outcome = { ok: true, result: subject.execute(op, input) };
      } catch (error) {
        if (!(error instanceof SubjectError)) {
          return crashed(fixture.name, `${label}: ${reason(error)}`, trace);
        }
        outcome = { ok: false, error: error.errorCode };
      }

      trace.push({ step: op, outcome });
      diffs.push(...checkExpectation(step, outcome, bag, label));
    }

    for (const [index, projection] of fixture.projections.entries()) {
      const name = projection.name;
      if (typeof name !== 'string') {
        return crashed(fixture.name, `projections[${index}]: name missing`, trace);
      }
      const label = `projections[${index}] ${name}`;

      let outcome: Record<string, unknown>;
      try {
        const params = bag.resolve(projection.params ?? {}, freshId) as Record<string, unknown>;
        outcome = { ok: true, result: subject.project(name, params) };
      } catch (error) {
        if (!(error instanceof SubjectError)) {
          return crashed(fixture.name, `${label}: ${reason(error)}`, trace);
        }
        outcome = { ok: false, error: error.errorCode };
      }

      trace.push({ projection: name, outcome });
      diffs.push(...checkExpectation(projection, outcome, bag, label));
    }

    return passOrFail(fixture.name, diffs, trace);
  }
}

/**
 * Compares a step/projection result against expect. Steps carry
 * expect.result, projections their expect directly; both can carry expect.error.
 */
function checkExpectation(
  definition: Record<string, unknown>,
  outcome: Record<string, unknown>,
  bag: PlaceholderBag,
  label: string,
): string[] {
  const expect = definition.expect;
  if (expect === undefined) {
    return [];
  }
  if (expect === null || typeof expect !== 'object' || Array.isArray(expect)) {
    return [`${label}: expect has unexpected structure`];
  }
  const expectObject = expect as Record<string, unknown>;
  const ok = outcome.ok === true;

  const expectedError = expectObject.error;
  if (typeof expectedError === 'string') {
    if (ok) {
      return [`${label}: error ${expectedError} expected, operation succeeded`];
    }
    const actualError = typeof outcome.error === 'string' ? outcome.error : '?';
    return actualError === expectedError
      ? []
      : [`${label}: error ${expectedError} expected, is ${actualError}`];
  }

  if (!ok) {
    const actualError = typeof outcome.error === 'string' ? outcome.error : '?';
    return [`${label}: success expected, error ${actualError}`];
  }

  let expected: unknown;
  if (Object.hasOwn(expectObject, 'result')) {
    expected = expectObject.result;
  } else {
    expected = { ...expectObject };
    delete (expected as Record<string, unknown>).comment;
  }

  if (expected === null || (typeof expected === 'object' && Object.keys(expected).length === 0)) {
    return [];
  }

  return diff(expected, outcome.result ?? null, bag, label);
}

function reason(error: unknown): string {
  if (error instanceof Error) {
    return `${error.name}: ${error.message}`;
  }
  return String(error);
}
