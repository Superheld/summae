import { readdirSync, readFileSync, statSync } from 'node:fs';
import { basename, join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { supersededFixtures } from '../src/fixture-loader.js';

/**
 * Gate between the fixtures' `covers` field and the requirement lists (IMPL-039).
 *
 * The whole quality gate is defined in terms of the requirements — "every requirement is proven by
 * a test", "a requirement without a test is itself a finding". The requirement lists were held by
 * nobody. For about a year, 21 fixtures cited an `F-PACK-*`/`F-RP-*` family that no requirements
 * file declared, using two area words the root CLAUDE.md did not list; `SF-27` was in the same
 * state from the other side — five fixtures covered it and `validate.py` counted it while
 * `lieferumfang.md` ended at SF-26, so a standard case existed only in the arithmetic.
 *
 * The comparison that makes this a gate rather than a chore: the error catalogue and the exit-code
 * tables are held against each other as SETS, in both directions, in both languages, so half the
 * work fails the build. This does the same for `covers` and the requirement lists.
 *
 * Two exception lists, both with reasons, both guarded in reverse so they cannot quietly become the
 * place where a gap hides.
 *
 * **What this cannot check, and it matters:** that the fixture behind an ID actually PROVES the
 * requirement. `F-KLR-005` is declared, cited by three fixtures, and those three are about
 * production cost — the one case that requirement excludes (IMPL-043). A guard over IDs reads green
 * there. Do not let it read as "every requirement is proven".
 *
 * The SAME checks live in the PHP CoversContractTest.
 */

const REPO_ROOT = join(import.meta.dirname, '..', '..', '..', '..');
const REQUIREMENTS = join(REPO_ROOT, 'knowledge', '30-anforderungen');
const FIXTURE_ROOT = join(REPO_ROOT, 'testing', 'testsuite', 'fixtures');
const SUPERSEDED = join(REPO_ROOT, 'testing', 'testsuite', 'superseded.json');

/**
 * `covers` entries that are not requirement IDs. All of them are Gate-1 resolver drafts that cite
 * error codes, resolver invariants and bare words instead — written before the rule existed. A
 * fixture is append-only, so `covers` cannot be corrected; the list is closed and must not grow.
 */
const LEGACY_COVERS: Record<string, string> = {
  E_PACK_INCOHERENT: 'Gate-1 resolver draft: cites the error code instead of F-PACK-RESOLVE',
  E_PACK_UNRESOLVED_REF: 'Gate-1 resolver draft: cites the error code instead of F-PACK-RESOLVE',
  E_POLICY_INVALID: 'Gate-1 resolver draft: cites the error code instead of F-PACK-RESOLVE',
  I1: 'Gate-1 resolver draft: cites resolver invariant I1 (tax code without account)',
  I2: 'Gate-1 resolver draft: cites resolver invariant I2 (mapping without accounts)',
  I3: 'Gate-1 resolver draft: cites resolver invariant I3 (missing reference)',
  I4: 'Gate-1 resolver draft: cites resolver invariant I4 (projection without taxTag)',
  cycle: 'Gate-1 resolver draft: bare word for the dependency-cycle invariant',
  mechanism: 'Gate-1 resolver draft: bare word for the unknown-mechanism invariant',
  override: 'Gate-1 resolver draft: bare word for the colliding-override invariant',
  packPolicy: 'Gate-1 resolver draft: bare word for the invalid-policy invariant',
};

/**
 * Declared requirements no live fixture names, each with the reason it is legitimately not
 * fixture-backed. Where the reason is "a fixture proves it under another ID", the fixtures are
 * named — and named fixtures are checked to exist and to still run, so the excuse cannot rot the
 * way a ✅ pointing at a renamed fixture does.
 */
const NOT_FIXTURE_BACKED: Record<string, { reason: string; fixtures: string[] }> = {
  'F-AST-007': {
    reason:
      'built and behaviourally covered, but the four fixtures that exercise it name F-AST-002/F-AST-005 in ' +
      '`covers`, and a fixture is append-only. Merging the requirement into those two would be wrong on content ' +
      '— declining-balance plans are neither the GWG switch nor the asset register — and a fixture whose only ' +
      'purpose is to carry a string is the wrong artefact',
    fixtures: [
      'assets/declining-balance-depreciation',
      'assets/declining-balance-asset-class',
      'assets/special-depreciation',
      'assets/asset-register-special-depreciation',
    ],
  },
  'F-IO-004': {
    reason: 'cross-language data exchange — proven by `make cross`, which a fixture cannot express',
    fixtures: [],
  },
  'F-IO-008': {
    reason: 'DATEV batch import: not built, deliberately deferred with its blocker named (IMPL-042)',
    fixtures: [],
  },
  'F-IO-010': {
    reason:
      'the operation parameter contract — proven by OperationParametersTest / operation-parameters.test.ts, ' +
      'which compare the constants against api-parameters.json. A fixture exercises the dispatcher; it cannot ' +
      'pin that the table equals the file',
    fixtures: [],
  },
  'F-KLR-002': {
    reason:
      'Abgrenzungsrechnung: not built. Decided 2026-08-28 to be IN scope and unbuilt (IMPL-041), not descoped ' +
      '— so this entry is a gap on record, not an excuse',
    fixtures: [],
  },
  'NF-4': {
    reason:
      'embeddability (no UI, no server, no forced DB) — architectural, guarded by SubstrateBoundaryTest and the ' +
      'eslint no-restricted-imports boundary, not by behaviour',
    fixtures: [],
  },
  'NF-5': {
    reason:
      'law-dependent values live in packs, not in the core — guarded by NoJurisdictionTextTest / ' +
      'no-jurisdiction-text.test.ts, which is a property of the source and not of a run',
    fixtures: [],
  },
  'NF-6': {
    reason:
      'concurrency — dedicated per-implementation test (NfConcurrencyPerformanceTest / ' +
      'nf-concurrency-performance.test.ts), per the Definition of Green',
    fixtures: [],
  },
  'NF-7': { reason: 'performance — same dedicated test; a fixture pins results, not timings', fixtures: [] },
  'SF-15': {
    reason: 'one data set, several engines — proven by `make cross` in both directions, like F-IO-004',
    fixtures: [],
  },
};

function declaredRequirements(): string[] {
  const read = (name: string): string => readFileSync(join(REQUIREMENTS, name), 'utf8');
  const ids = [
    ...read('funktional.md').matchAll(/^\| (F-[A-Z]+(?:-[A-Z]+)*-[A-Z0-9]+)/gm),
    ...read('nicht-funktional.md').matchAll(/^## (NF-\d+)/gm),
    ...read('lieferumfang.md').matchAll(/^- \[[ x]\] (SF-\d+)/gm),
  ].map((m) => m[1] as string);
  return [...new Set(ids)].sort();
}

/**
 * `dir/name` => covers, fixtures only (pack module and manifest data files carry no `fixture` key
 * and are not fixtures — `validate.py` skips them for the same reason).
 */
function fixtureCovers(): Map<string, string[]> {
  const found = new Map<string, string[]>();
  const walk = (dir: string, prefix: string): void => {
    for (const name of readdirSync(dir).sort()) {
      const full = join(dir, name);
      if (statSync(full).isDirectory()) {
        walk(full, prefix === '' ? name : `${prefix}/${name}`);
      } else if (name.endsWith('.json')) {
        const parsed = JSON.parse(readFileSync(full, 'utf8')) as Record<string, unknown>;
        if (!('fixture' in parsed)) continue;
        const covers = Array.isArray(parsed.covers) ? parsed.covers.filter((c): c is string => typeof c === 'string') : [];
        found.set(`${prefix === '' ? '' : `${prefix}/`}${name.slice(0, -5)}`, covers);
      }
    }
  };
  walk(FIXTURE_ROOT, '');
  return found;
}

/**
 * Only fixtures the runner still RUNS count as coverage. A retired fixture stays on disk
 * byte-identical by design, so existence is the wrong question here as well.
 */
function coveredRequirements(): string[] {
  const retired = supersededFixtures(SUPERSEDED);
  const covered = new Set<string>();
  for (const [path, covers] of fixtureCovers()) {
    if (retired.has(basename(path))) continue;
    for (const id of covers) covered.add(id);
  }
  return [...covered].sort();
}

describe('covers and the requirement lists', () => {
  it('actually parses both sides', () => {
    expect(declaredRequirements().length, 'no requirements found — did a table change shape?').toBeGreaterThan(100);
    expect(fixtureCovers().size, 'no fixtures found').toBeGreaterThan(50);
  });

  it('names only declared requirements', () => {
    const declared = new Set(declaredRequirements());
    const undeclared: string[] = [];
    for (const [path, covers] of fixtureCovers()) {
      for (const id of covers) {
        if (!declared.has(id) && !(id in LEGACY_COVERS)) undeclared.push(`${path} covers ${id}`);
      }
    }

    expect(
      undeclared.sort(),
      'these fixtures name something no requirements file declares — declare it or use a declared ID',
    ).toEqual([]);
  });

  it('covers or excuses every declared requirement', () => {
    const covered = new Set(coveredRequirements());
    const unproven = declaredRequirements().filter((id) => !covered.has(id) && !(id in NOT_FIXTURE_BACKED));

    expect(
      unproven,
      'these requirements have no live fixture and no recorded reason — "a requirement without a test is itself a ' +
        'finding", so either cover it or write down why a fixture cannot',
    ).toEqual([]);
  });

  // The reverse guard on both lists: once a real `covers` entry exists, the excuse must go, or the
  // list quietly turns into the place where coverage hides.
  it('lets no exception outlive its reason', () => {
    const covered = new Set(coveredRequirements());
    const stale: string[] = [];
    for (const id of Object.keys(NOT_FIXTURE_BACKED)) {
      if (covered.has(id)) stale.push(`${id} is now covered by a fixture — remove the NOT_FIXTURE_BACKED entry`);
    }

    const allCovers = new Set([...fixtureCovers().values()].flat());
    for (const id of Object.keys(LEGACY_COVERS)) {
      if (!allCovers.has(id)) stale.push(`${id} is in LEGACY_COVERS but no fixture uses it any more — remove the entry`);
    }

    expect(stale.sort()).toEqual([]);
  });

  // An excuse that names fixtures must name fixtures that exist and still run — the same rule the
  // GoBD census learned the hard way. Otherwise "covered under another ID" ages into a claim nobody
  // can reproduce.
  it('names substitute fixtures that exist and run', () => {
    const fixtures = fixtureCovers();
    const retired = supersededFixtures(SUPERSEDED);
    const broken: string[] = [];

    for (const [id, entry] of Object.entries(NOT_FIXTURE_BACKED)) {
      for (const path of entry.fixtures) {
        if (!fixtures.has(path)) broken.push(`${id} names ${path}, which does not exist`);
        else if (retired.has(basename(path))) broken.push(`${id} names ${path}, which the runner no longer runs`);
      }
    }

    expect(broken).toEqual([]);
  });

  // `validate.py` prints the standard cases from `covers` alone, which is how SF-27 came to exist in
  // the arithmetic and nowhere else. Same set, stated as its own check because that is the shape the
  // finding was found in.
  it('counts no standard case lieferumfang.md does not declare', () => {
    const declared = new Set(declaredRequirements());
    const undeclared = coveredRequirements().filter((id) => id.startsWith('SF-') && !declared.has(id));

    expect(undeclared, 'validate.py counts standard cases lieferumfang.md does not declare').toEqual([]);
  });
});
