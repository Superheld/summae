import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { PackManifest, PackModule } from '@superheld/summae-core';
import { repoRoot } from './fixture-loader.js';

/** Home of the shipped pack library in the repo (mirrored from the knowledge base). */
export const packLibraryDir = join(repoRoot, 'pack-library');

export interface PackLibrary {
  /** Reusable building blocks from `modules/<kind>/<id>.json`. */
  readonly modules: PackModule[];
  /** Selectable packs from `packs/<id>.json`. */
  readonly manifests: PackManifest[];
}

function readJsonFilesRecursive(dir: string): unknown[] {
  if (!existsSync(dir)) return [];
  const out: unknown[] = [];
  const walk = (current: string): void => {
    for (const entry of readdirSync(current, { withFileTypes: true })) {
      const path = join(current, entry.name);
      if (entry.isDirectory()) walk(path);
      else if (entry.isFile() && entry.name.endsWith('.json')) {
        out.push(JSON.parse(readFileSync(path, 'utf8')));
      }
    }
  };
  walk(dir);
  return out;
}

let cached: PackLibrary | null = null;

/**
 * Loads the shipped pack library from disk: modules (recursively under
 * `modules/`) and manifests (under `packs/`). This is the loader that feeds the
 * pure resolver with real product data — counterpart to "choose a pack at
 * installation/creation". Result is cached (read-only data).
 */
export function loadPackLibrary(dir: string = packLibraryDir): PackLibrary {
  if (dir === packLibraryDir && cached !== null) return cached;
  // Content-based classification: folder structure irrelevant — `modules/`+`packs/` OR a
  // collected pack folder (e.g. `de-pack/`). Manifest = has `modules[]`; module = has `kind`.
  const modules: PackModule[] = [];
  const manifests: PackManifest[] = [];
  for (const json of readJsonFilesRecursive(dir)) {
    if (json === null || typeof json !== 'object') continue;
    const rec = json as Record<string, unknown>;
    if (Array.isArray(rec.modules)) manifests.push(rec as unknown as PackManifest);
    else if (typeof rec.kind === 'string') modules.push(rec as unknown as PackModule);
  }
  const library: PackLibrary = { modules, manifests };
  if (dir === packLibraryDir) cached = library;
  return library;
}
