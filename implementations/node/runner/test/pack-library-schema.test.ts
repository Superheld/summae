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
 * Layer 1: the module/manifest WRAPPER (kind enum, required keys, no stray keys).
 * Layer 2 ("tief per-kind"): validate each module's `data` against a per-kind schema. The
 * `mapping` and `policy` kinds are already deeply schema'd (`#/$defs/mapping` incl.
 * positions/mappingPosition, `#/$defs/packPolicy`), so their `data` is validated here. The other
 * kinds (accounts/tax/depreciation/assetAccounts) still need per-kind sub-schemas authored in the
 * knowledge base — tracked separately.
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

    // Layer 2: kinds whose `data.<key>` is already deeply schema'd by an existing $def.
    // (accounts/tax/depreciation/assetAccounts need per-kind sub-schemas authored in the WB.)
    const deepByKind: Record<string, { def: string; key: string }> = {
      mapping: { def: 'mapping', key: 'mapping' },
      policy: { def: 'packPolicy', key: 'packPolicy' },
    };

    // Guard has teeth: a malformed module is rejected (bad kind, missing required keys).
    expect(validateModule({ kind: 'not-a-real-kind' }), 'validator must reject a bad module').toBe(false);

    const violations: string[] = [];
    for (const file of jsonFiles(packLibraryDir)) {
      const doc: unknown = JSON.parse(readFileSync(file, 'utf8'));
      const record = doc !== null && typeof doc === 'object' ? (doc as Record<string, unknown>) : null;
      const validate = isManifest(doc) ? validateManifest : validateModule;
      if (!validate(doc)) {
        violations.push(`${relative(packLibraryDir, file)}: ${ajv.errorsText(validate.errors)}`);
      }
      const deep = record !== null && typeof record.kind === 'string' ? deepByKind[record.kind] : undefined;
      if (deep && record !== null) {
        const data = record.data;
        const inner = data !== null && typeof data === 'object' ? (data as Record<string, unknown>)[deep.key] : undefined;
        const validateDeep = ajv.getSchema(`${schema.$id}#/$defs/${deep.def}`) as ValidateFunction;
        if (!validateDeep(inner)) {
          violations.push(`${relative(packLibraryDir, file)} (data.${deep.key}): ${ajv.errorsText(validateDeep.errors)}`);
        }
      }
    }

    expect(violations, 'every pack-library module + manifest must validate against the schema').toEqual([]);
  });
});
