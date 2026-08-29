import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { supersededFixtures } from '../src/fixture-loader.js';
import { CoreSubject } from '../src/subject/core-subject.js';

/**
 * Gate for `docs/gdpr-conformance.md`.
 *
 * The twin of `gobd-conformance-doc.test.ts`, and it exists for the same reason: a compliance
 * document that names its own evidence is worse than no document once the evidence moves. A ✅
 * pointing at a renamed fixture reads exactly like a ✅ pointing at a real one.
 *
 * It carries one check the GoBD twin does not need. Section 1 of that document is an **inventory of
 * the fields that can hold personal data**, and an inventory is the kind of list that rots quietly:
 * a field renamed in `format.schema.json` leaves the row standing, still readable, still wrong, and
 * the next person to answer an Art. 30 question copies it. So every `record.field` pair the
 * inventory names is resolved against the schema, and a row that no longer describes the format
 * turns this red.
 *
 * Since 2026-08-29 it carries the other half of that (IMPL-045): the inventory is held against what
 * `personalDataDescription` actually **publishes**. Both lists stopped at the exchange format and
 * missed the same three fields — `asset.name`, `provision.reason`, `deferral.reason` — which is what
 * two hand-kept lists do: they agree with each other and drift from the product together. A field
 * the projection reports and the table does not name now turns this red, and so does the reverse.
 *
 * The document is allowed to be wrong about the law. It is not allowed to be wrong about summae.
 *
 * The SAME checks live in the PHP GdprConformanceDocTest.
 */

const REPO_ROOT = join(import.meta.dirname, '..', '..', '..', '..');
const DOC = join(REPO_ROOT, 'docs', 'gdpr-conformance.md');
const FIXTURE_ROOT = join(REPO_ROOT, 'testing', 'testsuite', 'fixtures');
const SCHEMA = join(REPO_ROOT, 'testing', 'testsuite', 'schema', 'format.schema.json');

function readDoc(): string {
  return readFileSync(DOC, 'utf8');
}

function allFixtures(): Map<string, unknown> {
  const found = new Map<string, unknown>();
  const walk = (dir: string, prefix: string): void => {
    for (const name of readdirSync(dir).sort()) {
      const full = join(dir, name);
      if (statSync(full).isDirectory()) {
        walk(full, prefix === '' ? name : `${prefix}/${name}`);
      } else if (name.endsWith('.json')) {
        const key = `${prefix === '' ? '' : `${prefix}/`}${name.slice(0, -5)}`;
        found.set(key, JSON.parse(readFileSync(full, 'utf8')));
      }
    }
  };
  walk(FIXTURE_ROOT, '');
  return found;
}

/**
 * Fixtures are cited by bare name here (`partner-master-data`), not by `dir/name` — this document
 * argues about rights and mechanisms rather than about suite paths, and a reader looking one up
 * greps for the name. So the lookup is by basename across the whole tree.
 */
function fixturesByName(): Map<string, string> {
  const byName = new Map<string, string>();
  for (const path of allFixtures().keys()) byName.set(path.slice(path.lastIndexOf('/') + 1), path);
  return byName;
}

/** Backticked kebab-case tokens that name a fixture. Anything else backticked is prose or a field. */
function citedFixtures(doc: string, known: ReadonlySet<string>): string[] {
  const matches = doc.matchAll(/`([a-z0-9]+(?:-[a-z0-9]+)+)`/g);
  return [...new Set([...matches].map((m) => m[1] as string))].filter((token) => known.has(token));
}

function citedRequirements(doc: string): string[] {
  return [...new Set([...doc.matchAll(/\b(F-[A-Z]+-\d{3})\b/g)].map((m) => m[1] as string))];
}

/**
 * The inventory rows: `| `record` | `field` | …`. Both cells are backticked and the row sits in the
 * §1 table, which is the only table whose first two columns are a schema record and a field of it.
 */
function inventoryRows(doc: string): Array<{ record: string; field: string }> {
  const rows: Array<{ record: string; field: string }> = [];
  for (const line of doc.split('\n')) {
    const match = line.match(/^\| `([a-zA-Z]+)` \| ([^|]+)\|/);
    if (match === null) continue;
    for (const field of [...(match[2] as string).matchAll(/`([a-zA-Z]+)`/g)]) {
      rows.push({ record: match[1] as string, field: field[1] as string });
    }
  }
  return rows;
}

function schemaDefs(): Record<string, { properties?: Record<string, unknown> }> {
  return JSON.parse(readFileSync(SCHEMA, 'utf8')).$defs;
}

describe('docs/gdpr-conformance.md keeps its promises', () => {
  it('is actually parsed (the inventory and the citations are found)', () => {
    const doc = readDoc();
    expect(inventoryRows(doc).length, 'no inventory rows found — did the §1 table change shape?').toBeGreaterThan(8);
    expect(citedFixtures(doc, new Set(fixturesByName().keys())).length).toBeGreaterThan(4);
  });

  it('every field the personal-data inventory names exists in the data format', () => {
    const defs = schemaDefs();
    const missing = inventoryRows(readDoc())
      .filter(({ record, field }) => defs[record]?.properties?.[field] === undefined)
      .map(({ record, field }) => `${record}.${field}`);

    expect(
      missing,
      'the inventory describes fields the format does not have — an Art. 30 answer copied from a stale list',
    ).toEqual([]);
  });

  it('every fixture it names exists', () => {
    const known = fixturesByName();
    const missing = citedFixtures(readDoc(), new Set(known.keys())).filter((name) => !known.has(name));
    expect(missing, 'the document points at fixtures that do not exist').toEqual([]);
  });

  it('names no fixture the runner has retired', () => {
    const retired = supersededFixtures();
    const stale = citedFixtures(readDoc(), new Set(fixturesByName().keys())).filter((name) => retired.has(name));
    expect(stale, 'the document cites a fixture the runner no longer runs').toEqual([]);
  });

  it('every requirement it names is covered by a fixture', () => {
    const covered = new Set<string>();
    for (const fixture of allFixtures().values()) {
      const covers = (fixture as { covers?: unknown }).covers;
      if (Array.isArray(covers)) for (const c of covers) covered.add(String(c));
    }
    const unbacked = citedRequirements(readDoc()).filter((id) => !covered.has(id));
    expect(unbacked, 'the document cites these requirements as evidence but no fixture covers them').toEqual([]);
  });

  // Only the projection's direction can be total: § 1 legitimately names more than the projection
  // reports — `partner.kind`/`status`/`accountNumbers`/`paymentTermsDays` are listed as personal *by
  // association*, and `voucher.documents` is a reference rather than a payload. What must not happen
  // is the reverse: the software reporting a place where operator text sits that the inventory does
  // not mention, because § 1 is what an Art. 30 record gets copied from.
  it('names every field the projection publishes', () => {
    const inventory = new Set(inventoryRows(readDoc()).map((row) => `${row.record}.${row.field}`));

    const subject = new CoreSubject();
    subject.setup({
      tenant: { name: 'Verzeichnis GmbH', baseCurrency: 'EUR' },
      accounts: [{ number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' }],
      fiscalYears: [{ year: 2026, start: '2026-01-01', end: '2026-12-31' }],
    });

    const description = subject.project('personalDataDescription', {}) as {
      fields: Array<{ holder: string; field: string }>;
    };
    expect(description.fields.length, 'the projection reports no fields — did its shape change?').toBeGreaterThan(8);

    const missing = description.fields
      .map((field) => `${field.holder}.${field.field}`)
      .filter((key) => !inventory.has(key));

    expect(
      missing,
      'personalDataDescription reports these places where operator text sits and § 1 does not name them (IMPL-045)',
    ).toEqual([]);
  });

  it('every status marker is one of the three defined ones', () => {
    const inTables = readDoc()
      .split('\n')
      .filter((line) => line.startsWith('|') && !line.startsWith('|---'))
      .join('\n');
    const markers = [...inTables.matchAll(/[✅⚠➖❌❓]/gu)].map((m) => m[0]);
    const allowed = new Set(['✅', '⚠', '➖']);
    expect([...new Set(markers)].filter((m) => !allowed.has(m))).toEqual([]);
  });
});
