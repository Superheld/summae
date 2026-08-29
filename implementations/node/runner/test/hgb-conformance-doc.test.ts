import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { supersededFixtures } from '../src/fixture-loader.js';
import { ACCOUNT_SUBTYPES } from '@superheld/summae-core';

/**
 * Gate for `docs/hgb-conformance.md`.
 *
 * The third census, and the first one whose facts are mostly **absences**. Its two siblings claim
 * that something is in place and name the fixture that proves it; this one mostly claims that
 * something is *missing* — no stock, no provisions, no write-up. That inverts what the gate is for.
 *
 * A claim of presence rots when the evidence is renamed. A claim of absence rots the other way, and
 * worse: somebody builds the missing thing, nobody opens the census, and a ⚠️ row goes on describing
 * a hole that was filled months ago. The document then understates the product, which is the exact
 * mirror of the GoBD census's VAT row understating it for five green days — and it is harder to
 * notice, because nothing breaks and the software only looks worse than it is.
 *
 * So §8 of that document is a table of facts and this test reads it back against the real sources.
 * Building any of the named operations, adding the `inventory` subtype, giving the German balance
 * sheet its stock position — each one turns this red until the census is opened and the row is moved
 * with its evidence named. The gate does not merely notice progress; it refuses to let progress go
 * unrecorded.
 *
 * The SAME checks live in the PHP HgbConformanceDocTest.
 */

const REPO_ROOT = join(import.meta.dirname, '..', '..', '..', '..');
const DOC = join(REPO_ROOT, 'docs', 'hgb-conformance.md');
const FIXTURE_ROOT = join(REPO_ROOT, 'testing', 'testsuite', 'fixtures');

function readDoc(): string {
  return readFileSync(DOC, 'utf8');
}

function readJson(relative: string): Record<string, unknown> {
  return JSON.parse(readFileSync(join(REPO_ROOT, relative), 'utf8')) as Record<string, unknown>;
}

function fixtureDirNames(): Set<string> {
  return new Set(readdirSync(FIXTURE_ROOT).filter((name) => statSync(join(FIXTURE_ROOT, name)).isDirectory()));
}

function allFixtureNames(): Set<string> {
  const found = new Set<string>();
  const walk = (dir: string, prefix: string): void => {
    for (const name of readdirSync(dir).sort()) {
      const full = join(dir, name);
      if (statSync(full).isDirectory()) walk(full, prefix === '' ? name : `${prefix}/${name}`);
      else if (name.endsWith('.json')) found.add(`${prefix === '' ? '' : `${prefix}/`}${name.slice(0, -5)}`);
    }
  };
  walk(FIXTURE_ROOT, '');
  return found;
}

/**
 * Backticked `dir/name` tokens whose first segment is a real fixture directory — the same anchoring
 * the GoBD gate uses, and for the same reason: this document also contains `pack-library/...` paths
 * and `docs/handbuch`, which a shape-only pattern would happily mistake for fixtures.
 */
function citedFixtures(doc: string, fixtureDirs: ReadonlySet<string>): string[] {
  const matches = doc.matchAll(/`([a-z0-9-]+(?:\/[a-z0-9-]+)+)`/g);
  return [...new Set([...matches].map((m) => m[1] as string))].filter((token) =>
    fixtureDirs.has(token.slice(0, token.indexOf('/'))),
  );
}

/**
 * A §8 row's value is its backtick-quoted tokens; `—` means the source is empty, which is itself an
 * assertion.
 */
function claimRow(doc: string, claim: string): string[] {
  const line = doc
    .split('\n')
    .filter((l) => l.startsWith('|') && l.split('|').length >= 5)
    .find((l) => (l.split('|')[1] ?? '').replaceAll('`', '').trim() === claim);
  if (line === undefined) throw new Error(`§8 has no row for the claim "${claim}"`);
  const value = line.split('|')[3] ?? '';
  if (value.trim() === '—') return [];
  return [...value.matchAll(/`([^`]+)`/g)].map((m) => m[1] as string);
}

type Position = { key?: unknown; side?: unknown; children?: unknown };

function positionKeys(module: Record<string, unknown>, side: string | null): string[] {
  const data = (module.data ?? {}) as { mapping?: { positions?: Position[] } };
  const positions = data.mapping?.positions ?? [];
  if (side === null) return positions.map((p) => String(p.key ?? ''));
  return positions
    .filter((p) => p.side === side)
    .flatMap((p) => ((p.children ?? []) as Position[]).map((c) => String(c.key ?? '')));
}

function declared(block: 'operations' | 'projections'): Set<string> {
  const parsed = readJson('testing/testsuite/schema/api-parameters.json');
  const entries = parsed[block];
  return new Set(entries !== null && typeof entries === 'object' ? Object.keys(entries) : []);
}

describe('docs/hgb-conformance.md keeps its promises', () => {
  it('cites fixtures at all (the doc is actually parsed)', () => {
    expect(
      citedFixtures(readDoc(), fixtureDirNames()).length,
      'no fixture citations found — did the format change?',
    ).toBeGreaterThan(10);
  });

  it('every fixture it names exists', () => {
    const fixtures = allFixtureNames();
    const missing = citedFixtures(readDoc(), fixtureDirNames()).filter((name) => !fixtures.has(name));
    expect(missing, 'the census points at fixtures that do not exist — a ✅ nobody can reproduce').toEqual([]);
  });

  it('names no fixture the runner has retired', () => {
    const retired = supersededFixtures();
    const stale = citedFixtures(readDoc(), fixtureDirNames())
      .filter((path) => retired.has(path.slice(path.lastIndexOf('/') + 1)))
      .map((path) => `${path} is retired (superseded by ${retired.get(path.slice(path.lastIndexOf('/') + 1))!})`);
    expect(stale, 'the census cites a fixture the runner no longer runs').toEqual([]);
  });

  it('every status marker is one of the four defined ones', () => {
    // Four, not three: this census needs 🟡 for the shape most of its findings have — the chart
    // carries the right account and nothing carries the rule. A FIFTH symbol would mean the
    // distinction has softened again, which is the one thing a census must not do.
    //
    // Explicit code points, not literal emoji: the warning sign is written ⚠️ — sign plus a
    // variation selector — and putting that pair in a character class is exactly the
    // misleading-character-class bug eslint flags. Matching the base sign ignores the selector,
    // which is also what the PHP twin does.
    const inTables = readDoc()
      .split('\n')
      .filter((line) => line.startsWith('|') && !line.startsWith('|---'))
      .join('\n');
    const markers = [
      ...inTables.matchAll(/[✅⚠➖❌❓\u{1F7E1}\u{1F7E0}\u{1F534}\u{1F7E2}]/gu),
    ].map((m) => m[0]);
    const allowed = new Set(['✅', '⚠', '➖', '\u{1F7E1}']);
    expect([...new Set(markers)].filter((m) => !allowed.has(m))).toEqual([]);
  });

  it('the subtype repertoire §8 claims is the one the engine registers', () => {
    expect(
      claimRow(readDoc(), 'engine account subtypes'),
      'the repertoire moved — §§ 1 and 3 of the census argue from the absence of an inventory subtype',
    ).toEqual([...ACCOUNT_SUBTYPES]);
  });

  /**
   * The two checks that make this gate what it is: the census names the operations and projections
   * summae does **not** have, and building one of them must break this.
   */
  it('the operations §8 calls missing are still missing', () => {
    const operations = declared('operations');
    const built = claimRow(readDoc(), 'operations the engine does not have').filter((name) =>
      operations.has(name),
    );
    expect(
      built,
      'these are built and the census still calls them missing — open docs/hgb-conformance.md, ' +
        'move the row to ✅ with its fixture named, and take the name out of §8',
    ).toEqual([]);
  });

  it('the projections §8 calls missing are still missing', () => {
    const projections = declared('projections');
    const built = claimRow(readDoc(), 'projections the engine does not have').filter((name) =>
      projections.has(name),
    );
    expect(
      built,
      'these are built and the census still calls them missing — open docs/hgb-conformance.md, ' +
        'move the row to ✅ with its fixture named, and take the name out of §8',
    ).toEqual([]);
  });

  it('the balance-sheet positions §8 claims are the ones the de pack ships', () => {
    const doc = readDoc();
    const mapping = readJson('pack-library/de-pack/mappings/de-bilanz.json');
    expect(
      claimRow(doc, 'de balance sheet, asset positions'),
      'the German balance sheet changed shape — § 266 rows in §§ 3 and 5 argue from this list',
    ).toEqual(positionKeys(mapping, 'assets'));
    expect(claimRow(doc, 'de balance sheet, liability positions')).toEqual(
      positionKeys(mapping, 'liabilitiesAndEquity'),
    );
  });

  it('the income-statement positions §8 claims are the ones the de pack ships', () => {
    expect(
      claimRow(readDoc(), 'de income statement positions'),
      'the § 275 Abs. 2 row claims exactly six positions and names what is missing from them',
    ).toEqual(positionKeys(readJson('pack-library/de-pack/mappings/de-guv.json'), null));
  });

  it('the chart subtypes §8 claims are the ones the de chart uses', () => {
    const chart = readJson('pack-library/de-pack/accounts/de-konten.json') as {
      data?: { accounts?: Array<{ subtype?: unknown }> };
    };
    const used = [
      ...new Set(
        (chart.data?.accounts ?? [])
          .map((a) => a.subtype)
          .filter((s): s is string => typeof s === 'string'),
      ),
    ].sort();
    expect(claimRow(readDoc(), 'de chart, subtypes actually used')).toEqual(used);
  });

  it('the module kinds §8 claims are the ones the de pack bundles', () => {
    const manifest = readJson('pack-library/de-pack/de.json') as { modules?: Array<{ kind?: unknown }> };
    const kinds = [
      ...new Set((manifest.modules ?? []).map((m) => m.kind).filter((k): k is string => typeof k === 'string')),
    ].sort();
    expect(
      claimRow(readDoc(), 'de pack, module kinds'),
      'the German pack gained or lost a module kind — the census reads this list for what it does not contain',
    ).toEqual(kinds);
  });
});
