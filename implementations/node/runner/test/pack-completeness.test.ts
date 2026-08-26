import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

/**
 * Quality-gate obligation: a shipped pack must be COMPLETE, not merely well-formed.
 *
 * `pack-library-schema` proves that every module parses and matches the format. It cannot notice
 * that a pack ships an account no statement assigns, or a tax code the DATEV export cannot label.
 * Neither can the conformance fixtures: they exercise mechanism with inline rule data of their own,
 * so a pack whose data is thin still passes everything. That gap produced three separate defects
 * found on 2026-08-23, all of the same shape — the engine was right, the shipped product data was
 * not:
 *
 *   - `de-euer` left four of its own accounts unassigned, among them the small-business revenue
 *     account, which is the single most likely account for a cash-basis filer to use;
 *   - `us-schedule-c` left one;
 *   - not one `de-ust` tax code carried a DATEV key, so every exported batch line lost its tax.
 *
 * Mirror of the PHP `PackCompletenessTest`; the rules must stay identical in both languages.
 */
const here = dirname(fileURLToPath(import.meta.url));
const packLibraryDir = join(here, '..', '..', '..', '..', 'pack-library');

/**
 * Mapping kinds that present profit and loss, and therefore have to account for EVERY revenue and
 * expense account the pack ships. A balance sheet legitimately touches none of them, which is why
 * the obligation is per kind rather than "every mapping".
 */
const PROFIT_KINDS = ['income-statement', 'cash-basis-categories'];

/** Every mapping kind currently shipped. An unknown one must be classified, not ignored. */
const KNOWN_KINDS = ['income-statement', 'cash-basis-categories', 'balance-sheet'];

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function packs(): Array<{ name: string; dir: string }> {
  return readdirSync(packLibraryDir, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => ({ name: entry.name, dir: join(packLibraryDir, entry.name) }))
    .sort((a, b) => a.name.localeCompare(b.name));
}

function modulesIn(dir: string, sub: string): Array<{ file: string; module: Record<string, unknown> }> {
  const target = join(dir, sub);
  if (!existsSync(target)) return [];
  return readdirSync(target)
    .filter((name) => name.endsWith('.json'))
    .sort()
    .map((name) => {
      const parsed: unknown = JSON.parse(readFileSync(join(target, name), 'utf8'));
      return { file: basename(name), module: isRecord(parsed) ? parsed : {} };
    });
}

/** Account numbers a mapping assigns, ranges expanded. */
function coveredAccounts(node: unknown, out: Set<string> = new Set()): Set<string> {
  if (Array.isArray(node)) {
    for (const child of node) coveredAccounts(child, out);
    return out;
  }
  if (!isRecord(node)) return out;

  if ('key' in node && Array.isArray(node.accounts)) {
    for (const spec of node.accounts) {
      if (!isRecord(spec)) continue;
      if (Array.isArray(spec.numbers)) {
        for (const number of spec.numbers) if (typeof number === 'string') out.add(number);
      }
      if (typeof spec.from === 'string' && typeof spec.to === 'string') {
        for (let n = Number(spec.from); n <= Number(spec.to); n++) out.add(String(n));
      }
    }
  }

  for (const child of Object.values(node)) coveredAccounts(child, out);
  return out;
}

/**
 * Every position node of a mapping, with the account numbers it claims. `coveredAccounts` answers
 * "does the mapping cover this account at all"; the rule below needs to know *which* position does.
 */
function positions(
  node: unknown,
  out: Array<{ key: string; accounts: Set<string>; includesNetIncome: boolean }> = [],
): Array<{ key: string; accounts: Set<string>; includesNetIncome: boolean }> {
  if (Array.isArray(node)) {
    for (const child of node) positions(child, out);
    return out;
  }
  if (!isRecord(node)) return out;

  if (typeof node.key === 'string' && (Array.isArray(node.accounts) || node.includesNetIncome === true)) {
    out.push({
      key: node.key,
      accounts: coveredAccounts({ key: node.key, accounts: Array.isArray(node.accounts) ? node.accounts : [] }),
      includesNetIncome: node.includesNetIncome === true,
    });
  }

  for (const child of Object.values(node)) positions(child, out);
  return out;
}

describe('pack completeness', () => {
  it('assigns every profit-and-loss account a position in every statement', () => {
    const violations: string[] = [];

    for (const { name, dir } of packs()) {
      const profitAccounts: string[] = [];
      for (const { module } of modulesIn(dir, 'accounts')) {
        const data = isRecord(module.data) ? module.data : {};
        if (!Array.isArray(data.accounts)) continue;
        for (const account of data.accounts) {
          if (!isRecord(account)) continue;
          if (typeof account.number === 'string' && (account.type === 'revenue' || account.type === 'expense')) {
            profitAccounts.push(account.number);
          }
        }
      }
      if (profitAccounts.length === 0) continue;

      for (const { file, module } of modulesIn(dir, 'mappings')) {
        const data = isRecord(module.data) ? module.data : {};
        const mapping = isRecord(data.mapping) ? data.mapping : {};
        const kind = typeof mapping.kind === 'string' ? mapping.kind : '';

        if (!KNOWN_KINDS.includes(kind)) {
          violations.push(`${file}: unknown mapping kind "${kind}" — classify it in PROFIT_KINDS or not`);
          continue;
        }
        if (!PROFIT_KINDS.includes(kind)) continue;

        const covered = coveredAccounts(mapping);
        const missing = profitAccounts.filter((account) => !covered.has(account));
        if (missing.length > 0) {
          violations.push(`${name} (${file}): ${kind} assigns no position to ${missing.join(', ')}`);
        }
      }
    }

    expect(
      violations,
      'A statement that assigns some accounts but not others reports a total that is silently ' +
        'incomplete, and the money surfaces under a raw account name instead of a position',
    ).toEqual([]);
  });

  /**
   * A pack that offers DATEV keys at all offers them for every tax code that books tax.
   *
   * DATEV is a German interchange format, so no pack is obliged to support it — demanding a key from
   * the `us` pack's sales tax would be exporting one jurisdiction's tooling into every other. The
   * obligation is therefore conditional, the same shape as `poolProRataInFirstYear`: silence is a
   * valid answer, half an answer is not. Once a pack declares one key the rest become mandatory,
   * because the batch folds the tax line into the gross amount and rebuilds it from the key — a code
   * without one exports its gross with the tax silently gone.
   *
   * Scoped to `standard` on purpose. Only the plain output/input keys are unambiguous (2 and 3 for
   * 7 % and 19 % output, 8 and 9 for input). Reverse charge and intra-community supply map onto
   * several DATEV keys depending on the underlying transaction, and this suite does not put a guess
   * into a shipped pack: a wrong key posts the wrong tax at the recipient, which is worse than an
   * absent one.
   */
  it('gives every tax-booking code a DATEV key once a pack declares any', () => {
    const violations: string[] = [];

    for (const { name, dir } of packs()) {
      for (const { module } of modulesIn(dir, 'tax')) {
        const data = isRecord(module.data) ? module.data : {};
        const taxCodes = Array.isArray(data.taxCodes) ? data.taxCodes : [];

        // Does this module speak DATEV at all? If not, it owes nothing here.
        const declaresAny = taxCodes.some((code) => isRecord(code) && typeof code.datevBu === 'string');
        if (!declaresAny) continue;

        for (const code of taxCodes) {
          if (!isRecord(code)) continue;
          const versions = Array.isArray(code.versions) ? code.versions : [];
          const booksTax = versions.some(
            (version) =>
              isRecord(version) && (version.mechanism ?? 'standard') === 'standard' && typeof version.taxAccount === 'string',
          );
          if (booksTax && typeof code.datevBu !== 'string') {
            violations.push(`${name}: tax code "${typeof code.code === 'string' ? code.code : '?'}" books tax but carries no datevBu`);
          }
        }
      }
    }

    expect(
      violations,
      'The DATEV batch folds the tax line into the gross amount and recreates it from the key. ' +
        'Without a key the exported line carries the gross with no tax at all',
    ).toEqual([]);
  });
  /**
   * A result-allocation account belongs to the position that carries the result — and to no other.
   *
   * The appropriation of profit is a resolution, not a calculation, so summae does not post it on
   * anyone's behalf: the decision arrives as an ordinary entry, `result_allocation` account against
   * retained earnings or a distribution liability (`datenformat.md`, F-CORE-024/SF-25). The balance
   * sheet then subtracts that account's balance from the position with `includesNetIncome`, which is
   * what makes the position mean "the result not yet appropriated".
   *
   * That only works if the mapping puts the account there. Both shipped packs put it somewhere else,
   * and neither the schema nor a single fixture could see it, because a fixture brings a mapping of
   * its own that gets it right. The effect was that the appropriation entry cancelled itself out
   * INSIDE the equity position it had wrongly landed in: `de-bilanz` claimed 2000–2499 wholesale,
   * which swallowed 2300 next to the retained-earnings account 2100, and `us-gaap-balance-sheet`
   * claimed 3000–3999, swallowing Income Summary 3300 next to Retained Earnings 3100. Book the
   * resolution correctly and the balance sheet did not move — the result stayed labelled as this
   * year's for every year that followed, and the only visible effect of a correct entry was a new
   * zero row.
   *
   * `E_MAPPING_OVERLAP` is why the repair is not "add the account": a wholesale range has to be cut
   * around it, which is presumably how it was missed in the first place.
   *
   * Mirror of the PHP `PackCompletenessTest`; the rules must stay identical in both languages.
   */
  it('puts every result-allocation account in the position that carries the result', () => {
    const violations: string[] = [];

    for (const { name, dir } of packs()) {
      const allocationAccounts: string[] = [];
      for (const { module } of modulesIn(dir, 'accounts')) {
        const data = isRecord(module.data) ? module.data : {};
        if (!Array.isArray(data.accounts)) continue;
        for (const account of data.accounts) {
          if (!isRecord(account)) continue;
          if (typeof account.number === 'string' && account.subtype === 'result_allocation') {
            allocationAccounts.push(account.number);
          }
        }
      }
      if (allocationAccounts.length === 0) continue;

      for (const { file, module } of modulesIn(dir, 'mappings')) {
        const data = isRecord(module.data) ? module.data : {};
        const mapping = isRecord(data.mapping) ? data.mapping : {};
        if (mapping.kind !== 'balance-sheet') continue;

        const nodes = positions(mapping);
        const result = nodes.find((position) => position.includesNetIncome);
        if (result === undefined) {
          violations.push(`${name} (${file}): balance sheet has no position with includesNetIncome — the result lands nowhere`);
          continue;
        }

        for (const account of allocationAccounts) {
          const claiming = nodes.filter((position) => position.accounts.has(account)).map((position) => position.key);
          if (claiming.length === 1 && claiming[0] === result.key) continue;
          violations.push(
            `${name} (${file}): ${account} is claimed by ${claiming.length === 0 ? 'no position' : claiming.join(', ')}, ` +
              `not by ${result.key} which carries the result`,
          );
        }
      }
    }

    expect(
      violations,
      'An appropriation entry booked against a result-allocation account outside the result position ' +
        'cancels itself out and leaves the balance sheet reporting an appropriated result as unappropriated',
    ).toEqual([]);
  });
});
