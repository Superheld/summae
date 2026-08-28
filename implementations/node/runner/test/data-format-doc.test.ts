import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { FORMAT_VERSION } from '@superheld/summae-core';

/**
 * Gate for `knowledge/50-spezifikation/datenformat.md` — the NORMATIVE data-format document.
 *
 * The authority here used to sit upside down (IMPL-037). `format.schema.json`'s `$id` is held
 * against `FORMAT_VERSION` by `format-version.test.ts` and its PHP twin, so code and schema cannot
 * drift. The prose both of them are derived *from* was checked by nobody — and so the document
 * described format 0.6 while the product shipped 0.7, for weeks, with the whole gate green. The
 * derived artefacts were guarded; the source was not.
 *
 * This is deliberately NOT a prose check — that is neither achievable nor wanted. Three narrow,
 * mechanical claims:
 *
 *   1. the version in the document's title and in its `$id` line equals `FORMAT_VERSION`;
 *   2. no `## v0.x` section is missing between the oldest documented version and the current one,
 *      so a release cannot skip its own write-up the way 0.7 did;
 *   3. every `$defs` key the schema declares is named in the document.
 *
 * The SAME checks live in the PHP DataFormatDocTest.
 */

const REPO_ROOT = join(import.meta.dirname, '..', '..', '..', '..');
const DOC = join(REPO_ROOT, 'knowledge', '50-spezifikation', 'datenformat.md');
const SCHEMA = join(REPO_ROOT, 'testing', 'testsuite', 'schema', 'format.schema.json');

function readDoc(): string {
  return readFileSync(DOC, 'utf8');
}

function schemaDefs(): string[] {
  const schema = JSON.parse(readFileSync(SCHEMA, 'utf8')) as { $defs?: Record<string, unknown> };
  return Object.keys(schema.$defs ?? {});
}

/**
 * A version without a section is a release that skipped its own write-up, which is exactly what
 * happened to 0.7. Minor versions only — the format has never had a major step, and inventing a
 * rule for one that does not exist would be guessing.
 */
function documentedMinors(): number[] {
  const matches = readDoc().matchAll(/^## v0\.(\d+)\b/gm);
  return [...new Set([...matches].map((m) => Number(m[1])))].sort((a, b) => a - b);
}

describe('datenformat.md', () => {
  it('is actually parsed', () => {
    expect(documentedMinors().length, 'no version sections found — did the format change?').toBeGreaterThan(3);
  });

  it('states the current format version in its title', () => {
    const firstLine = readDoc().split('\n')[0] ?? '';
    const found = /^# Datenformat-Spezifikation v(\d+\.\d+)/.exec(firstLine);
    expect(found, `the document must open with its own version — got: ${firstLine}`).not.toBeNull();
    expect(
      found?.[1],
      'the normative document describes a format the product has left behind (IMPL-037)',
    ).toBe(FORMAT_VERSION);
  });

  // The document repeats the schema version in its opening note, in the form
  // "Schema-Datei `$id` → **0.9**". That sentence is what a reader takes away, so it is held
  // against the same source as the title.
  it('states the current format version in its $id line', () => {
    const found = /Schema-Datei `\$id` → \*\*(\d+\.\d+)\*\*/u.exec(readDoc());
    expect(found, 'the opening note must name the schema version it describes').not.toBeNull();
    expect(found?.[1]).toBe(FORMAT_VERSION);
  });

  it('skips no version between the oldest documented one and the current one', () => {
    const minors = documentedMinors();
    const [major, current] = FORMAT_VERSION.split('.').map(Number) as [number, number];
    expect(major, 'this check assumes a 0.x format — a 1.0 needs its own rule, not a silent pass').toBe(0);

    const expected = Array.from({ length: current - (minors[0] as number) + 1 }, (_, i) => (minors[0] as number) + i);
    const missing = expected.filter((v) => !minors.includes(v));

    expect(
      missing,
      'these format versions have no `## v0.x` section — a release that skipped its own write-up (IMPL-037)',
    ).toEqual([]);
  });

  it('writes up the version that ships', () => {
    const current = Number(FORMAT_VERSION.split('.')[1]);
    expect(documentedMinors(), `format ${FORMAT_VERSION} ships but \`## v0.${current}\` is not written up`).toContain(
      current,
    );
  });

  // Every `$defs` key is named somewhere in the document — via the index in
  // § "Wo jeder `$defs`-Schlüssel spezifiziert ist" where the prose calls it something else
  // (`entryLine` is "Position", `manifest` is "Export-Manifest"). An object the schema knows and
  // the specification does not name is the same gap from the other side.
  it('names every schema definition', () => {
    const doc = readDoc();
    const defs = schemaDefs();
    expect(defs.length, 'no $defs in the schema — did the schema change shape?').toBeGreaterThan(0);

    expect(
      defs.filter((key) => !doc.includes(key)),
      'the schema declares these and the normative document never names them',
    ).toEqual([]);
  });
});
