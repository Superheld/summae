import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import Ajv2020, { type ValidateFunction } from 'ajv/dist/2020.js';
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
 * (SPEC-C01 solved: UTC-Z/ms in both languages) no exceptions are needed anymore.
 *
 *  3. The **stored aggregates** — the half of the shared data format that never leaves through
 *     `journalExport` and was therefore crossed by nothing until 2026-08-29 (IMPL-046). Assets,
 *     costing runs, provisions, deferrals and inventory valuations live as JSON documents in the
 *     `summae_*` tables; on a shared database (SF-15: one store, several engines) two engines that
 *     write them differently are two truths. Each document is compared byte-exact between the two
 *     stores AND validated against `format.schema.json`, whose 0.10 `$defs` describe exactly these
 *     documents. Its first run found a difference in one fixture out of 126: PHP wrote an empty map
 *     as `[]`, Node as `{}` — which is also why this comparison belongs here rather than in PHP.
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
    const tenant = DatabaseTenantFactory.build(db, clock, new DeterministicIdGenerator(clock), {
      tenantId: Uuid.fromString(tenantId),
      name: tenantName,
      baseCurrency: Currency.of(currency),
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

// ── Direction 3: the stored aggregates, compared and validated ──────────────
//
// Byte-exact on the payload column, because that is what a second engine reads back. The schema
// check runs over BOTH stores: a document that both engines get equally wrong is still wrong, and
// the whole point of declaring these five kinds in the format was to have something to be wrong
// against.
// `null` = compared but not validated: the two columnar tables store JSON no `$defs` entry
// describes yet (IMPL-047). Byte-equality across engines is the half that can be had today, and it
// is the half a shared database needs first.
const AGGREGATE_COLUMNS: ReadonlyArray<readonly [string, ReadonlyArray<readonly [string, string | null]>]> = [
  ['assets', [['payload', 'asset'], ['state', 'assetState']]],
  ['costing_runs', [['payload', 'costingRun']]],
  ['provisions', [['payload', 'provision']]],
  ['deferrals', [['payload', 'deferral']]],
  ['inventory_valuations', [['payload', 'inventoryValuation']]],
  ['fiscal_years', [['periods', null]]],
  ['tenants', [['config', null]]],
];

const schema = JSON.parse(
  readFileSync(join(dir, '..', 'testing', 'testsuite', 'schema', 'format.schema.json'), 'utf8'),
) as Record<string, unknown>;
const ajv = new Ajv2020({ strict: false });
ajv.addSchema(schema, 'format');
const validators = new Map<string, ValidateFunction>();
function validatorFor(def: string): ValidateFunction {
  const known = validators.get(def);
  if (known !== undefined) return known;
  const compiled = ajv.compile({ $ref: `format#/$defs/${def}` });
  validators.set(def, compiled);
  return compiled;
}

function documents(
  file: string,
  table: string,
  columns: ReadonlyArray<readonly [string, string | null]>,
): Array<[string | null, string]> {
  const db = new SyncDb(join(dir, file));
  try {
    if (!db.hasTable(`${TABLE_PREFIX}${table}`)) return [];
    const rows = db.all(db.table(`${TABLE_PREFIX}${table}`).select(...columns.map(([c]) => c), 'id').orderBy('id'));
    const out: Array<[string | null, string]> = [];
    for (const row of rows) {
      for (const [column, def] of columns) {
        const value = row[column];
        if (typeof value === 'string') out.push([def, value]);
      }
    }
    return out;
  } finally {
    db.close();
  }
}

const aggregates: Result = { green: 0, red: 0, failures: [] };
for (const file of readdirSync(dir).filter((f) => f.endsWith('.sqlite') && !f.endsWith('.node.sqlite')).sort()) {
  const name = file.slice(0, -'.sqlite'.length);
  const nodeFile = `${name}.node.sqlite`;
  if (!existsSync(join(dir, nodeFile))) continue;

  for (const [table, columns] of AGGREGATE_COLUMNS) {
    const fromPhp = documents(file, table, columns);
    const fromNode = documents(nodeFile, table, columns);

    if (fromPhp.length !== fromNode.length) {
      aggregates.red++;
      aggregates.failures.push(`${name}/${table}: PHP wrote ${fromPhp.length} documents, Node ${fromNode.length}`);
      continue;
    }

    for (const [index, [def, phpDoc]] of fromPhp.entries()) {
      const [, nodeDoc] = fromNode[index] as [string | null, string];
      let ok = true;

      if (phpDoc !== nodeDoc) {
        ok = false;
        aggregates.failures.push(`${name}/${table}[${index}] ${def ?? 'undeclared'}: A=PHP: …${firstDiff(phpDoc, nodeDoc)}`);
      }

      for (const [engine, document] of [['PHP', phpDoc], ['Node', nodeDoc]] as const) {
        if (def === null) continue;
        const validate = validatorFor(def);
        if (!validate(JSON.parse(document))) {
          ok = false;
          aggregates.failures.push(
            `${name}/${table}[${index}] ${def} (${engine}) does not validate: ${ajv.errorsText(validate.errors, { separator: '; ' })}`,
          );
        }
      }

      if (ok) aggregates.green++;
      else aggregates.red++;
    }
  }
}

function report(label: string, r: Result): void {
  console.log(`${label}: ${r.green} green, ${r.red} red`);
  for (const f of r.failures.slice(0, 5)) console.log(`  ${f}`);
}

console.log('');
report('Cross-test PHP→Node (journalExport)', phpToNode);
report('Cross-test Node→PHP (journalExport)', nodeToPhp);
report('Cross-test stored aggregates (byte-equal + schema)', aggregates);
process.exit(phpToNode.red === 0 && nodeToPhp.red === 0 && aggregates.red === 0 ? 0 : 1);
