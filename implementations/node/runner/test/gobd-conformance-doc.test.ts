import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { supersededFixtures } from '../src/fixture-loader.js';
import { allTaxBaseKinds, allTaxMechanisms } from '@superheld/summae-core';

/**
 * Gate for `docs/gobd-conformance.md`.
 *
 * That document answers an audit question — "is this GoBD-compliant, and where is the
 * proof?" — by naming, per obligation, the fixture or test that fails if the claim stops
 * being true. A document like that is worse than useless once it drifts: a ✅ pointing at a
 * renamed fixture reads exactly like a ✅ pointing at a real one, and nobody notices until
 * an auditor tries to run it.
 *
 * So the claims are checked mechanically. Every fixture path and every requirement ID the
 * document cites must exist, and every requirement it cites must really be covered by a
 * fixture that says so in `covers`. The document is allowed to be wrong about the law; it
 * is not allowed to be wrong about its own evidence.
 *
 * The SAME checks live in the PHP GobdConformanceDocTest.
 */

const REPO_ROOT = join(import.meta.dirname, '..', '..', '..', '..');
const DOC = join(REPO_ROOT, 'docs', 'gobd-conformance.md');
const FIXTURE_ROOT = join(REPO_ROOT, 'testing', 'testsuite', 'fixtures');

/**
 * Requirements the document cites that no fixture covers — each with the reason it is
 * legitimately not fixture-backed. Anything else must be a real `covers` entry.
 */
const NOT_FIXTURE_BACKED: Record<string, string> = {
  'F-IO-004': 'cross-language data exchange — proven by `make cross`, which a fixture cannot express',
};

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
 * Backticked `dir/name` tokens whose first segment is a real fixture directory. Anchoring on
 * the directory set rather than on the shape matters: the document also contains `brick/math`
 * (a library) and `period/reopened` (an audit objectType/action pair), which a shape-only
 * pattern happily mistakes for fixtures.
 */
function citedFixtures(doc: string, fixtureDirs: ReadonlySet<string>): string[] {
  const matches = doc.matchAll(/`([a-z0-9-]+(?:\/[a-z0-9-]+)+)`/g);
  return [...new Set([...matches].map((m) => m[1] as string))].filter((token) =>
    fixtureDirs.has(token.slice(0, token.indexOf('/'))),
  );
}

function fixtureDirNames(): Set<string> {
  return new Set(readdirSync(FIXTURE_ROOT).filter((name) => statSync(join(FIXTURE_ROOT, name)).isDirectory()));
}

function citedRequirements(doc: string): string[] {
  const matches = doc.matchAll(/`(F-[A-Z]+-\d{3})`/g);
  return [...new Set([...matches].map((m) => m[1] as string))];
}


const PACK_LIBRARY = join(REPO_ROOT, 'pack-library');

/**
 * §15 of the document is a table of FACTS about the shipped product, and this reads it back.
 *
 * The rows above it are argued in prose on purpose — a compliance census that reduced itself to a
 * machine-readable list would stop being readable by the person who has to defend it. But the facts
 * quoted inside that prose were checked by nobody, and on 2026-08-23 §4 named two tax codes as
 * missing that were built the same day. The row stayed wrong through five green builds. So the
 * facts move into one table and the table is held against its sources.
 *
 * Values are backtick-quoted tokens in the third column; `—` means the source is empty, which is
 * itself an assertion (the `default` pack ships no tax codes, and that is why it is the pack to
 * start from).
 */
function claimRow(doc: string, claim: string): string[] {
  const line = doc
    .split('\n')
    .filter((l) => l.startsWith('|') && l.split('|').length >= 5)
    .find((l) => (l.split('|')[1] ?? '').replaceAll('`', '').trim() === claim);
  if (line === undefined) throw new Error(`§15 has no row for the claim "${claim}"`);
  const value = line.split('|')[3] ?? '';
  if (value.trim() === '—') return [];
  return [...value.matchAll(/`([^`]+)`/g)].map((m) => m[1] as string);
}

function packTaxCodes(pack: string, manifest: string): string[] {
  const parsed: unknown = JSON.parse(readFileSync(join(PACK_LIBRARY, pack, manifest), 'utf8'));
  const codes = (parsed as { taxCodes?: unknown }).taxCodes;
  return Array.isArray(codes) ? codes.map(String) : [];
}

describe('docs/gobd-conformance.md keeps its promises', () => {
  it('cites at least one fixture and one requirement (the doc is actually parsed)', () => {
    const doc = readDoc();
    expect(
      citedFixtures(doc, fixtureDirNames()).length,
      'no fixture citations found — did the format change?',
    ).toBeGreaterThan(10);
    expect(citedRequirements(doc).length).toBeGreaterThan(10);
  });

  it('every fixture it names exists', () => {
    const fixtures = allFixtures();
    const missing = citedFixtures(readDoc(), fixtureDirNames()).filter((name) => !fixtures.has(name));
    expect(
      missing,
      'the conformance document points at fixtures that do not exist — a ✅ nobody can reproduce',
    ).toEqual([]);
  });

  /**
   * A cited fixture must still RUN, not merely still exist.
   *
   * Retiring a fixture leaves the file in place, byte-identical, by design — which is exactly why
   * existence was the wrong question. Four rows of this document went on citing retired fixtures for
   * a day without anything noticing: three lost their evidence when the data format moved to 0.7,
   * one when the capability list stopped being pinned. A ✅ whose proof no longer runs is the
   * failure mode this whole document exists to prevent, one level up.
   */
  it('names no fixture the runner has retired', () => {
    const retired = supersededFixtures();
    const stale = citedFixtures(readDoc(), fixtureDirNames())
      .filter((path) => retired.has(path.slice(path.lastIndexOf('/') + 1)))
      .map((path) => `${path} is retired (superseded by ${retired.get(path.slice(path.lastIndexOf('/') + 1))!})`);
    expect(stale, 'the conformance document cites a fixture the runner no longer runs').toEqual([]);
  });

  it('every requirement it names is covered by a fixture, or is a declared exception', () => {
    const covered = new Set<string>();
    for (const fixture of allFixtures().values()) {
      const covers = (fixture as { covers?: unknown }).covers;
      if (Array.isArray(covers)) for (const c of covers) covered.add(String(c));
    }

    const unbacked = citedRequirements(readDoc()).filter(
      (id) => !covered.has(id) && !(id in NOT_FIXTURE_BACKED),
    );
    expect(
      unbacked,
      'the document cites these requirements as evidence but no fixture covers them — ' +
        'either add the fixture or record the reason in NOT_FIXTURE_BACKED',
    ).toEqual([]);
  });

  it('every declared exception is still an exception', () => {
    // The reverse guard: once a fixture DOES cover one of these, the excuse must go, or the
    // list quietly turns into a place where real coverage hides.
    const covered = new Set<string>();
    for (const fixture of allFixtures().values()) {
      const covers = (fixture as { covers?: unknown }).covers;
      if (Array.isArray(covers)) for (const c of covers) covered.add(String(c));
    }

    const stale = Object.keys(NOT_FIXTURE_BACKED).filter((id) => covered.has(id));
    expect(
      stale,
      'these are listed as not fixture-backed but a fixture now covers them — remove the entry',
    ).toEqual([]);
  });

  it('every status marker is one of the three defined ones', () => {
    // A fourth symbol would mean the three-way distinction (verified / open / not verifiable)
    // has quietly softened — which is the one thing this document must not do.
    const doc = readDoc();
    const inTables = doc
      .split('\n')
      .filter((line) => line.startsWith('|') && !line.startsWith('|---'))
      .join('\n');
    // Explicit code points, not literal emoji: the warning sign is written ⚠️ — sign plus a
    // variation selector — and putting that pair in a character class is exactly the
    // misleading-character-class bug eslint flags. Matching the base sign ignores the
    // selector, which is also what the PHP twin does.
    const markers = [...inTables.matchAll(/[\u2705\u26A0\u2796\u274C\u2753]/gu)].map((m) => m[0]);
    const allowed = new Set(['\u2705', '\u26A0', '\u2796']);
    expect([...new Set(markers)].filter((m) => !allowed.has(m))).toEqual([]);
  });

  it('the tax codes §15 claims are the tax codes the packs ship', () => {
    const doc = readDoc();
    expect(claimRow(doc, 'de pack tax codes')).toEqual(packTaxCodes('de-pack', 'de.json'));
    expect(claimRow(doc, 'us pack tax codes')).toEqual(packTaxCodes('us-pack', 'us.json'));
    expect(claimRow(doc, 'default pack tax codes')).toEqual(packTaxCodes('default-pack', 'default.json'));
  });

  it('the engine repertoires §15 claims are the ones the core registers', () => {
    const doc = readDoc();
    expect(claimRow(doc, 'engine tax mechanisms')).toEqual([...allTaxMechanisms()]);
    expect(claimRow(doc, 'engine tax base kinds')).toEqual([...allTaxBaseKinds()]);
  });

  /**
   * The A-13 row is ✅ only because a SHIPPED pack declares the rule. If the rule changes its
   * accounts, that ✅ is describing something else — so the accounts are part of the claim.
   */
  it('the account-combination rule §15 claims is the rule the de pack declares', () => {
    const parsed: unknown = JSON.parse(
      readFileSync(join(PACK_LIBRARY, 'de-pack', 'constraint', 'de-entgeltminderung.json'), 'utf8'),
    );
    type Range = { from: string; to: string };
    const rules = ((parsed as { data?: { accountCombinationRules?: unknown } }).data?.accountCombinationRules ??
      []) as Array<{ whenAccountIn?: Range; requireAccountIn?: Range }>;
    const actual = rules.flatMap(({ whenAccountIn, requireAccountIn }) =>
      whenAccountIn === undefined || requireAccountIn === undefined
        ? []
        : [whenAccountIn.from, whenAccountIn.to, requireAccountIn.from, requireAccountIn.to],
    );
    expect(claimRow(readDoc(), 'de pack account-combination rules')).toEqual(actual);
  });
});
