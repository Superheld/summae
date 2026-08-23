import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

// runner/src → repo root. Node reads the same **shared** testsuite/ as the
// PHP runner (no per-impl drift); maintained one-way via bin/sync-testsuite.sh.
export const repoRoot = resolve(here, '../../../..');
export const fixturesDir = join(repoRoot, 'testing', 'testsuite', 'fixtures');
export const supersededFile = join(repoRoot, 'testing', 'testsuite', 'superseded.json');

/**
 * Fixtures a later fixture replaced (`testing/testsuite/superseded.json`).
 *
 * The suite is append-only, so a fixture whose expectation turned out never to have been a
 * contract is retired rather than edited: the file stays byte-identical, the registry names the
 * successor, and the runner skips it. Kept out of the fixture files themselves so append-only
 * means what it says — the file is not touched at all.
 */
export function supersededFixtures(file: string = supersededFile): Map<string, string> {
  const out = new Map<string, string>();
  if (!existsSync(file)) return out;
  const data = JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>;
  for (const entry of Array.isArray(data.superseded) ? data.superseded : []) {
    const record = asRecord(entry);
    if (typeof record.fixture === 'string' && typeof record.supersededBy === 'string') {
      out.set(record.fixture, record.supersededBy);
    }
  }
  return out;
}

export interface Fixture {
  /** Name from the `fixture` field; fallback file name. */
  readonly name: string;
  /** Absolute path of the source file. */
  readonly file: string;
  /** setup block (tenant, accounts, fiscal years, vouchers …). */
  readonly setup: Record<string, unknown>;
  /** steps[] — write operations with expect. */
  readonly steps: Array<Record<string, unknown>>;
  /** projections[] — reading projections with expect. */
  readonly projections: Array<Record<string, unknown>>;
}

function asRecord(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {};
}

function asRecordList(value: unknown): Array<Record<string, unknown>> {
  return Array.isArray(value) ? value.map(asRecord) : [];
}

function fixtureFromFile(file: string): Fixture | null {
  const data = JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>;
  // Module/pack data files (pack/**/{modules,packs}/) carry no "fixture"
  // key — they are resolver inputs, not fixtures, and are skipped.
  if (typeof data.fixture !== 'string') return null;
  return {
    name: data.fixture,
    file,
    setup: asRecord(data.setup),
    steps: asRecordList(data.steps),
    projections: asRecordList(data.projections),
  };
}

/**
 * Loads all fixtures of the shared suite recursively (`<category>/<name>.json` and
 * deeper, e.g. `pack/<group>/<name>.json`). Files without a "fixture" key
 * (module/pack data) are skipped. Sorted by fixture name in
 * codepoint order (deterministic, leading zeros significant).
 */
export function loadFixtures(dir: string = fixturesDir): Fixture[] {
  const superseded = supersededFixtures(join(dirname(dir), 'superseded.json'));
  const fixtures: Fixture[] = [];

  const walk = (current: string): void => {
    for (const entry of readdirSync(current, { withFileTypes: true })) {
      const path = join(current, entry.name);
      if (entry.isDirectory()) {
        walk(path);
      } else if (entry.isFile() && entry.name.endsWith('.json')) {
        const fixture = fixtureFromFile(path);
        if (fixture !== null && !superseded.has(fixture.name)) fixtures.push(fixture);
      }
    }
  };
  walk(dir);

  fixtures.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
  return fixtures;
}
