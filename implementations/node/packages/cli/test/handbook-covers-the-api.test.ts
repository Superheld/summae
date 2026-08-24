import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { API_OPERATIONS, API_PROJECTIONS } from '@superheld/summae-core';

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

  it('reads the manual it claims to read', () => {
    // Without this a moved or renamed file would leave both tests above passing on nothing.
    expect(headings().length).toBeGreaterThan(40);
  });
});
