import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

/**
 * Gate for the two language-neutral documents that enumerate the API (IMPL-044):
 *
 *   - `knowledge/50-spezifikation/api.md` — the normative API specification, whose own words are
 *     "Semantik und Namen sind bindend";
 *   - `knowledge/40-domaenenmodell/jurisdiction-profil.md` — the policy-kind census, which claims to
 *     make "everything above the substrate is exactly one of three kinds" provable *by enumeration*.
 *
 * Neither was held against anything until 2026-08-29, and both had drifted far enough to be wrong
 * rather than merely incomplete: 26 of 80 operations and projections missing from the spec, two
 * names it carried that no implementation ever had (`writeDown`, `writeUp`), and a census whose
 * three buckets had no room for the nineteen master-data operations at all.
 *
 * Same defect as IMPL-037 one folder over: `api-parameters.json` is held against the dispatcher's
 * constants in both languages, so code and contract cannot drift — and the prose both are derived
 * from was checked by nobody. The spec even names a guard for its own completeness; that guard is
 * real and holds `systemDescription` against the dispatcher, never this list.
 *
 * Deliberately NOT a prose check: one mechanical claim, that every declared operation and
 * projection is *named* in both documents. What they say about it stays human work.
 *
 * The SAME checks live in the PHP ApiSpecDocTest.
 */

const REPO_ROOT = join(import.meta.dirname, '..', '..', '..', '..');

const DOCS: ReadonlyArray<readonly [string, string]> = [
  ['api.md', join('knowledge', '50-spezifikation', 'api.md')],
  ['jurisdiction-profil.md', join('knowledge', '40-domaenenmodell', 'jurisdiction-profil.md')],
];

function readDoc(relative: string): string {
  return readFileSync(join(REPO_ROOT, relative), 'utf8');
}

function declared(): { operations: string[]; projections: string[] } {
  const raw = JSON.parse(
    readFileSync(join(REPO_ROOT, 'testing', 'testsuite', 'schema', 'api-parameters.json'), 'utf8'),
  ) as { operations?: Record<string, unknown>; projections?: Record<string, unknown> };

  return {
    operations: Object.keys(raw.operations ?? {}).sort(),
    projections: Object.keys(raw.projections ?? {}).sort(),
  };
}

/**
 * Inline-code spans, which is where an operation name legitimately appears. A name counts when a
 * span *starts* with it, so `runCosting(period)` and `createFiscalYear {year, start, end}` count and
 * a sentence mentioning the word does not.
 */
function codeSpans(doc: string): string[] {
  return [...doc.matchAll(/`([^`]+)`/gu)].map((match) => match[1] ?? '');
}

function names(spans: readonly string[], name: string): boolean {
  return spans.some(
    (span) => span === name || [' ', '(', '{', '/', '.'].some((boundary) => span.startsWith(name + boundary)),
  );
}

describe('the API documents name every declared operation', () => {
  it('parses the contract', () => {
    const { operations, projections } = declared();
    expect(operations.length).toBeGreaterThan(30);
    expect(projections.length).toBeGreaterThan(20);
  });

  it('parses the documents', () => {
    for (const [label, relative] of DOCS) {
      expect(codeSpans(readDoc(relative)).length, `${label}: no inline-code spans found`).toBeGreaterThan(50);
    }
  });

  for (const group of ['operations', 'projections'] as const) {
    it(`names every declared ${group} in both documents`, () => {
      const expected = declared()[group];
      for (const [label, relative] of DOCS) {
        const spans = codeSpans(readDoc(relative));
        const missing = expected.filter((name) => !names(spans, name));
        expect(
          missing,
          `${label} does not name these declared ${group} — a spec that calls its own list complete has to be held against the contract (IMPL-044)`,
        ).toEqual([]);
      }
    });
  }
});
