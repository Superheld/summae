import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { allExitCodes, exitCodeFor } from '../src/exit-codes.js';

/**
 * Drift guard for the exit-code mapping (IMPL-018).
 *
 * `testing/testsuite/fehlerkatalog.md` is the normative list of error codes; `exitCodeFor` turns
 * it into the numbers a script branches on. Nothing compared the two, so four codes reached the
 * catalogue and never reached the mapping — they exited `1`, which the CLI documents as *unknown
 * error*, i.e. indistinguishable from a summae crash. The JSON on stderr still named the code, so
 * a human reader lost nothing and no test went red: exactly the kind of gap this comparison
 * closes. Its PHP twin (`ExitCodesTest`) asserts the same thing against the same file, so the two
 * languages cannot drift apart either.
 *
 * Deliberately without an exception list: a code that is declared but not yet thrown gets its
 * number reserved (`E_AMOUNT_SCALE_MISMATCH`). An allowlist here would be the same hole again.
 */
const here = dirname(fileURLToPath(import.meta.url));
const catalogPath = join(here, '..', '..', '..', '..', '..', 'testing', 'testsuite', 'fehlerkatalog.md');

/**
 * The catalogue lists its codes in tables, one per row: `| \`E_…\` | invariant | fixture |`.
 * Codes mentioned in the surrounding prose (`E_UNEXPECTED`) are explanation, not contract, and are
 * not matched — the anchor is the start of a table row.
 */
function catalogCodes(): string[] {
  const raw = readFileSync(catalogPath, 'utf8');
  const codes = [...raw.matchAll(/^\| `(E_[A-Z_]+)`/gm)].map((match) => match[1]!);

  expect(codes.length, 'the catalogue parse must not silently yield nothing').toBeGreaterThan(30);

  return [...new Set(codes)];
}

describe('exit codes', () => {
  it('gives every catalogued error code an exit code of its own', () => {
    const withoutExit = catalogCodes().filter((code) => exitCodeFor(code) === 1);

    expect(withoutExit, 'these catalogued codes fall through to exit 1 (IMPL-018)').toEqual([]);
  });

  /**
   * The other direction, and the reason the two lists are compared as sets: a code that has a
   * number here but no row in the catalogue is invisible to every machine check — the knowledge
   * base's `validate.py` never sees it, and the test above cannot miss it. `E_NOT_IMPLEMENTED`
   * sat in exactly that blind spot until 2026-08-16.
   */
  it('maps no error code the catalogue does not know', () => {
    const cataloged = new Set(catalogCodes());
    const uncataloged = allExitCodes().filter((code) => !cataloged.has(code));

    expect(uncataloged, 'these codes have an exit code but no catalogue row').toEqual([]);
  });

  it('gives no two error codes the same exit code', () => {
    const codes = catalogCodes();

    expect(new Set(codes.map(exitCodeFor)).size).toBe(codes.length);
  });

  /**
   * The numbers are a published contract (index + 10, append-only): reordering or inserting would
   * silently renumber every later code. These anchors pin the head, the middle and the current
   * tail, so an insertion cannot pass unnoticed — while a plain append, which shifts nothing,
   * stays free of test churn.
   */
  it('keeps the numbers stable', () => {
    expect(exitCodeFor('E_ENTRY_UNBALANCED')).toBe(10);
    expect(exitCodeFor('E_INPUT_INVALID')).toBe(45);
    expect(exitCodeFor('E_AMOUNT_SCALE_MISMATCH')).toBe(53);
  });

  it('leaves an unknown code as the unknown error', () => {
    expect(exitCodeFor('E_NOT_A_REAL_CODE')).toBe(1);
  });
});
