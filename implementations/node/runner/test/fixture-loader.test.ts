import { describe, it, expect } from 'vitest';
import { loadFixtures } from '../src/fixture-loader.js';

describe('Fixture loader — shared root suite', () => {
  const fixtures = loadFixtures();

  it('finds at least the 45 core conformance fixtures (regression floor, not a hard number)', () => {
    // Lower bound instead of a fixed number: fixtures grow (pack composition etc.) — a fixed
    // value would be transactional data and break on every increase. The current count comes from the runner.
    expect(fixtures.length).toBeGreaterThanOrEqual(45);
  });

  it('every fixture carries a non-empty name', () => {
    expect(fixtures.every((f) => f.name.length > 0)).toBe(true);
  });

  it('is deterministically sorted by codepoints', () => {
    const names = fixtures.map((f) => f.name);
    const sorted = [...names].sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
    expect(names).toEqual(sorted);
  });
});
