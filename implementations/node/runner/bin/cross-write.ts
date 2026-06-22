import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { canonicalJson } from '@superheld/summae-core';
import { DatabaseTenantFactory, SyncDb, installSchema } from '@superheld/summae-knex';
import { loadFixtures } from '../src/fixture-loader.js';
import { FixtureRunner } from '../src/fixture-runner.js';
import { CoreSubject } from '../src/subject/core-subject.js';

/**
 * Cross-test, write side Node→PHP (SF-15, reverse direction): runs every fixture
 * with setup.tenant against a SQLite FILE (Knex adapter) and stores the
 * canonical journalExport as oracle. The PHP side (`cross-read.php`) opens
 * the same file and must come out byte-identical.
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

  // Write oracle raw (full journalExport); the read side normalizes at comparison.
  writeFileSync(
    join(dir, `${fixture.name}.node.expected.json`),
    canonicalJson(subject.project('journalExport', { format: 'gobd-z3' })),
  );
  written++;
}

console.log(`Cross-write (Node): ${written} fixtures written, ${skipped} skipped (no setup.tenant) → ${dir}`);
