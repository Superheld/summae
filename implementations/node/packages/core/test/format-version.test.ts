import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { FORMAT_VERSION } from '../src/substrate/format-version.js';

/**
 * The export manifest must state the current spec version — datenformat.md says so in as many
 * words. It said 0.4 for two spec releases while the schema, the pack modules and the parameter
 * contract were on 0.6, and nothing noticed, because a version number that is merely wrong still
 * looks like a version number.
 *
 * The schema's `$id` carries the authoritative version, so that is what this compares against.
 * The SAME check lives in the PHP FormatVersionTest.
 */
const here = dirname(fileURLToPath(import.meta.url));
const schemaPath = join(here, '..', '..', '..', '..', '..', 'testing', 'testsuite', 'schema', 'format.schema.json');
const schema = JSON.parse(readFileSync(schemaPath, 'utf8')) as { $id: string };

describe('format version', () => {
  it('matches the version in the schema $id', () => {
    const fromSchema = /\/format\/([0-9]+\.[0-9]+)\//.exec(schema.$id)?.[1];
    expect(fromSchema, `could not read a version out of ${schema.$id}`).toBeDefined();
    expect(FORMAT_VERSION).toBe(fromSchema);
  });
});
