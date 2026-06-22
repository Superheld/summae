import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join, resolve } from 'node:path';
import {
  type PackManifest,
  type PackModule,
  resolvePack,
  ruleModulesFromResolved,
} from '@superheld/summae-core';

const here = dirname(fileURLToPath(import.meta.url));

/** Default home of the shipped pack library (repo root; overridable via --pack-library). */
export const defaultPackLibraryDir = resolve(here, '../../../../../pack-library');

function isRecord(v: unknown): v is Record<string, unknown> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

function readJsonRecursive(dir: string): unknown[] {
  if (!existsSync(dir)) return [];
  const out: unknown[] = [];
  const walk = (current: string): void => {
    for (const entry of readdirSync(current, { withFileTypes: true })) {
      const path = join(current, entry.name);
      if (entry.isDirectory()) walk(path);
      else if (entry.isFile() && entry.name.endsWith('.json')) out.push(JSON.parse(readFileSync(path, 'utf8')));
    }
  };
  walk(dir);
  return out;
}

/** Load pack library, classified by content (manifest=has `modules[]`, module=has `kind`). */
export function loadPackLibrary(dir: string): { modules: PackModule[]; manifests: PackManifest[] } {
  const modules: PackModule[] = [];
  const manifests: PackManifest[] = [];
  for (const json of readJsonRecursive(dir)) {
    if (!isRecord(json)) continue;
    if (Array.isArray(json.modules)) manifests.push(json as unknown as PackManifest);
    else if (typeof json.kind === 'string') modules.push(json as unknown as PackModule);
  }
  return { modules, manifests };
}

/**
 * Resolve pack `<id>` from the library → CLI `rules` structure that `Workspace`/`init`
 * consume (ruleModules bundle + accounts + taxCodes + taxProfile). This way the CLI selects
 * a shipped pack instead of maintaining rules inline in `summae.json`.
 */
export function packToRules(packId: string, libDir: string): Record<string, unknown> {
  const lib = loadPackLibrary(libDir);
  const manifest = lib.manifests.find((m) => m.id === packId);
  if (manifest === undefined) {
    throw new Error(`Pack "${packId}" not found in the library (${libDir})`);
  }
  const rm = ruleModulesFromResolved(resolvePack(manifest, lib.modules));
  const coa = Array.isArray(rm.chartsOfAccounts) && isRecord(rm.chartsOfAccounts[0]) ? rm.chartsOfAccounts[0] : {};
  const profile = Array.isArray(rm.profiles) && isRecord(rm.profiles[0]) ? rm.profiles[0] : {};
  return {
    pack: { id: packId },
    ruleModules: rm,
    accounts: Array.isArray(coa.accounts) ? coa.accounts : [],
    taxCodes: rm.taxCodes,
    taxProfile: isRecord(profile.defaults) ? profile.defaults : {},
  };
}
