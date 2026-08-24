import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020, { type ValidateFunction } from 'ajv/dist/2020.js';
import { describe, expect, it } from 'vitest';

/**
 * Quality-gate obligation 1: every shipped pack-library module + manifest is validated
 * against testing/testsuite/schema/format.schema.json — the same schema the PHP runner already
 * validates journalExport streams against (SchemaValidationTest), now extended to the
 * pack format in both languages. A field the engine reads but the schema does not
 * declare is a finding (the IMPL-002/SPEC-008 class), not a convenience.
 *
 * Layer 1: the module/manifest WRAPPER (kind enum, required keys, no stray keys).
 * Layer 2 ("tief per-kind"): validate each module's `data` against a per-kind schema. `mapping`,
 * `policy` and `depreciation` are deeply schema'd (`#/$defs/mapping` incl. positions,
 * `#/$defs/packPolicy`, `#/$defs/depreciationData`), so their `data` is validated here. The
 * remaining kinds (accounts/tax/assetAccounts) still need per-kind sub-schemas authored in the
 * knowledge base — tracked separately.
 */
const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..', '..', '..');
const packLibraryDir = join(repoRoot, 'pack-library');
const schemaPath = join(repoRoot, 'testing', 'testsuite', 'schema', 'format.schema.json');

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

    // Layer 2: kinds whose `data` is already deeply schema'd by an existing $def.
    // `key: null` = the whole `data` object is the payload (depreciation keeps gwgThresholds and
    // usefulLife at the top level); a string key = the payload sits under `data.<key>`.
    // (accounts/tax/assetAccounts need per-kind sub-schemas authored in the WB.)
    const deepByKind: Record<string, { def: string; key: string | null }> = {
      mapping: { def: 'mapping', key: 'mapping' },
      policy: { def: 'packPolicy', key: 'packPolicy' },
      depreciation: { def: 'depreciationData', key: null },
      productionCost: { def: 'productionCostData', key: null },
      constraint: { def: 'constraintData', key: null },
    };

    // Guard has teeth: a malformed module is rejected (bad kind, missing required keys).
    expect(validateModule({ kind: 'not-a-real-kind' }), 'validator must reject a bad module').toBe(false);

    // …and the depreciation payload rejects a pool range that leaves a jurisdiction's answer to the
    // core. Three questions are conditionally required next to poolMax: the period (SPEC-004,
    // poolYears), whether a disposal reduces the pool (IMPL-019, poolReducedOnDisposal), and whether
    // the first year is shortened by the acquisition month (poolProRataInFirstYear). Each one a pack
    // may omit is one a jurisdiction inherits from whoever wrote the core.
    const validateDepreciation = ajv.getSchema(`${schema.$id}#/$defs/depreciationData`) as ValidateFunction;
    const poolRange = { validFrom: '2018-01-01', validTo: null, immediateMax: '250.00', poolMin: '250.01', poolMax: '1000.00' };
    expect(
      validateDepreciation({ gwgThresholds: [{ ...poolRange, poolReducedOnDisposal: false, poolProRataInFirstYear: false }] }),
      'a pool range without poolYears must be rejected',
    ).toBe(false);
    expect(
      validateDepreciation({ gwgThresholds: [{ ...poolRange, poolYears: 5, poolProRataInFirstYear: false }] }),
      'a pool range without poolReducedOnDisposal must be rejected',
    ).toBe(false);
    expect(
      validateDepreciation({ gwgThresholds: [{ ...poolRange, poolYears: 5, poolReducedOnDisposal: false }] }),
      'a pool range without poolProRataInFirstYear must be rejected',
    ).toBe(false);
    expect(
      validateDepreciation({
        gwgThresholds: [{ ...poolRange, poolYears: 5, poolReducedOnDisposal: false, poolProRataInFirstYear: false }],
      }),
      'the same range with all three answers must pass',
    ).toBe(true);

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
        const payload = data !== null && typeof data === 'object' ? (data as Record<string, unknown>) : undefined;
        const inner = deep.key === null ? payload : payload?.[deep.key];
        const validateDeep = ajv.getSchema(`${schema.$id}#/$defs/${deep.def}`) as ValidateFunction;
        if (!validateDeep(inner)) {
          const where = deep.key === null ? 'data' : `data.${deep.key}`;
          violations.push(`${relative(packLibraryDir, file)} (${where}): ${ajv.errorsText(validateDeep.errors)}`);
        }
      }
    }

    expect(violations, 'every pack-library module + manifest must validate against the schema').toEqual([]);
  });
});
