import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { CLI_VERSION } from '../src/version.js';

/**
 * Drift guard for every version string a release bumps (IMPL-035).
 *
 * `summae --version` answered `0.1.0` through fifteen releases, and its PHP twin answered
 * `0.1.0-dev` — two different lies about the same build, which is the equivalence policy broken on
 * the one surface a user reads first. Nothing compared the constant to anything, because a version
 * number is not behaviour: no fixture exercises it, the suite stayed green, and the number was
 * wrong from the release after the one that wrote it.
 *
 * The anchor is `CHANGELOG.md`, and deliberately so. Dating a section (`## X.Y.Z — YYYY-MM-DD`) is
 * what *makes* a release — `release-notes.yml` refuses to publish without it — so the guard fires
 * in the release commit itself, the moment the bumps are due. An undated `unreleased` section does
 * not move the anchor: between releases the CLI keeps naming the last version that shipped, which
 * is what `package.json` says too.
 *
 * Its PHP twin (`ReleaseVersionTest`) asserts the same constant against the same file, so the two
 * languages cannot answer `--version` differently again.
 */
const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..', '..', '..', '..');

/**
 * The newest *dated* section heading. `0.13.1` carries a parenthetical after the date
 * ("never tagged on its own"), so the date anchors the match and the rest of the line is free.
 */
function releasedVersion(): string {
  const raw = readFileSync(join(repoRoot, 'CHANGELOG.md'), 'utf8');
  const match = raw.match(/^## (\d+\.\d+\.\d+) — \d{4}-\d{2}-\d{2}/m);

  expect(match, 'the changelog must carry at least one dated release heading').not.toBeNull();

  return match![1]!;
}

/** The version pnpm publishes this package under. */
function packageVersion(name: string): string {
  const path = join(repoRoot, 'implementations', 'node', 'packages', name, 'package.json');

  return JSON.parse(readFileSync(path, 'utf8')).version;
}

describe('release version', () => {
  it('has the CLI name the version that was last released', () => {
    expect(CLI_VERSION).toBe(releasedVersion());
  });

  it('has all three published packages carry that version', () => {
    // `pnpm -r publish` ships every one of them; bumping only the core releases the other two
    // under their old version (RELEASING.md).
    for (const name of ['core', 'cli', 'knex']) {
      expect(packageVersion(name), `@superheld/summae-${name}`).toBe(releasedVersion());
    }
  });
});
