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
 * Layer 1 (here): the module/manifest WRAPPER (kind enum, required keys, no stray keys).
 * Deep per-kind validation of each module's `data` ("tief per-kind") is layer 2 — it
 * needs per-kind sub-schemas authored in the knowledge base and is tracked separately.
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

            $isManifest = false;
            if (is_object($doc)) {
                $arr = (array) $doc;
                $isManifest = isset($arr['modules']) && is_array($arr['modules']) && isset($arr['packPolicy']);
            }
            $def = $isManifest ? 'packManifest' : 'module';

            $result = $validator->validate($doc, $base . '#/$defs/' . $def);
            if (!$result->isValid()) {
                $violations[] = substr($file, strlen($packDir) + 1) . ': '
                    . ($result->error()?->message() ?? '?');
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
