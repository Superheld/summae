import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { loadFixtures, supersededFixtures } from '../src/fixture-loader.js';

/**
 * The retirement register is itself a contract surface.
 *
 * `testing/testsuite/` is append-only, and the runner now skips whatever
 * `testing/testsuite/superseded.json` lists — which is precisely the kind of mechanism that turns
 * into a way of making inconvenient fixtures disappear if nothing watches it. So: an entry has to
 * name a fixture that really exists, has to name a successor that really exists and really runs,
 * and a retired fixture may not still be claimed as green. A typo in the register would otherwise
 * silently retire nothing, or silently retire something nobody meant to.
 *
 * Mirror of the PHP `SupersededFixturesTest`; the register is shared, so both runners must skip
 * the same set — a fixture retired for PHP and still running for Node would break the one thing
 * the suite exists for.
 */
const here = dirname(fileURLToPath(import.meta.url));
const testsuiteDir = join(here, '..', '..', '..', '..', 'testing', 'testsuite');
const fixturesDir = join(testsuiteDir, 'fixtures');

function everyFixtureName(dir: string): Set<string> {
  const names = new Set<string>();
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      for (const name of everyFixtureName(path)) names.add(name);
    } else if (entry.isFile() && entry.name.endsWith('.json')) {
      const data = JSON.parse(readFileSync(path, 'utf8')) as Record<string, unknown>;
      if (typeof data.fixture === 'string') names.add(data.fixture);
    }
  }
  return names;
}

function expectedGreen(): string[] {
  return readFileSync(join(here, '..', 'expected-green.txt'), 'utf8')
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line !== '' && !line.startsWith('#'));
}

describe('superseded fixtures register', () => {
  const register = supersededFixtures(join(testsuiteDir, 'superseded.json'));
  const names = everyFixtureName(fixturesDir);
  const green = new Set(expectedGreen());

  it('names an existing fixture and an existing successor in every entry', () => {
    const problems: string[] = [];
    for (const [fixture, successor] of register) {
      if (!names.has(fixture)) problems.push(`superseded fixture "${fixture}" does not exist`);
      if (!names.has(successor)) problems.push(`successor "${successor}" of "${fixture}" does not exist`);
    }
    expect(problems).toEqual([]);
  });

  /**
   * A successor that nobody runs would leave the retired fixture's ground uncovered — the point of
   * retiring one is that something else took over its job, not that the job went away.
   *
   * Followed through chains, because retirement happens more than once to the same ground:
   * `system-description` → `-current` → `-invariants`. Only the last link has to run; demanding it
   * of a middle one would force a retired fixture back into the suite, which is the opposite of
   * what retiring it meant. A cycle is reported rather than followed forever.
   */
  it('has every successor in expected-green.txt', () => {
    const missing: string[] = [];
    for (const [fixture] of register) {
      const seen = new Set<string>([fixture]);
      let successor = register.get(fixture)!;
      while (register.has(successor) && !seen.has(successor)) {
        seen.add(successor);
        successor = register.get(successor)!;
      }
      if (seen.has(successor)) {
        missing.push(`supersession chain from "${fixture}" is circular at "${successor}"`);
        continue;
      }
      if (!green.has(successor)) {
        missing.push(`successor "${successor}" of "${fixture}" is not in expected-green.txt`);
      }
    }
    expect(missing).toEqual([]);
  });

  it('no longer demands a retired fixture as green', () => {
    expect(expectedGreen().filter((name) => register.has(name))).toEqual([]);
  });

  it('really skips them in the runner', () => {
    const loaded = new Set(loadFixtures().map((f) => f.name));
    expect([...register.keys()].filter((name) => loaded.has(name))).toEqual([]);
  });
});
