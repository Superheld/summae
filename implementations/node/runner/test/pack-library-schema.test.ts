import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020, { type ValidateFunction } from 'ajv/dist/2020.js';
import { describe, expect, it } from 'vitest';

/**
 * Quality-gate obligation 1: every shipped pack-library module + manifest is validated
 * against testsuite/schema/format.schema.json — the same schema the PHP runner already
 * validates journalExport streams against (SchemaValidationTest), now extended to the
 * pack format in both languages. A field the engine reads but the schema does not
 * declare is a finding (the NF-002/F-008 class), not a convenience.
 *
 * Layer 1 (here): the module/manifest WRAPPER (kind enum, required keys, no stray keys).
 * Deep per-kind validation of each module's `data` ("tief per-kind") is layer 2 — it
 * needs per-kind sub-schemas authored in the knowledge base and is tracked separately.
 */
const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..', '..', '..');
const packLibraryDir = join(repoRoot, 'pack-library');
const schemaPath = join(repoRoot, 'testsuite', 'schema', 'format.schema.json');

function jsonFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...jsonFiles(path));
    else if (entry.isFile() && entry.name.endsWith('.json')) out.push(path);
  }
  return out;
}

function isManifest(doc: unknown): boolean {
  return (
    doc !== null &&
    typeof doc === 'object' &&
    Array.isArray((doc as Record<string, unknown>).modules) &&
    'packPolicy' in (doc as Record<string, unknown>)
  );
}

describe('pack-library files validate against format.schema.json', () => {
  it('every module and manifest conforms to its schema definition', () => {
    const schema = JSON.parse(readFileSync(schemaPath, 'utf8')) as { $id: string };
    const ajv = new Ajv2020({ strict: false });
    ajv.addSchema(schema);
    const validateModule = ajv.getSchema(`${schema.$id}#/$defs/module`) as ValidateFunction;
    const validateManifest = ajv.getSchema(`${schema.$id}#/$defs/packManifest`) as ValidateFunction;

    // Guard has teeth: a malformed module is rejected (bad kind, missing required keys).
    expect(validateModule({ kind: 'not-a-real-kind' }), 'validator must reject a bad module').toBe(false);

    const violations: string[] = [];
    for (const file of jsonFiles(packLibraryDir)) {
      const doc: unknown = JSON.parse(readFileSync(file, 'utf8'));
      const validate = isManifest(doc) ? validateManifest : validateModule;
      if (!validate(doc)) {
        violations.push(`${relative(packLibraryDir, file)}: ${ajv.errorsText(validate.errors)}`);
      }
    }

    expect(violations, 'every pack-library module + manifest must validate against the schema').toEqual([]);
  });
});
