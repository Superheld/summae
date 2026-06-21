import { describe, it, expect } from 'vitest';
import { loadFixtures } from '../src/fixture-loader.js';

describe('Fixture-Loader — geteilte Root-Suite', () => {
  const fixtures = loadFixtures();

  it('findet mindestens die 45 Kern-Konformitäts-Fixtures (Regressions-Floor, keine harte Zahl)', () => {
    // Untergrenze statt fixer Zahl: Fixtures wachsen (Pack-Komposition etc.) — ein fixer
    // Wert wäre Bewegungsdaten und bräche bei jedem Zuwachs. Der aktuelle Stand kommt aus dem Runner.
    expect(fixtures.length).toBeGreaterThanOrEqual(45);
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
