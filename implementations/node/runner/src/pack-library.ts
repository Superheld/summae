import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { PackManifest, PackModule } from '@superheld/summae-core';
import { repoRoot } from './fixture-loader.js';

/** Heimat der ausgelieferten Pack-Bibliothek im Repo (gespiegelt aus der WB). */
export const packLibraryDir = join(repoRoot, 'pack-library');

export interface PackLibrary {
  /** Wiederverwendbare Bausteine aus `modules/<kind>/<id>.json`. */
  readonly modules: PackModule[];
  /** Wählbare Packs aus `packs/<id>.json`. */
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
 * Lädt die ausgelieferte Pack-Bibliothek von der Platte: Module (rekursiv unter
 * `modules/`) und Manifeste (unter `packs/`). Das ist der Loader, der den
 * reinen Resolver mit echten Produkt-Daten füttert — Pendant zu „Pack bei
 * Installation/Anlegen wählen". Ergebnis wird gecacht (read-only Daten).
 */
export function loadPackLibrary(dir: string = packLibraryDir): PackLibrary {
  if (dir === packLibraryDir && cached !== null) return cached;
  // Inhaltsbasierte Klassifikation: Ordnerstruktur egal — `modules/`+`packs/` ODER ein
  // gesammelter Pack-Ordner (z. B. `de-pack/`). Manifest = hat `modules[]`; Modul = hat `kind`.
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
