import { describe, expect, it } from 'vitest';
import { loadPackLibrary } from '../src/pack-library.js';

/**
 * A published `(id, version)` names exactly one bundle.
 *
 * This is the rule that was missing, not a rule that was broken by accident. Until 2026-08-23 the
 * `de` manifest kept the version `2026.2` while the modules underneath it moved twice and a new one
 * joined, and the old module files were overwritten — so `de@2026.2` named at least three different
 * bundles and nothing anywhere said so. Whoever pinned that version got different books depending
 * on the day they installed.
 *
 * A test cannot see history, so it cannot catch a version that was silently reused last week. What
 * it can catch is the moment the library holds two files claiming the same published identity —
 * which is exactly what happens the first time somebody keeps an old version around (the point of
 * versioning) and edits it instead of adding a new one. `contentDigest` covers the other half at
 * runtime: it is derived, so a bundle that changed cannot present itself as unchanged.
 *
 * Mirror of the PHP `PackVersionIdentityTest`.
 */
function duplicates(keys: string[]): string[] {
  const seen = new Set<string>();
  const twice: string[] = [];
  for (const key of keys) {
    if (seen.has(key)) twice.push(key);
    seen.add(key);
  }
  return twice;
}

describe('published pack identities', () => {
  const library = loadPackLibrary();

  it('gives every manifest its own (id, version)', () => {
    expect(duplicates(library.manifests.map((m) => `${m.id}@${m.version}`))).toEqual([]);
  });

  it('gives every module its own (kind, id, version)', () => {
    expect(duplicates(library.modules.map((m) => `${m.kind}|${m.id}@${m.version}`))).toEqual([]);
  });

  /**
   * Every manifest carries a version at all — an absent one would make the whole rule vacuous, and
   * "highest version wins" would sort an empty string to the bottom without complaining.
   */
  it('leaves no manifest or module without a version', () => {
    const missing = [
      ...library.manifests.filter((m) => !m.version).map((m) => `manifest ${m.id}`),
      ...library.modules.filter((m) => !m.version).map((m) => `module ${m.id}`),
    ];
    expect(missing).toEqual([]);
  });
});
