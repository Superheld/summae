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

describe('Konformität — erwartete grüne Fixtures gegen den Core', () => {
  it.each(expectedGreen)('%s ist grün', (name) => {
    const result = byName.get(name);
    expect(result, `Fixture ${name} nicht gefunden`).toBeDefined();
    expect(result?.diffs.join('\n')).toBe('');
    expect(result?.status).toBe('pass');
  });

  it('Doppellauf ist deterministisch (gesamte Suite)', () => {
    expect(suite.determinismBreaks).toEqual([]);
  });
});
