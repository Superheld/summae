import { existsSync, mkdtempSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { run } from '../src/cli.js';

/**
 * R-8, R-9, R-10 — the ways `init` and the workspace file let a caller down.
 *
 * These are deliberately malformed invocations, which is why they live here and not in
 * `testing/scenarios/`: those are the documentation in executable form and every step in them is
 * exemplary. Nonsense input belongs where it cannot be mistaken for a recommendation.
 *
 * The SAME cases live in the PHP CliInitRobustnessTest.
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

function freshDir(): string {
  return mkdtempSync(join(tmpdir(), 'summae-init-'));
}

function rulesFile(dir: string, rules: unknown): string {
  const path = join(dir, 'rules.json');
  writeFileSync(path, JSON.stringify(rules));
  return path;
}

describe('R-10 — --pack and --rules are alternatives, and saying so out loud', () => {
  it('rejects both instead of silently dropping --rules', () => {
    const dir = freshDir();
    const rules = rulesFile(dir, { accounts: [{ number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' }] });

    // The help calls them alternatives; the code took the pack branch and ignored the file
    // entirely, so a caller who meant "pack plus my accounts" silently got only the pack.
    const result = capture(['init', '--name', 'X', '--dir', dir, '--pack', 'de', '--rules', rules]);
    expect(result.error).toBe('E_INPUT_INVALID');
    expect(existsSync(join(dir, 'summae.json')), 'nothing may be written on a rejected call').toBe(false);
  });
});

describe('R-8 — init validates its year and leaves nothing half-built', () => {
  for (const value of ['', 'zweitausendsechsundzwanzig', '2026.5', '-1']) {
    it(`rejects --first-fiscal-year ${JSON.stringify(value)}`, () => {
      const dir = freshDir();
      // "" became year 0000 through Number("") === 0, and the workspace was written before
      // anything looked at it.
      const result = capture(['init', '--name', 'X', '--dir', dir, '--pack', 'de', '--first-fiscal-year', value]);
      expect(result.error).toBe('E_INPUT_INVALID');
      expect(existsSync(join(dir, 'summae.json'))).toBe(false);
    });
  }

  it('accepts a proper year', () => {
    const dir = freshDir();
    const result = capture(['init', '--name', 'X', '--dir', dir, '--pack', 'de', '--first-fiscal-year', '2027']);
    expect(result.initialized).toBe(true);
  });

  it('leaves the directory re-initialisable when master data fails', () => {
    const dir = freshDir();
    // Two accounts with the same number: the workspace is written first, then the second
    // createAccount throws — which used to leave a config and a database behind, so every retry
    // answered "Workspace already exists" and the directory was a dead end.
    const rules = rulesFile(dir, {
      accounts: [
        { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' },
        { number: '1200', name: 'Bank nochmal', type: 'asset', subtype: 'bank' },
      ],
    });

    const failed = capture(['init', '--name', 'X', '--dir', dir, '--rules', rules]);
    expect(failed.error, 'the duplicate must surface').toBe('E_ACCOUNT_NUMBER_TAKEN');
    expect(existsSync(join(dir, 'summae.json')), 'the failed attempt must not leave a workspace').toBe(false);

    // The point of the rollback: fixing the input and retrying has to work.
    const fixed = rulesFile(dir, { accounts: [{ number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' }] });
    expect(capture(['init', '--name', 'X', '--dir', dir, '--rules', fixed]).initialized).toBe(true);
  });
});

describe('R-9 — a damaged workspace file is reported, not defaulted away', () => {
  it('refuses a config without a tenantId instead of inventing one', () => {
    const dir = freshDir();
    const rules = rulesFile(dir, { accounts: [{ number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' }] });
    capture(['init', '--name', 'X', '--dir', dir, '--rules', rules]);

    // Parseable JSON, missing the one field that identifies the tenant. Every field was
    // defaulted and the tenantId regenerated, so the CLI opened the same database as a
    // different tenant and reported an empty ledger — books that look wiped.
    const configPath = join(dir, 'summae.json');
    const config = JSON.parse(readFileSync(configPath, 'utf8')) as Record<string, unknown>;
    delete config.tenantId;
    writeFileSync(configPath, JSON.stringify(config));

    const result = capture(['report', 'trialBalance', '--dir', dir, '--params', '{"fiscalYear":2026}']);
    expect(result.error).toBe('E_WORKSPACE_INVALID');
  });

  it('names the field it is missing', () => {
    const dir = freshDir();
    const rules = rulesFile(dir, { accounts: [] });
    capture(['init', '--name', 'X', '--dir', dir, '--rules', rules]);
    writeFileSync(join(dir, 'summae.json'), JSON.stringify({ tenantId: 'not-a-uuid' }));

    const result = capture(['report', 'trialBalance', '--dir', dir, '--params', '{"fiscalYear":2026}']);
    expect(result.error).toBe('E_WORKSPACE_INVALID');
    expect(String((result.details as Record<string, unknown>)?.field ?? '')).not.toBe('');
  });

  it('still works on an untouched workspace', () => {
    const dir = freshDir();
    const rules = rulesFile(dir, {
      accounts: [{ number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' }],
      fiscalYears: [{ year: 2026, start: '2026-01-01', end: '2026-12-31' }],
    });
    capture(['init', '--name', 'X', '--dir', dir, '--rules', rules]);
    const result = capture(['report', 'trialBalance', '--dir', dir, '--params', '{"fiscalYear":2026}']);
    expect(result.error, 'the guard must not forbid the healthy case').toBeUndefined();
    expect(readdirSync(dir)).toContain('summae.json');
  });
});
