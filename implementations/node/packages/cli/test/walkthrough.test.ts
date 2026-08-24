import { mkdtempSync, readFileSync, readdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, test, vi } from 'vitest';
import { run } from '../src/cli.js';

/**
 * Walkthrough scenarios — the gated form of the user documentation
 * (`docs/handbuch/cli-walkthrough.md`). One scenario per configuration we ship
 * (de / us / default / free `rules.json`), each a complete lifecycle: workspace →
 * postings → settlement → reversal → reports → close → export.
 *
 * What this covers that the conformance suite cannot: the CLI surface itself, the
 * workspace, the pack library, and the documented parameter names. The conformance
 * runner drives the *core* with a fixed clock; this drives the *binary* the docs
 * tell people to type. A documented behaviour that stops being true fails here.
 *
 * Format + scenario list: `scenarios/README.md`; the whole test landscape: `TESTING.md`.
 * The PHP `WalkthroughTest` reads the SAME files and pins the same expectations.
 */

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../..');
/** The documentation in executable form — one per shipped configuration. */
const scenarioDir = join(repoRoot, 'testing/scenarios/walkthrough');
/** Fixed defects, pinned so they cannot come back — adversarial input lives here only. */
const regressionDir = join(repoRoot, 'testing/scenarios/regression');

interface Step {
  name: string;
  op?: string;
  report?: string;
  input?: unknown;
  params?: unknown;
  capture?: Record<string, string>;
  expect?: Record<string, unknown>;
  expectError?: string;
  expectExitCode?: number;
  repeat?: { over: string; values: unknown[] };
}
interface Scenario {
  id: string;
  description: string;
  init: { pack?: string; rules?: string; currency?: string; firstFiscalYear?: number };
  expect?: Record<string, unknown>;
  steps: Step[];
}

/** One CLI invocation, in-process: the JSON line it printed plus its exit code. */
function cli(argv: string[]): { output: unknown; exitCode: number } {
  const lines: string[] = [];
  const spy = vi.spyOn(console, 'log').mockImplementation((message: unknown) => {
    lines.push(String(message));
  });
  process.exitCode = 0;
  try {
    run(['node', 'summae', ...argv]);
  } finally {
    spy.mockRestore();
  }
  const exitCode = typeof process.exitCode === 'number' ? process.exitCode : 0;
  process.exitCode = 0;
  return { output: JSON.parse(lines.at(-1) ?? '{}'), exitCode };
}

/** `openItemsCreated[0].id`, `keys.81.tax` — an unresolvable path is a failure, not a skip. */
function at(value: unknown, path: string): unknown {
  let current = value;
  for (const key of path.replace(/\[(\d+)\]/g, '.$1').split('.')) {
    if (current === null || typeof current !== 'object') {
      throw new Error(`path "${path}" runs out at "${key}"`);
    }
    current = (current as Record<string, unknown>)[key];
  }
  return current;
}

/** Replaces every `"$name"` with the captured value of that name (deeply). */
function substitute(value: unknown, captured: Map<string, unknown>): unknown {
  if (typeof value === 'string' && value.startsWith('$')) {
    const name = value.slice(1);
    if (!captured.has(name)) throw new Error(`step refers to "$${name}", which nothing captured`);
    return captured.get(name);
  }
  if (Array.isArray(value)) return value.map((item) => substitute(item, captured));
  if (value !== null && typeof value === 'object') {
    return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, substitute(v, captured)]));
  }
  return value;
}

function scenariosIn(directory: string): Scenario[] {
  return readdirSync(directory)
    .filter((file) => file.endsWith('.json') && !file.endsWith('-rules.json'))
    .sort()
    .map((file) => JSON.parse(readFileSync(join(directory, file), 'utf8')) as Scenario);
}

/** Documentation scenarios keep their own directory for the pack-coverage guard below. */
function documentedScenarios(): Scenario[] {
  return scenariosIn(scenarioDir);
}

function scenarios(): Scenario[] {
  return [...documentedScenarios(), ...scenariosIn(regressionDir)];
}

/**
 * These tests do real file I/O, so they get a real timeout.
 *
 * Every step is its own CLI invocation against a fresh SQLite workspace in `tmpdir()` — which is
 * the point of the scenarios and also what makes them the slowest thing in this suite. Locally the
 * `de` scenario runs in ~80ms; on a shared CI runner one of them once took longer than vitest's
 * 5-second default, on a commit that passed on the very same SHA in another run. Not a regression
 * and not a hang: an I/O stall with no headroom in front of it.
 *
 * A gate that goes red at random is worse than a slow one — it teaches people to re-run instead of
 * to look. So the budget here is large enough that exceeding it means something is genuinely stuck,
 * and small enough to still fail rather than hang forever. It applies to these tests only; the unit
 * suite keeps the default, where 5 seconds really would mean a bug.
 */
const SCENARIO_TIMEOUT_MS = 30_000;

describe('CLI walkthrough scenarios (the documentation, gated)', () => {
  for (const scenario of scenarios()) {
    test(`${scenario.id}: ${scenario.description}`, { timeout: SCENARIO_TIMEOUT_MS }, () => {
      const dir = mkdtempSync(join(tmpdir(), `summae-walkthrough-${scenario.id}-`));
      const captured = new Map<string, unknown>();

      const initArgs = ['init', '--name', `Walkthrough ${scenario.id}`, '--dir', dir];
      if (scenario.init.pack !== undefined) initArgs.push('--pack', scenario.init.pack);
      if (scenario.init.rules !== undefined) initArgs.push('--rules', join(scenarioDir, scenario.init.rules));
      if (scenario.init.currency !== undefined) initArgs.push('--currency', scenario.init.currency);
      if (scenario.init.firstFiscalYear !== undefined) {
        initArgs.push('--first-fiscal-year', String(scenario.init.firstFiscalYear));
      }
      const init = cli(initArgs);
      for (const [path, want] of Object.entries(scenario.expect ?? {})) {
        expect(at(init.output, path), `init → ${path}`).toStrictEqual(want);
      }

      for (const step of scenario.steps) {
        const values = step.repeat ? step.repeat.values : [null];
        for (const value of values) {
          const scope = new Map(captured);
          if (step.repeat) scope.set(step.repeat.over, value);

          const payload = JSON.stringify(substitute(step.input ?? step.params ?? {}, scope));
          const { output, exitCode } =
            step.op !== undefined
              ? cli(['op', step.op, '--input', payload, '--dir', dir])
              : cli(['report', step.report ?? '', '--params', payload, '--dir', dir]);

          const where = `[${scenario.id}] ${step.name}`;

          if (step.expectError !== undefined) {
            expect(at(output, 'error'), `${where} → error code`).toBe(step.expectError);
            if (step.expectExitCode !== undefined) {
              expect(exitCode, `${where} → exit code`).toBe(step.expectExitCode);
            }
            continue;
          }

          expect(at(output, 'error'), `${where} → unexpected error: ${JSON.stringify(output)}`).toBeUndefined();
          expect(exitCode, `${where} → exit code`).toBe(0);

          for (const [name, path] of Object.entries(step.capture ?? {})) {
            captured.set(name, at(output, path));
          }
          for (const [path, want] of Object.entries(step.expect ?? {})) {
            expect(at(output, path), `${where} → ${path}`).toStrictEqual(substitute(want, scope));
          }
        }
      }
    });
  }
});

test('the copy-pasteable example script shows every operation the de scenario pins', () => {
  // Two artefacts describe the same walkthrough: the shell script a reader copies from,
  // and de.json which the gate checks. This couples them — an operation that gains
  // coverage in the scenario but never appears in the script is a documentation hole.
  const source = readFileSync(join(repoRoot, 'docs/handbuch/examples/cli-walkthrough.sh'), 'utf8');
  const de = documentedScenarios().find((scenario) => scenario.id === 'de');
  const used = new Set(de?.steps.flatMap((step) => (step.op ? [`op ${step.op}`] : [`report ${step.report ?? ''}`])));

  const missing = [...used].filter((call) => !source.includes(call));
  expect(missing, 'shown in the gated scenario but not in the example script').toStrictEqual([]);
});

test('every shipped pack has a scenario', () => {
  const packDir = join(repoRoot, 'pack-library');
  const packs = readdirSync(packDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory() && entry.name.endsWith('-pack'))
    .map((entry) => entry.name.replace(/-pack$/, ''))
    .sort();
  // A pack may back several scenarios (the lifecycle one plus regression guards), so
  // compare the SET of covered packs, not the list.
  const covered = [
    ...new Set(
      documentedScenarios()
        .map((scenario) => scenario.init.pack)
        .filter((pack): pack is string => typeof pack === 'string'),
    ),
  ].sort();
  expect(covered, 'a pack without a walkthrough scenario is an untested offer').toStrictEqual(packs);
});
