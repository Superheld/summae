import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test, vi } from 'vitest';
import { run } from '../src/cli.js';

/** Einen CLI-Aufruf fahren und die JSON-Ausgabezeilen einsammeln. */
function capture(argv: string[]): string[] {
  const out: string[] = [];
  const spy = vi.spyOn(console, 'log').mockImplementation((msg: unknown) => {
    out.push(String(msg));
  });
  try {
    run(['node', 'summae', ...argv]);
  } finally {
    spy.mockRestore();
  }
  return out;
}

test('init → postVoucher → trialBalance über die CLI (persistente SQLite)', () => {
  const dir = mkdtempSync(join(tmpdir(), 'summae-cli-'));
  const rulesPath = join(dir, 'rules.json');
  writeFileSync(
    rulesPath,
    JSON.stringify({
      accounts: [
        { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' },
        { number: '8400', name: 'Erlöse 19%', type: 'revenue' },
        { number: '1776', name: 'USt 19%', type: 'liability', subtype: 'tax_out' },
      ],
      taxCodes: [
        { code: 'USt19', versions: [{ validFrom: '2024-01-01', validTo: null, rate: '19.00', taxAccount: '1776', reportingKey: '81' }] },
      ],
      fiscalYears: [{ year: 2026, start: '2026-01-01', end: '2026-12-31' }],
    }),
  );

  const init = capture(['init', '--name', 'Test GmbH', '--dir', dir, '--rules', rulesPath]);
  expect(JSON.parse(init[0] ?? '{}')).toMatchObject({
    initialized: true,
    tenant: 'Test GmbH',
    created: { accounts: 3, fiscalYears: 1 },
  });

  capture([
    'op',
    'postVoucher',
    '--dir',
    dir,
    '--input',
    JSON.stringify({
      voucher: { voucherNumber: 'R-1', voucherDate: '2026-03-01' },
      entryDate: '2026-03-01',
      text: 'Smoke',
      taxCode: 'USt19',
      direction: 'output',
      counterAccount: '1200',
      netLines: [{ account: '8400', money: { amount: '1000.00', currency: 'EUR' } }],
    }),
  ]);

  const report = capture(['report', 'trialBalance', '--dir', dir, '--params', '{"fiscalYear":2026}']).join('');
  // Die Buchung wurde persistiert und aus der DB neu projiziert.
  expect(report).toContain('1200');
  expect(report).toContain('8400');
  expect(report).toContain('1000.00');
});
