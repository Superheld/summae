import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { API_OPERATIONS, API_PROJECTIONS, OPERATION_PARAMETERS, PROJECTION_PARAMETERS } from '@superheld/summae-core';

/**
 * The manual documents every name the API publishes.
 *
 * summae's own rule is that documentation which stops being true turns a build red rather
 * than rotting on the page — the walkthrough scenarios do that for behaviour. This does it
 * for *coverage*: a capability can be finished, fixture-covered and published, and still be
 * undiscoverable because nobody wrote the section. Four of them were (`cashJournal`,
 * `unfinalizedEntries`, `systemDescription`, `allocate`), and nothing said so.
 *
 * Deliberately weak on purpose: it asks for a heading that names the operation or projection,
 * not for a well-written one. A guard that tried to judge the prose would be a guard nobody
 * keeps green. The counterpart on the PHP side reads the same file.
 *
 * **Since SPEC-019 it also reaches the vocabulary.** Coverage by NAME was not enough: `taxTag` was
 * published in the manual as "(object, no)" — no shape, no word that `vatReturn` counts only tagged
 * lines, nothing about the sign convention — and an embedding concluded from that that a capability
 * working since v0.4 was impossible, shipped a screen without a discount field, and recorded a
 * legal obligation as unimplementable. A field that is named and never explained is worse than one
 * that is absent: absent, they would have asked. So every key the input contract declares, at every
 * depth, must appear in the section of the operation that accepts it.
 *
 * Still deliberately weak, and in the same way: appearing in the prose is not the same as being
 * explained well. It catches the shape this defect had — declared, published, meaningless — and
 * leaves quality to review, which is the honest division.
 */
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../../..');
const manual = join(repoRoot, 'docs/handbuch/README.md');

function headings(): string[] {
  return readFileSync(manual, 'utf8')
    .split('\n')
    .filter((line) => /^#{3,4} /.test(line));
}

function undocumented(names: readonly string[]): string[] {
  const lines = headings();
  return names.filter((name) => !lines.some((line) => new RegExp(`\\b${name}\\b`).test(line)));
}

describe('the manual covers the published API surface', () => {
  it('documents every published operation', () => {
    expect(undocumented(API_OPERATIONS), 'every operation needs its own section in docs/handbuch/README.md').toEqual([]);
  });

  it('documents every published projection', () => {
    expect(undocumented(API_PROJECTIONS), 'every projection needs its own section in docs/handbuch/README.md').toEqual([]);
  });

  /**
   * Documented once for every operation in "Conventions for this whole section" rather than in each
   * of the thirty sections that accept it. The exemption is a list on purpose: adding to it is a
   * decision somebody makes visibly, not a hole that opens by itself.
   */
  const DOCUMENTED_GLOBALLY = new Set(['actor']);

  function declaredKeys(spec: Record<string, unknown>, out: Set<string>): void {
    const fields = spec.fields;
    if (fields !== null && typeof fields === 'object') {
      for (const [key, field] of Object.entries(fields as Record<string, unknown>)) {
        out.add(key);
        if (field !== null && typeof field === 'object') declaredKeys(field as Record<string, unknown>, out);
      }
    }
    const element = spec.element;
    if (element !== null && typeof element === 'object') declaredKeys(element as Record<string, unknown>, out);
  }

  function sectionOf(name: string): string | null {
    const lines = readFileSync(manual, 'utf8').split('\n');
    const heads = lines.map((l, i) => [i, l] as const).filter(([, l]) => /^#{3,4} /.test(l));
    for (let idx = 0; idx < heads.length; idx++) {
      const entry = heads[idx];
      if (entry === undefined) continue;
      const [start, line] = entry;
      if (new RegExp(`\\b${name}\\b`).test(line)) {
        const next = heads[idx + 1];
        return lines.slice(start, next === undefined ? lines.length : next[0]).join('\n');
      }
    }
    return null;
  }

  it('explains every input key the contract declares, at every depth', () => {
    const gaps: string[] = [];
    for (const table of [OPERATION_PARAMETERS, PROJECTION_PARAMETERS]) {
      for (const [op, params] of Object.entries(table)) {
        const section = sectionOf(op) ?? '';
        const want = new Set<string>(Object.keys(params));
        for (const spec of Object.values(params)) declaredKeys(spec as unknown as Record<string, unknown>, want);
        for (const key of want) {
          if (DOCUMENTED_GLOBALLY.has(key)) continue;
          if (!new RegExp(`\\b${key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`).test(section)) {
            gaps.push(`${op}.${key}`);
          }
        }
      }
    }
    expect(
      gaps,
      'a declared input key must be named in the manual section of the operation that accepts it — ' +
        'a field that is published and never explained is worse than one that is absent (SPEC-019)',
    ).toEqual([]);
  });

  it('reads the manual it claims to read', () => {
    // Without this a moved or renamed file would leave both tests above passing on nothing.
    expect(headings().length).toBeGreaterThan(40);
  });
});
