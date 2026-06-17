import { describe, it, expect } from 'vitest';
import { loadFixtures } from '../src/fixture-loader.js';

describe('Fixture-Loader — geteilte Root-Suite', () => {
  const fixtures = loadFixtures();

  it('findet die 45 Konformitäts-Fixtures', () => {
    expect(fixtures.length).toBe(45);
  });

  it('jede Fixture trägt einen nicht-leeren Namen', () => {
    expect(fixtures.every((f) => f.name.length > 0)).toBe(true);
  });

  it('ist deterministisch nach Codepoints sortiert', () => {
    const names = fixtures.map((f) => f.name);
    const sorted = [...names].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    expect(names).toEqual(sorted);
  });
});
