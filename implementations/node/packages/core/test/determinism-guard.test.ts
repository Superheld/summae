import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

const here = dirname(fileURLToPath(import.meta.url));
const srcDir = join(here, '..', 'src');

/**
 * Iron invariant (determinism): same input -> byte-identical result. The core must
 * never reach for the wall clock or a randomness source directly — time and ids enter
 * only through the injected `Clock` / id generator (the runner wires `FixedClock` +
 * a deterministic generator). This guard fails loudly if domain/policy code calls a
 * non-deterministic primitive, the class of bug a single fixture cannot express.
 *
 * Allowlisted: the two seam files that ARE the real (production) Clock / id source —
 * `clock.ts` (SystemClock) and `uuid.ts` (UUIDv7 generation). Everything else must go
 * through the injected ports. Counterpart to PHP's DeterminismBoundaryTest.
 */
const ALLOW = new Set(['clock.ts', 'uuid.ts']);

const FORBIDDEN: Array<{ re: RegExp; label: string }> = [
  { re: /\bDate\.now\s*\(/, label: 'Date.now()' },
  { re: /\bnew\s+Date\s*\(\s*\)/, label: 'new Date() (argless = wall clock)' },
  { re: /\bMath\.random\s*\(/, label: 'Math.random()' },
  { re: /\bperformance\.now\s*\(/, label: 'performance.now()' },
  { re: /\brandomBytes\s*\(/, label: 'randomBytes()' },
  { re: /\brandomUUID\s*\(/, label: 'randomUUID()' },
  { re: /\brandomInt\s*\(/, label: 'randomInt()' },
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

describe('core uses no non-deterministic primitives', () => {
  it('calls no wall-clock / randomness source outside the injected ports', () => {
    const violations: string[] = [];
    for (const file of tsFiles(srcDir)) {
      const base = file.slice(file.lastIndexOf('/') + 1);
      if (ALLOW.has(base)) continue;
      const lines = readFileSync(file, 'utf8').split('\n');
      lines.forEach((line, index) => {
        for (const { re, label } of FORBIDDEN) {
          if (re.test(line)) {
            violations.push(`${file.slice(srcDir.length + 1)}:${index + 1} uses ${label}`);
          }
        }
      });
    }
    expect(
      violations,
      'the core must take time/ids only through the injected Clock/id generator',
    ).toEqual([]);
  });
});
