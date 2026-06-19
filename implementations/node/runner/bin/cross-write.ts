import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { canonicalJson } from '@superheld/summae-core';
import { DatabaseTenantFactory, SyncDb, installSchema } from '@superheld/summae-knex';
import { loadFixtures } from '../src/fixture-loader.js';
import { FixtureRunner } from '../src/fixture-runner.js';
import { CoreSubject } from '../src/subject/core-subject.js';

/**
 * Cross-Test, Schreib-Seite Node→PHP (SF-15, Gegenrichtung): fährt jede Fixture
 * mit setup.tenant gegen eine SQLite-DATEI (Knex-Adapter) und legt den
 * kanonischen journalExport als Oracle ab. Die PHP-Seite (`cross-read.php`) öffnet
 * dieselbe Datei und muss byte-identisch herauskommen.
 */

const dirArg = process.argv.slice(2).find((a) => a.startsWith('--dir='));
const dir = dirArg ? dirArg.slice('--dir='.length) : join(process.cwd(), '../../.cross-dbs');
mkdirSync(dir, { recursive: true });

let written = 0;
let skipped = 0;

for (const fixture of loadFixtures()) {
  if (fixture.setup.tenant === undefined || typeof fixture.setup.tenant !== 'object') {
    skipped++;
    continue;
  }

  const dbPath = join(dir, `${fixture.name}.node.sqlite`);
  rmSync(dbPath, { force: true });

  const subject = new CoreSubject((name, baseCurrency, clock, ids, dimensions, taxCodes, taxProfile, mappings) => {
    const db = new SyncDb(dbPath);
    installSchema(db);
    return DatabaseTenantFactory.build(db, name, baseCurrency, clock, ids, { dimensions, taxCodes, taxProfile, mappings });
  });

  new FixtureRunner().run(fixture, subject);

  // Oracle roh schreiben (voller journalExport); die Leseseite normiert beim Vergleich.
  writeFileSync(
    join(dir, `${fixture.name}.node.expected.json`),
    canonicalJson(subject.project('journalExport', { format: 'gobd-z3' })),
  );
  written++;
}

console.log(`Cross-Write (Node): ${written} Fixtures geschrieben, ${skipped} übersprungen (kein setup.tenant) → ${dir}`);
