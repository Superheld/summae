import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020, { type ValidateFunction } from 'ajv/dist/2020.js';
import { describe, expect, it } from 'vitest';
import { CoreSubject } from '../src/subject/core-subject.js';

/**
 * Cross-language equivalence (top quality policy): the PHP runner has validated journalExport
 * streams against testsuite/schema/format.schema.json since JOB-011 (SchemaValidationTest); the
 * Node runner had no schema validation at all. This is the mirror — the same export streams +
 * manifest, validated against the same schema definitions. journalExport is byte-identical across
 * the language boundary (SF-15), so what validates in PHP must validate here; this guard keeps it so.
 */
const here = dirname(fileURLToPath(import.meta.url));
const schemaPath = join(here, '..', '..', '..', '..', 'testsuite', 'schema', 'format.schema.json');

function rows(data: unknown, stream: string): Array<Record<string, unknown>> {
  if (data === null || typeof data !== 'object') return [];
  const value = (data as Record<string, unknown>)[stream];
  return Array.isArray(value) ? (value as Array<Record<string, unknown>>) : [];
}

describe('journalExport streams validate against format.schema.json', () => {
  it('every exported stream row + the manifest conforms to its schema definition', () => {
    const schema = JSON.parse(readFileSync(schemaPath, 'utf8')) as { $id: string };
    const ajv = new Ajv2020({ strict: false });
    ajv.addSchema(schema);
    const validatorFor = (def: string): ValidateFunction =>
      ajv.getSchema(`${schema.$id}#/$defs/${def}`) as ValidateFunction;

    const subject = new CoreSubject();
    subject.setup({
      tenant: { name: 'Schema GmbH', baseCurrency: 'EUR' },
      accounts: [
        { number: '1200', name: 'Bank', type: 'asset', subtype: 'bank' },
        { number: '8400', name: 'Revenue', type: 'revenue' },
      ],
      fiscalYears: [{ year: 2026, start: '2026-01-01', end: '2026-12-31' }],
      vouchers: [{ id: '01900000-0000-7000-8000-000000000001', voucherNumber: 'AR-1', voucherDate: '2026-01-10' }],
    });
    subject.execute('post', {
      entryDate: '2026-01-10',
      voucherId: '01900000-0000-7000-8000-000000000001',
      text: 'Revenue',
      lines: [
        { account: '1200', side: 'debit', money: { amount: '100.00', currency: 'EUR' } },
        { account: '8400', side: 'credit', money: { amount: '100.00', currency: 'EUR' } },
      ],
    });

    // A correction produces an audit record, so the auditLog stream is non-empty.
    const firstId = rows(subject.project('journalExport', { fiscalYear: 2026 }).data, 'journal')[0]?.id;
    if (typeof firstId !== 'string') throw new Error('no journal entry to correct');
    subject.execute('correct', { entryId: firstId, text: 'Revenue January', actor: 'bruce' });

    const exported = subject.project('journalExport', { fiscalYear: 2026, format: 'gobd-z3' });

    const checks: Record<string, string> = {
      journal: 'journalEntry',
      accounts: 'account',
      vouchers: 'voucher',
      auditLog: 'auditRecord',
    };
    const violations: string[] = [];
    for (const [stream, def] of Object.entries(checks)) {
      const validate = validatorFor(def);
      rows(exported.data, stream).forEach((row, index) => {
        if (!validate(row)) {
          violations.push(`${stream}[${index}] (${def}): ${ajv.errorsText(validate.errors)}`);
        }
      });
    }

    const validateManifest = validatorFor('manifest');
    if (!validateManifest(exported.manifest)) {
      violations.push(`manifest: ${ajv.errorsText(validateManifest.errors)}`);
    }

    expect(violations, 'exported streams + manifest must validate against the schema').toEqual([]);
  });
});
