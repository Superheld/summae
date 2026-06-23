import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const srcDir = join(here, '..', 'src');

/**
 * Axis 2 (jurisdiction freedom): the law-free core must emit no jurisdiction-specific
 * user-facing TEXT. Projection labels come from the pack (the mapping), never as
 * hard-coded German strings in the core — otherwise a German label leaks into every
 * jurisdiction's output (the cash-basis "Vereinnahmte USt" bug). Regression guard for that
 * class. (Mechanism *names* like `reverse_charge` are a separate, documented closed/open
 * matter — not covered here.) Counterpart to PHP's SubstrateBoundaryTest.
 */
const FORBIDDEN = [
  'Vereinnahmte', 'Vorsteuer', 'Umsatzsteuer', 'Kleinunternehmer', 'Finanzamt',
  'Betriebsausgabe', 'Betriebseinnahme', 'Wertabgabe', 'Bewirtung', 'Skonto', 'Erlös',
];

function tsFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...tsFiles(path));
    else if (entry.isFile() && entry.name.endsWith('.ts')) out.push(path);
  }
  return out;
}

describe('core emits no hard-coded jurisdiction labels', () => {
  it('contains no German jurisdiction label text in src', () => {
    const violations: string[] = [];
    for (const file of tsFiles(srcDir)) {
      const contents = readFileSync(file, 'utf8');
      for (const term of FORBIDDEN) {
        if (contents.includes(term)) {
          violations.push(`${file.slice(srcDir.length + 1)} contains jurisdiction label text "${term}"`);
        }
      }
    }
    expect(violations, 'the law-free core must not hard-code jurisdiction label text (use the pack/mapping)').toEqual([]);
  });

  // The same axis, for code comments: the law-free core must not cite a jurisdiction's
  // statutes (the litmus test "does your code cite a statute -> wrong layer"). A statute
  // citation is provenance that belongs in the pack docs, not in the substrate/policies.
  // Matches "§ 17 UStG", "§ 4 Abs. 3 EStG" etc. — NOT doc-section refs like "determinismus.md §3"
  // (no statute keyword follows). Locks in the jurisdiction-comment cleanup.
  const STATUTE = /§\s*\d+[a-z]?\s*(Abs\.?|Nr\.?|UStG|EStG|HGB|BGB|AO|GewStG|KStG)/;

  it('cites no jurisdiction statutes in src', () => {
    const violations: string[] = [];
    for (const file of tsFiles(srcDir)) {
      const lines = readFileSync(file, 'utf8').split('\n');
      lines.forEach((line, index) => {
        if (STATUTE.test(line)) {
          violations.push(`${file.slice(srcDir.length + 1)}:${index + 1} cites a statute`);
        }
      });
    }
    expect(violations, 'the law-free core must not cite jurisdiction statutes (provenance belongs in the pack)').toEqual([]);
  });
});
