import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import {
  canonicalJson,
  Currency,
  DeterministicIdGenerator,
  FixedClock,
  TenantOperations,
  Uuid,
} from '@superheld/summae-core';
import { DatabaseTenantFactory, SyncDb, TABLE_PREFIX } from '@superheld/summae-knex';
import { loadFixtures } from '../src/fixture-loader.js';

/**
 * Cross-test comparison hub (SF-15), BOTH directions — deliberately fully in Node,
 * because JS preserves the {}/[] distinction on parsing (PHP's json_decode(assoc)
 * does not):
 *  1. PHP→Node: Node opens the `*.sqlite` written by `cross-export.php`,
 *     computes journalExport and compares with PHP's oracle (`*.expected.json`).
 *  2. Node→PHP: compares Node's oracle (`*.node.expected.json`) with PHP's
 *     result (`*.php-actual.json`, computed by `cross-read.php` from the Node DB).
 *
 * journalExport is config-/placeholder-free. The **full** canonical journalExport
 * is compared byte-exact — incl. the sha256 contentHashes and the
 * exportedAt (same fixed clock on both sides). Since the timestamp canonicalization
 * (F-CROSS-001 solved: UTC-Z/ms in both languages) no exceptions are needed anymore.
 */

const dirArg = process.argv.slice(2).find((a) => a.startsWith('--dir='));
const dir = dirArg ? dirArg.slice('--dir='.length) : join(process.cwd(), '../../.cross-dbs');

const fixturesByName = new Map(loadFixtures().map((f) => [f.name, f]));

function tenantConfig(name: string): { tenantName: string; currency: string } {
  const setup = fixturesByName.get(name)?.setup;
  const tenant = setup && typeof setup.tenant === 'object' ? (setup.tenant as Record<string, unknown>) : {};
  return {
    tenantName: typeof tenant.name === 'string' ? tenant.name : 'Tenant',
    currency: typeof tenant.baseCurrency === 'string' ? tenant.baseCurrency : 'EUR',
  };
}

const TENANT_TABLES = ['accounts', 'journal_entries', 'fiscal_years', 'vouchers', 'partners', 'assets', 'open_items', 'audit_log'];

/** Determine tenant_id from the data — a foreign package does not know it in advance. */
function discoverTenantId(db: SyncDb): string | null {
  for (const table of TENANT_TABLES) {
    const row = db.first(db.table(`${TABLE_PREFIX}${table}`).select('tenant_id'));
    if (row !== null && typeof row.tenant_id === 'string') return row.tenant_id;
  }
  return null;
}

interface Result {
  green: number;
  red: number;
  failures: string[];
}

function firstDiff(a: string, b: string): string {
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i++;
  const at = Math.max(0, i - 30);
  return `…${a.slice(at, i + 30)}\n      B: …${b.slice(at, i + 30)}  (from position ${i})`;
}

// ── Direction 1: PHP writes, Node reads ─────────────────────────────────────
const phpToNode: Result = { green: 0, red: 0, failures: [] };
for (const file of readdirSync(dir).filter((f) => f.endsWith('.sqlite') && !f.endsWith('.node.sqlite')).sort()) {
  const name = file.slice(0, -'.sqlite'.length);
  const db = new SyncDb(join(dir, file));
  try {
    const tenantId = discoverTenantId(db);
    if (tenantId === null) {
      phpToNode.red++;
      phpToNode.failures.push(`${name}: no tenant_id`);
      continue;
    }
    const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
    const { tenantName, currency } = tenantConfig(name);
    const tenant = DatabaseTenantFactory.build(db, tenantName, Currency.of(currency), clock, new DeterministicIdGenerator(clock), {
      tenantId: Uuid.fromString(tenantId),
    });
    const actual = canonicalJson(new TenantOperations(tenant).project('journalExport', { format: 'gobd-z3' }));
    const expected = readFileSync(join(dir, `${name}.expected.json`), 'utf8');
    if (actual === expected) phpToNode.green++;
    else {
      phpToNode.red++;
      phpToNode.failures.push(`${name}: A: …${firstDiff(actual, expected)}`);
    }
  } finally {
    db.close();
  }
}

// ── Direction 2: Node writes, PHP reads (comparison here in Node) ────────────
const nodeToPhp: Result = { green: 0, red: 0, failures: [] };
for (const file of readdirSync(dir).filter((f) => f.endsWith('.php-actual.json')).sort()) {
  const name = file.slice(0, -'.php-actual.json'.length);
  const oracle = join(dir, `${name}.node.expected.json`);
  if (!existsSync(oracle)) continue;
  const actual = readFileSync(join(dir, file), 'utf8');
  const expected = readFileSync(oracle, 'utf8');
  if (actual === expected) nodeToPhp.green++;
  else {
    nodeToPhp.red++;
    nodeToPhp.failures.push(`${name}: A=PHP: …${firstDiff(actual, expected)}`);
  }
}

function report(label: string, r: Result): void {
  console.log(`${label}: ${r.green} green, ${r.red} red`);
  for (const f of r.failures.slice(0, 5)) console.log(`  ${f}`);
}

console.log('');
report('Cross-test PHP→Node (journalExport)', phpToNode);
report('Cross-test Node→PHP (journalExport)', nodeToPhp);
process.exit(phpToNode.red === 0 && nodeToPhp.red === 0 ? 0 : 1);
