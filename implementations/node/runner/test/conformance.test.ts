import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { loadFixtures } from '../src/fixture-loader.js';
import { SuiteRunner } from '../src/suite-runner.js';
import { CoreSubjectFactory } from '../src/subject/core-subject-factory.js';

const here = dirname(fileURLToPath(import.meta.url));
const expectedGreen = readFileSync(join(here, '..', 'expected-green.txt'), 'utf8')
  .split('\n')
  .map((line) => line.trim())
  .filter((line) => line.length > 0 && !line.startsWith('#'));

const suite = new SuiteRunner(new CoreSubjectFactory()).run(loadFixtures());
const byName = new Map(suite.results.map((result) => [result.fixture, result]));

describe('Conformance — expected green fixtures against the core', () => {
  it.each(expectedGreen)('%s is green', (name) => {
    const result = byName.get(name);
    expect(result, `Fixture ${name} not found`).toBeDefined();
    expect(result?.diffs.join('\n')).toBe('');
    expect(result?.status).toBe('pass');
  });

  it('double run is deterministic (entire suite)', () => {
    expect(suite.determinismBreaks).toEqual([]);
  });
});
