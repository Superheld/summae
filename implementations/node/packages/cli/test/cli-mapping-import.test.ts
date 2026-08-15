import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { run } from '../src/cli.js';

/**
 * R-4 — `importMapping` answered `imported: true` and stored nothing.
 *
 * Mappings live in a registry the CLI rebuilds from `summae.json` on every invocation, and the
 * import never wrote back. Inside one process it worked, which is why it looks fine in a unit
 * test; across two calls — the only way a CLI is ever used — the mapping was gone, and the report
 * that followed failed as though it had never been imported. The documented flow could not work.
 *
 * The SAME cases live in the PHP CliMappingImportTest.
 */
function capture(argv: string[]): Record<string, unknown> {
  const out: string[] = [];
  const spy = vi.spyOn(console, 'log').mockImplementation((msg: unknown) => void out.push(String(msg)));
  const code = process.exitCode;
  try {
    run(['node', 'summae', ...argv]);
  } finally {
    spy.mockRestore();
    process.exitCode = code;
  }
  return JSON.parse(out[0] ?? '{}') as Record<string, unknown>;
}

const MAPPING = {
  id: 'guv-test',
  kind: 'income-statement',
  version: '1',
  positions: [{ key: '1', label: 'Umsatzerlöse', accounts: [{ from: '8000', to: '8999' }] }],
};

function workspace(): string {
  const dir = mkdtempSync(join(tmpdir(), 'summae-mapping-'));
  const rules = join(dir, 'rules.json');
  writeFileSync(
    rules,
    JSON.stringify({
      accounts: [
        { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' },
        { number: '8400', name: 'Erlöse', type: 'revenue' },
      ],
      fiscalYears: [{ year: 2026, start: '2026-01-01', end: '2026-12-31' }],
    }),
  );
  capture(['init', '--name', 'X', '--dir', dir, '--rules', rules]);
  return dir;
}

describe('R-4 — an imported mapping survives the process that imported it', () => {
  it('is usable by a later, separate invocation', () => {
    const dir = workspace();

    const imported = capture(['op', 'importMapping', '--dir', dir, '--input', JSON.stringify({ mapping: MAPPING })]);
    expect(imported.imported, 'the import itself already reported success before this fix').toBe(true);

    // The second call is the whole point: a new process, a registry rebuilt from summae.json.
    const report = capture([
      'report',
      'incomeStatement',
      '--dir',
      dir,
      '--params',
      JSON.stringify({ fiscalYear: 2026, mapping: 'guv-test' }),
    ]);
    expect(report.error, 'the mapping must still be there').toBeUndefined();
    expect(report.positions).toBeDefined();
  });

  it('replaces a mapping of the same id rather than collecting duplicates', () => {
    const dir = workspace();
    capture(['op', 'importMapping', '--dir', dir, '--input', JSON.stringify({ mapping: MAPPING })]);

    const changed = { ...MAPPING, positions: [{ key: '1', label: 'Erlöse neu', accounts: [{ from: '8000', to: '8999' }] }] };
    capture(['op', 'importMapping', '--dir', dir, '--input', JSON.stringify({ mapping: changed })]);

    // Two mappings with one id would be E_MAPPING_OVERLAP territory on the next load.
    const report = capture([
      'report',
      'incomeStatement',
      '--dir',
      dir,
      '--params',
      JSON.stringify({ fiscalYear: 2026, mapping: 'guv-test' }),
    ]);
    expect(report.error).toBeUndefined();
  });

  it('leaves the workspace alone when the import fails', () => {
    const dir = workspace();
    const overlapping = {
      id: 'kaputt',
      kind: 'income-statement',
      version: '1',
      positions: [
        { key: '1', label: 'A', accounts: [{ from: '8000', to: '8999' }] },
        { key: '2', label: 'B', accounts: [{ from: '8400', to: '8400' }] },
      ],
    };

    const failed = capture(['op', 'importMapping', '--dir', dir, '--input', JSON.stringify({ mapping: overlapping })]);
    expect(failed.error).toBe('E_MAPPING_OVERLAP');

    const report = capture([
      'report',
      'incomeStatement',
      '--dir',
      dir,
      '--params',
      JSON.stringify({ fiscalYear: 2026, mapping: 'kaputt' }),
    ]);
    expect(report.error, 'a rejected mapping must not be persisted').toBe('E_INPUT_INVALID');
  });
});
