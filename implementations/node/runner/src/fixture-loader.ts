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
  /** Geparster JSON-Inhalt der Fixture. */
  readonly data: Record<string, unknown>;
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

      const file = join(categoryDir, entry.name);
      const data = JSON.parse(readFileSync(file, 'utf8')) as Record<string, unknown>;
      const name = typeof data.fixture === 'string' ? data.fixture : entry.name;
      fixtures.push({ name, file, data });
    }
  }

  fixtures.sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
  return fixtures;
}
