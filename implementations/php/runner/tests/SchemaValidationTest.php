<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Summae\Runner\Subject\CoreSubject;

/**
 * JOB-011-Akzeptanz: Exporte validieren gegen das maschinenlesbare
 * Schema (schema/format.schema.json, JSON Schema draft 2020-12) —
 * das Schema ist die prüfbare Ableitung der Datenformat-Spec.
 */
final class SchemaValidationTest extends TestCase
{
    public function testJournalExportStreamsValidateAgainstSchema(): void
    {
        $schemaPath = dirname(__DIR__, 4) . '/testsuite/schema/format.schema.json';
        self::assertFileExists($schemaPath);

        $schemaJson = file_get_contents($schemaPath);
        self::assertIsString($schemaJson);
        /** @var object{'$id': string} $schema */
        $schema = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);

        // Kleinen Mandanten aufbauen und exportieren.
        $subject = new CoreSubject();
        $subject->setup([
            'tenant' => ['name' => 'Schema GmbH', 'baseCurrency' => 'EUR'],
            'accounts' => [
                ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
                ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue'],
            ],
            'fiscalYears' => [['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']],
            'vouchers' => [[
                'id' => '01900000-0000-7000-8000-000000000001',
                'voucherNumber' => 'AR-1',
                'voucherDate' => '2026-01-10',
            ]],
        ]);

        $subject->execute('post', [
            'entryDate' => '2026-01-10',
            'voucherId' => '01900000-0000-7000-8000-000000000001',
            'text' => 'Erlös',
            'lines' => [
                ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
                ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
            ],
        ]);
        $subject->execute('correct', [
            'entryId' => $this->firstEntryId($subject),
            'text' => 'Erlös Januar',
            'actor' => 'bruce',
        ]);

        $export = $subject->project('journalExport', ['fiscalYear' => 2026, 'format' => 'gobd-z3']);

        $validator = new Validator();
        $validator->resolver()?->registerRaw($schema);

        $checks = [
            'journal' => 'journalEntry',
            'accounts' => 'account',
            'vouchers' => 'voucher',
            'auditLog' => 'auditRecord',
        ];

        /** @var array<string, list<mixed>> $data */
        $data = $export['data'];
        /** @var array<string, mixed> $manifest */
        $manifest = $export['manifest'];

        foreach ($checks as $stream => $definition) {
            self::assertArrayHasKey($stream, $data, sprintf('Strom %s fehlt im Export', $stream));

            foreach ($data[$stream] as $index => $row) {
                $decoded = json_decode(json_encode($row, JSON_THROW_ON_ERROR), false);
                $result = $validator->validate($decoded, $schema->{'$id'} . '#/$defs/' . $definition);

                self::assertTrue(
                    $result->isValid(),
                    sprintf(
                        '%s[%d] verletzt Schema-Definition %s: %s',
                        $stream,
                        $index,
                        $definition,
                        json_encode($result->error()?->args() ?? [], JSON_THROW_ON_ERROR) . ' / '
                            . ($result->error()?->message() ?? '?'),
                    ),
                );
            }
        }

        // v0.5/F-005: Schema-Manifest kennt jetzt streams + hashAlgorithm —
        // das volle Manifest validiert.
        $manifestDecoded = json_decode(json_encode($manifest, JSON_THROW_ON_ERROR), false);
        $manifestResult = $validator->validate($manifestDecoded, $schema->{'$id'} . '#/$defs/manifest');
        self::assertTrue(
            $manifestResult->isValid(),
            'Manifest verletzt Schema: ' . ($manifestResult->error()?->message() ?? '?'),
        );
    }

    private function firstEntryId(CoreSubject $subject): string
    {
        $export = $subject->project('journalExport', ['fiscalYear' => 2026]);
        /** @var array<string, list<array<string, mixed>>> $data */
        $data = $export['data'];
        $id = $data['journal'][0]['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }
}
