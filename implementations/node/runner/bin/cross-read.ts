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
 * Cross-Test-Vergleichs-Hub (SF-15), BEIDE Richtungen — bewusst komplett in Node,
 * weil JS die {}/[]-Unterscheidung beim Parsen bewahrt (PHPs json_decode(assoc)
 * nicht):
 *  1. PHP→Node: Node öffnet die von `cross-export.php` geschriebenen `*.sqlite`,
 *     berechnet journalExport und vergleicht mit PHPs Oracle (`*.expected.json`).
 *  2. Node→PHP: vergleicht Nodes Oracle (`*.node.expected.json`) mit PHPs
 *     Ergebnis (`*.php-actual.json`, von `cross-read.php` aus der Node-DB berechnet).
 *
 * journalExport ist konfig-/placeholder-frei. Zeitstempel werden als Instant
 * verglichen (SPEC-FINDING F-CROSS-001), alles andere byte-genau.
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

/** tenant_id aus den Daten ermitteln — ein fremdes Package kennt sie nicht vorab. */
function discoverTenantId(db: SyncDb): string | null {
  for (const table of TENANT_TABLES) {
    const row = db.first(db.table(`${TABLE_PREFIX}${table}`).select('tenant_id'));
    if (row !== null && typeof row.tenant_id === 'string') return row.tenant_id;
  }
  return null;
}

/**
 * Vergleichbar machen (alles wegen F-CROSS-001 = Zeitstempel-Format-Divergenz):
 * - `exportedAt` (Export-Erzeugungszeit) raus — keine Daten.
 * - `at`/`recordedAt` auf kanonischen Instant (UTC, ms) normieren. PHP serialisiert
 *   ATOM (Offset, ohne ms), Node toISOString (UTC, ms) — gleicher Moment, andere
 *   Schreibweise; verglichen wird der Wert.
 * - `contentHashes` raus: das sind sha256-Digests über die Roh-Stream-Bytes, sie
 *   backen das Zeitstempel-Format ein und lassen sich nicht normieren. Die Streams
 *   selbst (in `data`) werden ohnehin direkt + vollständig verglichen — der Digest
 *   ist dazu redundant.
 */
function normalizeForCompare(value: unknown): unknown {
  if (Array.isArray(value)) {
    value.forEach((item, i) => {
      value[i] = normalizeForCompare(item);
    });
    return value;
  }
  if (value === null || typeof value !== 'object') return value;
  const obj = value as Record<string, unknown>;
  for (const [key, val] of Object.entries(obj)) {
    if (key === 'exportedAt' || key === 'contentHashes') delete obj[key];
    else if ((key === 'at' || key === 'recordedAt') && typeof val === 'string') obj[key] = new Date(val).toISOString();
    else obj[key] = normalizeForCompare(val);
  }
  return obj;
}

function canonicalOfFile(path: string): string {
  return canonicalJson(normalizeForCompare(JSON.parse(readFileSync(path, 'utf8')) as Record<string, unknown>));
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
  return `…${a.slice(at, i + 30)}\n      B: …${b.slice(at, i + 30)}  (ab Position ${i})`;
}

// ── Richtung 1: PHP schreibt, Node liest ────────────────────────────────────
const phpToNode: Result = { green: 0, red: 0, failures: [] };
for (const file of readdirSync(dir).filter((f) => f.endsWith('.sqlite') && !f.endsWith('.node.sqlite')).sort()) {
  const name = file.slice(0, -'.sqlite'.length);
  const db = new SyncDb(join(dir, file));
  try {
    const tenantId = discoverTenantId(db);
    if (tenantId === null) {
      phpToNode.red++;
      phpToNode.failures.push(`${name}: keine tenant_id`);
      continue;
    }
    const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
    const { tenantName, currency } = tenantConfig(name);
    const tenant = DatabaseTenantFactory.build(db, tenantName, Currency.of(currency), clock, new DeterministicIdGenerator(clock), {
      tenantId: Uuid.fromString(tenantId),
    });
    const actual = canonicalJson(normalizeForCompare(new TenantOperations(tenant).project('journalExport', { format: 'gobd-z3' })));
    const expected = canonicalOfFile(join(dir, `${name}.expected.json`));
    if (actual === expected) phpToNode.green++;
    else {
      phpToNode.red++;
      phpToNode.failures.push(`${name}: A: …${firstDiff(actual, expected)}`);
    }
  } finally {
    db.close();
  }
}

// ── Richtung 2: Node schreibt, PHP liest (Vergleich hier in Node) ────────────
const nodeToPhp: Result = { green: 0, red: 0, failures: [] };
for (const file of readdirSync(dir).filter((f) => f.endsWith('.php-actual.json')).sort()) {
  const name = file.slice(0, -'.php-actual.json'.length);
  const oracle = join(dir, `${name}.node.expected.json`);
  if (!existsSync(oracle)) continue;
  const actual = canonicalOfFile(join(dir, file));
  const expected = canonicalOfFile(oracle);
  if (actual === expected) nodeToPhp.green++;
  else {
    nodeToPhp.red++;
    nodeToPhp.failures.push(`${name}: A=PHP: …${firstDiff(actual, expected)}`);
  }
}

function report(label: string, r: Result): void {
  console.log(`${label}: ${r.green} grün, ${r.red} rot`);
  for (const f of r.failures.slice(0, 5)) console.log(`  ${f}`);
}

console.log('');
report('Cross-Test PHP→Node (journalExport)', phpToNode);
report('Cross-Test Node→PHP (journalExport)', nodeToPhp);
process.exit(phpToNode.red === 0 && nodeToPhp.red === 0 ? 0 : 1);
