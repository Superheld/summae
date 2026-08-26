import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * The pack documentation describes the packs that are actually shipped.
 *
 * `knowledge/99-pack-docs/` is the reference work for whoever builds or audits a pack — one file
 * per module, position by position, account by account. Nothing held it against the modules, and
 * on 2026-08-26 it turned out that two of the three balance-sheet documents described a product
 * that does not exist: the `de` document listed positions `A`, `B.I`, `B.II`, `B.III` where the
 * module ships `A.I`–`A.V` and `P.A1`–`P.D`, and the `us` document had the two sides of the chart
 * swapped — equity at 2000–2499, payables at 3000–3099, the exact opposite of what the pack does.
 * They read as design notes written before the modules were built and never reconciled (IMPL-031).
 *
 * A reference work that is wrong is worse than none: it is believed. So the parts a machine can
 * check are checked, and the prose is left alone.
 *
 * Three rules, each narrow enough that a pack author can satisfy it without guessing:
 *  1. every module the manifest lists has exactly one document, found by its own `id:` header;
 *  2. that header states the module's real `kind`, `id` and `version` — so a version bump cannot
 *     land without the document being opened;
 *  3. a mapping document's table names every position of its module, and each row carries the
 *     account selection that position really has.
 *
 * Deliberately NOT checked: labels and prose. A document may call a position something clearer
 * than the module's own label, and should.
 *
 * Mirror of the PHP `PackDocsTest`; the rules must stay identical in both languages.
 */
const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..', '..', '..');
const packLibraryDir = join(repoRoot, 'pack-library');
const packDocsDir = join(repoRoot, 'knowledge', '99-pack-docs');

interface Doc {
  file: string;
  text: string;
  header: Record<string, string>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

/** Packs that ship modules AND have a documentation folder. */
function packs(): Array<{ name: string; dir: string; docs: string }> {
  if (!existsSync(packLibraryDir)) return [];
  return readdirSync(packLibraryDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => ({
      name: entry.name,
      dir: join(packLibraryDir, entry.name),
      docs: join(packDocsDir, entry.name),
    }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

function manifestOf(dir: string): Record<string, unknown> | null {
  for (const name of readdirSync(dir).sort()) {
    if (!name.endsWith('.json')) continue;
    const parsed: unknown = JSON.parse(readFileSync(join(dir, name), 'utf8'));
    if (isRecord(parsed) && Array.isArray(parsed.modules)) return parsed;
  }
  return null;
}

/** Every module file of a pack, by id. */
function modulesOf(dir: string): Map<string, Record<string, unknown>> {
  const out = new Map<string, Record<string, unknown>>();
  const walk = (current: string): void => {
    for (const entry of readdirSync(current, { withFileTypes: true })) {
      const path = join(current, entry.name);
      if (entry.isDirectory()) {
        // `versions/` holds retired copies on purpose — they are not what the pack ships today.
        if (entry.name !== 'versions') walk(path);
        continue;
      }
      if (!entry.name.endsWith('.json')) continue;
      const parsed: unknown = JSON.parse(readFileSync(path, 'utf8'));
      if (isRecord(parsed) && typeof parsed.kind === 'string' && typeof parsed.id === 'string') {
        out.set(parsed.id, parsed);
      }
    }
  };
  walk(dir);
  return out;
}

/**
 * The header block every module document opens with: `kind: x · id: y · version: z`, in a fenced
 * block, one fact per `key: value` pair separated by `·`.
 */
function docsIn(dir: string): Doc[] {
  if (!existsSync(dir)) return [];
  return readdirSync(dir)
    .filter((name) => name.endsWith('.md'))
    .sort()
    .map((name) => {
      const text = readFileSync(join(dir, name), 'utf8');
      const header: Record<string, string> = {};
      const fence = /```[\s\S]*?```/.exec(text)?.[0] ?? '';
      for (const part of fence.split(/[·\n]/)) {
        const match = /^\s*(kind|id|version|formatVersion)\s*:\s*([A-Za-z0-9._-]+)/.exec(part);
        if (match !== null && match[1] !== undefined && match[2] !== undefined) header[match[1]] = match[2];
      }
      return { file: basename(name), text, header };
    });
}

/** Leaf positions of a mapping — the ones that carry accounts. */
function leaves(node: unknown, out: Array<Record<string, unknown>> = []): Array<Record<string, unknown>> {
  if (Array.isArray(node)) {
    for (const child of node) leaves(child, out);
    return out;
  }
  if (!isRecord(node)) return out;
  if (typeof node.key === 'string' && !('children' in node)) out.push(node);
  for (const child of Object.values(node)) leaves(child, out);
  return out;
}

/** How a position's account selection reads, in the form the documents use. */
function selectors(leaf: Record<string, unknown>): string[] {
  const out: string[] = [];
  for (const spec of Array.isArray(leaf.accounts) ? leaf.accounts : []) {
    if (!isRecord(spec)) continue;
    if (Array.isArray(spec.numbers)) {
      for (const number of spec.numbers) if (typeof number === 'string') out.push(number);
    } else if (typeof spec.from === 'string' && typeof spec.to === 'string') {
      out.push(`${spec.from}-${spec.to}`);
    }
  }
  return out;
}

/** En dashes, spaces and thousands dots are formatting, not content. */
function normalise(text: string): string {
  return text.replace(/[–—]/g, '-').replace(/\s+/g, '');
}

describe('pack documentation', () => {
  it('gives every shipped module exactly one document', () => {
    const violations: string[] = [];

    for (const pack of packs()) {
      const manifest = manifestOf(pack.dir);
      if (manifest === null) continue;
      const docs = docsIn(pack.docs);
      if (docs.length === 0) {
        violations.push(`${pack.name}: ships ${(manifest.modules as unknown[]).length} modules and has no documentation folder at all`);
        continue;
      }

      for (const ref of manifest.modules as unknown[]) {
        if (!isRecord(ref) || typeof ref.id !== 'string') continue;
        const matching = docs.filter((doc) => doc.header.id === ref.id);
        if (matching.length === 0) {
          violations.push(`${pack.name}: module "${ref.id}" (${String(ref.kind)}) has no document`);
        } else if (matching.length > 1) {
          violations.push(`${pack.name}: module "${ref.id}" is documented twice: ${matching.map((d) => d.file).join(', ')}`);
        }
      }
    }

    expect(
      violations,
      'A module nobody documented is a module nobody can audit — and the documents are the ' +
        'reference work a pack author reads before touching the data',
    ).toEqual([]);
  });

  it('states the real kind, id and version in every header', () => {
    const violations: string[] = [];

    for (const pack of packs()) {
      const modules = modulesOf(pack.dir);
      for (const doc of docsIn(pack.docs)) {
        const id = doc.header.id;
        if (id === undefined) continue; // not a module document (README, decisions, …)
        const module = modules.get(id);
        if (module === undefined) {
          violations.push(`${pack.name} (${doc.file}): documents "${id}", which is not a module of this pack`);
          continue;
        }
        for (const field of ['kind', 'version', 'formatVersion'] as const) {
          const stated = doc.header[field];
          const real = module[field];
          if (stated !== undefined && stated !== real) {
            violations.push(`${pack.name} (${doc.file}): says ${field} ${stated}, the module says ${String(real)}`);
          }
        }
      }
    }

    expect(
      violations,
      'The header is what a reader trusts before reading anything else, and a version bump that ' +
        'leaves it behind makes the whole document undatable',
    ).toEqual([]);
  });

  it('lists every mapping position with the accounts it really claims', () => {
    const violations: string[] = [];

    for (const pack of packs()) {
      const modules = modulesOf(pack.dir);
      for (const doc of docsIn(pack.docs)) {
        const id = doc.header.id;
        if (id === undefined) continue;
        const module = modules.get(id);
        if (module === undefined || module.kind !== 'mapping') continue;

        const data = isRecord(module.data) ? module.data : {};
        const mapping = isRecord(data.mapping) ? data.mapping : {};
        // Table rows, by their first cell — that is where every document puts the position key.
        const rows = new Map<string, string>();
        for (const line of doc.text.split('\n')) {
          const cells = line.split('|').map((cell) => cell.trim());
          const key = cells[1];
          if (cells.length > 2 && key !== undefined && /^[A-Za-z0-9._]+$/.test(key)) rows.set(key, line);
        }

        for (const leaf of leaves(mapping.positions)) {
          const key = String(leaf.key);
          const row = rows.get(key);
          if (row === undefined) {
            violations.push(`${pack.name} (${doc.file}): position ${key} is in the module and in no table row`);
            continue;
          }
          const missing = selectors(leaf).filter((selector) => !normalise(row).includes(normalise(selector)));
          if (missing.length > 0) {
            violations.push(`${pack.name} (${doc.file}): row ${key} does not name ${missing.join(', ')}`);
          }
        }
      }
    }

    expect(
      violations,
      'A mapping table that names other accounts than the mapping is the most dangerous kind of ' +
        'wrong: it is specific, it looks checked, and it is what somebody builds the next pack from',
    ).toEqual([]);
  });
});
