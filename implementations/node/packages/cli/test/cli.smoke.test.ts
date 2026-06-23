import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { expect, test, vi } from 'vitest';
import { run } from '../src/cli.js';

/** Run one CLI call and collect the JSON output lines. */
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

test('init → postVoucher → trialBalance via the CLI (persistent SQLite)', () => {
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
  // The posting was persisted and re-projected from the DB.
  expect(report).toContain('1200');
  expect(report).toContain('8400');
  expect(report).toContain('1000.00');
});

test('init --pack de → post → balance sheet balances (pack library chosen by frontend)', () => {
  const dir = mkdtempSync(join(tmpdir(), 'summae-cli-de-'));

  // Frontend selects the shipped de pack from the library — no inline.
  const init = capture(['init', '--name', 'DE GmbH', '--pack', 'de', '--first-fiscal-year', '2026', '--dir', dir]);
  expect(JSON.parse(init[0] ?? '{}')).toMatchObject({
    initialized: true,
    created: { accounts: 40, fiscalYears: 1 },
  });

  capture([
    'op',
    'postVoucher',
    '--dir',
    dir,
    '--input',
    JSON.stringify({
      voucher: { voucherNumber: 'AR-1', voucherDate: '2026-03-01' },
      entryDate: '2026-03-01',
      taxCode: 'USt19',
      direction: 'output',
      counterAccount: '1200',
      netLines: [{ account: '4000', money: { amount: '1000.00', currency: 'EUR' } }],
    }),
  ]);

  // Journal: output VAT posting on the neutral DE numbers.
  const tb = capture(['report', 'trialBalance', '--dir', dir, '--params', '{"fiscalYear":2026}']).join('');
  expect(tb).toContain('4000');
  expect(tb).toContain('-1000.00');
  expect(tb).toContain('-190.00');

  // Balance sheet: via the bundled mappings, assets == liabilities.
  const bs = JSON.parse(
    capture([
      'report',
      'balanceSheet',
      '--dir',
      dir,
      '--params',
      '{"asOf":"2026-12-31","mapping":"de-bilanz","incomeMapping":"de-guv"}',
    ])[0] ?? '{}',
  ) as { assetsTotal: string; liabilitiesAndEquityTotal: string };
  expect(bs.assetsTotal).toBe('1190.00');
  expect(bs.liabilitiesAndEquityTotal).toBe('1190.00');
});

test('init --pack us → post → balance sheet balances (pack library chosen by frontend)', () => {
  const dir = mkdtempSync(join(tmpdir(), 'summae-cli-us-'));

  // Frontend selects the shipped us pack from the library — no inline.
  const init = capture(['init', '--name', 'US Corp', '--currency', 'USD', '--pack', 'us', '--first-fiscal-year', '2026', '--dir', dir]);
  expect(JSON.parse(init[0] ?? '{}')).toMatchObject({
    initialized: true,
    created: { accounts: 35, fiscalYears: 1 },
  });

  capture([
    'op',
    'postVoucher',
    '--dir',
    dir,
    '--input',
    JSON.stringify({
      voucher: { voucherNumber: 'INV-1', voucherDate: '2026-03-01' },
      entryDate: '2026-03-01',
      taxCode: 'SALETAX',
      direction: 'output',
      counterAccount: '1010',
      netLines: [{ account: '4000', money: { amount: '1000.00', currency: 'USD' } }],
    }),
  ]);

  // Journal: single-stage sales tax on the own US numbers (2100 Sales Tax Payable).
  const tb = capture(['report', 'trialBalance', '--dir', dir, '--params', '{"fiscalYear":2026}']).join('');
  expect(tb).toContain('4000');
  expect(tb).toContain('-1000.00');
  expect(tb).toContain('-70.00');

  // Balance sheet: via the bundled US-GAAP mappings, assets == liabilities.
  const bs = JSON.parse(
    capture([
      'report',
      'balanceSheet',
      '--dir',
      dir,
      '--params',
      '{"asOf":"2026-12-31","mapping":"us-gaap-balance-sheet","incomeMapping":"us-gaap-income-statement"}',
    ])[0] ?? '{}',
  ) as { assetsTotal: string; liabilitiesAndEquityTotal: string };
  expect(bs.assetsTotal).toBe('1070.00');
  expect(bs.liabilitiesAndEquityTotal).toBe('1070.00');
});
