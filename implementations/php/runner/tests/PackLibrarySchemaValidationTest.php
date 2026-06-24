<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Quality-gate obligation 1: every shipped pack-library module + manifest is validated
 * against testsuite/schema/format.schema.json — the same schema SchemaValidationTest
 * validates journalExport streams against, now extended to the pack format (mirrors the
 * Node pack-library-schema test). A field the engine reads but the schema does not
 * declare is a finding (the NF-002/F-008 class), not a convenience.
 *
 * Layer 1: the module/manifest WRAPPER (kind enum, required keys, no stray keys).
 * Layer 2 ("tief per-kind"): validate each module's `data` against a per-kind schema. The
 * `mapping` kind is already deeply schema'd (`#/$defs/mapping`, incl. positions/mappingPosition),
 * so its `data.mapping` is validated here. The other kinds (accounts/tax/depreciation/policy/
 * assetAccounts) still need per-kind sub-schemas authored in the knowledge base — tracked separately.
 */
final class PackLibrarySchemaValidationTest extends TestCase
{
    public function testPackLibraryFilesValidateAgainstSchema(): void
    {
        $schemaPath = dirname(__DIR__, 4) . '/testsuite/schema/format.schema.json';
        self::assertFileExists($schemaPath);
        $schemaJson = file_get_contents($schemaPath);
        self::assertIsString($schemaJson);
        /** @var object{'$id': string} $schema */
        $schema = json_decode($schemaJson, false, 512, JSON_THROW_ON_ERROR);

        $validator = new Validator();
        $validator->resolver()?->registerRaw($schema);
        $base = $schema->{'$id'};

        // Guard has teeth: a malformed module is rejected (bad kind, missing required keys).
        $bad = json_decode('{"kind":"not-a-real-kind"}', false, 512, JSON_THROW_ON_ERROR);
        self::assertFalse(
            $validator->validate($bad, $base . '#/$defs/module')->isValid(),
            'validator must reject a bad module',
        );

        $packDir = dirname(__DIR__, 4) . '/pack-library';
        $violations = [];
        foreach ($this->jsonFiles($packDir) as $file) {
            $json = file_get_contents($file);
            if ($json === false) {
                continue;
            }
            $doc = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

            $arr = is_object($doc) ? (array) $doc : [];
            $isManifest = isset($arr['modules']) && is_array($arr['modules']) && isset($arr['packPolicy']);
            $def = $isManifest ? 'packManifest' : 'module';

            $result = $validator->validate($doc, $base . '#/$defs/' . $def);
            if (!$result->isValid()) {
                $violations[] = substr($file, strlen($packDir) + 1) . ': '
                    . ($result->error()?->message() ?? '?');
            }

            // Layer 2 for the mapping kind: its data.mapping is deeply schema'd already.
            if (($arr['kind'] ?? null) === 'mapping') {
                $dataObj = $arr['data'] ?? null;
                $inner = is_object($dataObj) ? (((array) $dataObj)['mapping'] ?? null) : null;
                $mapResult = $validator->validate($inner, $base . '#/$defs/mapping');
                if (!$mapResult->isValid()) {
                    $violations[] = substr($file, strlen($packDir) + 1) . ' (data.mapping): '
                        . ($mapResult->error()?->message() ?? '?');
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "every pack-library module + manifest must validate against the schema:\n" . implode("\n", $violations),
        );
    }

    /**
     * @return list<string>
     */
    private function jsonFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'json') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
