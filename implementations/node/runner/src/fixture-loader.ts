import { readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));

// runner/src → Repo-Root. Node liest dieselbe **geteilte** testsuite/ wie der
// PHP-Runner (kein Per-Impl-Drift); gepflegt einbahnig via bin/sync-testsuite.sh.
export const repoRoot = resolve(here, '../../../..');
export const fixturesDir = join(repoRoot, 'testsuite', 'fixtures');

export interface Fixture {
  /** Name aus dem `fixture`-Feld; Fallback Dateiname. */
  readonly name: string;
  /** Absoluter Pfad der Quelldatei. */
  readonly file: string;
  /** setup-Block (Mandant, Konten, Wirtschaftsjahre, Belege …). */
  readonly setup: Record<string, unknown>;
  /** steps[] — Schreiboperationen mit expect. */
  readonly steps: Array<Record<string, unknown>>;
  /** projections[] — lesende Projektionen mit expect. */
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

function fixtureFromFile(file: string): Fixture {
  const data = JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>;
  const name = typeof data.fixture === 'string' ? data.fixture : file;
  return {
    name,
    file,
    setup: asRecord(data.setup),
    steps: asRecordList(data.steps),
    projections: asRecordList(data.projections),
  };
}

/**
 * Lädt alle Fixtures der geteilten Suite (Muster `<kategorie>/<name>.json`,
 * eine Ebene tief — wie der PHP-Glob `* /*.json`), sortiert nach Fixture-Name
 * in Codepoint-Reihenfolge (deterministisch, führende Nullen signifikant).
 */
export function loadFixtures(dir: string = fixturesDir): Fixture[] {
  const fixtures: Fixture[] = [];

  for (const category of readdirSync(dir, { withFileTypes: true })) {
    if (!category.isDirectory()) continue;
    const categoryDir = join(dir, category.name);

    for (const entry of readdirSync(categoryDir, { withFileTypes: true })) {
      if (!entry.isFile() || !entry.name.endsWith('.json')) continue;
      fixtures.push(fixtureFromFile(join(categoryDir, entry.name)));
    }
  }

  fixtures.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
  return fixtures;
}
