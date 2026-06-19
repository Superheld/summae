import { readdirSync, readFileSync } from 'node:fs';
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
 * Cross-Test, Lese-Seite (SF-15): öffnet die von PHP geschriebenen SQLite-Dateien
 * (`cross-export.php`), berechnet `journalExport` aus dem persistierten Bestand
 * und vergleicht den kanonischen JSON byte-genau mit PHPs Erwartung. Gleich =>
 * beide Sprachen teilen denselben Datenbestand (eine DB, zwei Engines).
 *
 * Lesen ist konfig- und placeholder-frei: journalExport dumpt nur den Journal-
 * Bestand (keine Mappings/Steuerschlüssel nötig).
 */

const dirArg = process.argv.slice(2).find((a) => a.startsWith('--dir='));
const dir = dirArg ? dirArg.slice('--dir='.length) : join(process.cwd(), '../../.cross-dbs');

const TENANT_TABLES = [
  'accounts',
  'journal_entries',
  'fiscal_years',
  'vouchers',
  'partners',
  'assets',
  'open_items',
  'audit_log',
];

/** tenant_id aus den Daten ermitteln — ein fremdes Package kennt sie nicht vorab. */
function discoverTenantId(db: SyncDb): string | null {
  for (const table of TENANT_TABLES) {
    const row = db.first(db.table(`${TABLE_PREFIX}${table}`).select('tenant_id'));
    if (row !== null && typeof row.tenant_id === 'string') return row.tenant_id;
  }
  return null;
}

// Geteilte Config (Name, Währung) kommt aus der Fixture — wie ein gemeinsamer Pack;
// die Daten kommen aus der von PHP geschriebenen DB.
const fixturesByName = new Map(loadFixtures().map((f) => [f.name, f]));

function tenantConfig(name: string): { tenantName: string; currency: string } {
  const setup = fixturesByName.get(name)?.setup;
  const tenant = setup && typeof setup.tenant === 'object' ? (setup.tenant as Record<string, unknown>) : {};
  return {
    tenantName: typeof tenant.name === 'string' ? tenant.name : 'Tenant',
    currency: typeof tenant.baseCurrency === 'string' ? tenant.baseCurrency : 'EUR',
  };
}

const files = readdirSync(dir)
  .filter((f) => f.endsWith('.sqlite'))
  .sort();

let green = 0;
let red = 0;
const failures: string[] = [];

for (const file of files) {
  const name = file.slice(0, -'.sqlite'.length);
  const db = new SyncDb(join(dir, file));
  try {
    const tenantId = discoverTenantId(db);
    if (tenantId === null) {
      red++;
      failures.push(`${name}: keine tenant_id in der DB gefunden`);
      continue;
    }
    const clock = FixedClock.at('2026-06-07T12:00:00+02:00');
    const { tenantName, currency } = tenantConfig(name);
    const tenant = DatabaseTenantFactory.build(db, tenantName, Currency.of(currency), clock, new DeterministicIdGenerator(clock), {
      tenantId: Uuid.fromString(tenantId),
    });
    // exportedAt (im manifest) ist der Erzeugungs-Zeitstempel des Exports
    // (clock-abgeleitet), KEIN geteilter Datenbestand — vor dem Vergleich raus.
    const actual = canonicalJson(stripExportedAt(new TenantOperations(tenant).project('journalExport', { format: 'gobd-z3' })));
    const expected = canonicalJson(
      stripExportedAt(JSON.parse(readFileSync(join(dir, `${name}.expected.json`), 'utf8')) as Record<string, unknown>),
    );

    if (actual === expected) {
      green++;
      console.log(`PASS  ${name}`);
    } else {
      red++;
      console.log(`FAIL  ${name}`);
      failures.push(`${name}: journalExport weicht ab\n      PHP : ${firstDiff(expected, actual)}`);
    }
  } finally {
    db.close();
  }
}

/** Entfernt das volatile manifest.exportedAt rekursiv (Export-Metadatum, keine Daten). */
function stripExportedAt(obj: Record<string, unknown>): Record<string, unknown> {
  for (const [key, value] of Object.entries(obj)) {
    if (key === 'exportedAt') delete obj[key];
    else if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
      stripExportedAt(value as Record<string, unknown>);
    }
  }
  return obj;
}

/** Erste abweichende Stelle kompakt zeigen. */
function firstDiff(a: string, b: string): string {
  let i = 0;
  while (i < a.length && i < b.length && a[i] === b[i]) i++;
  const at = Math.max(0, i - 30);
  return `…${a.slice(at, i + 30)}\n      Node: …${b.slice(at, i + 30)}  (ab Position ${i})`;
}

console.log(`\nCross-Test (PHP→Node, journalExport): ${green} grün, ${red} rot von ${files.length}`);
for (const f of failures.slice(0, 10)) console.log(`  ${f}`);
process.exit(red === 0 ? 0 : 1);
