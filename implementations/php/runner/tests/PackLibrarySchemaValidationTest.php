<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Quality-gate obligation 1: every shipped pack-library module + manifest is validated
 * against testing/testsuite/schema/format.schema.json — the same schema SchemaValidationTest
 * validates journalExport streams against, now extended to the pack format (mirrors the
 * Node pack-library-schema test). A field the engine reads but the schema does not
 * declare is a finding (the IMPL-002/SPEC-008 class), not a convenience.
 *
 * Layer 1: the module/manifest WRAPPER (kind enum, required keys, no stray keys).
 * Layer 2 ("tief per-kind"): validate each module's `data` against a per-kind schema. `mapping`,
 * `policy` and `depreciation` are deeply schema'd (`#/$defs/mapping` incl. positions,
 * `#/$defs/packPolicy`, `#/$defs/depreciationData`), so their `data` is validated here. The
 * remaining kinds (accounts/tax/assetAccounts) still need per-kind sub-schemas authored in the
 * knowledge base — tracked separately.
 */
final class PackLibrarySchemaValidationTest extends TestCase
{
    public function testPackLibraryFilesValidateAgainstSchema(): void
    {
        $schemaPath = dirname(__DIR__, 4) . '/testing/testsuite/schema/format.schema.json';
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

        // …and the depreciation payload rejects a pool range that leaves a jurisdiction's answer to
        // the core. Three questions are conditionally required next to poolMax: the period (SPEC-004,
        // poolYears), whether a disposal reduces the pool (IMPL-019, poolReducedOnDisposal), and
        // whether the first year is shortened by the acquisition month (poolProRataInFirstYear).
        // Each one a pack may omit is one a jurisdiction inherits from whoever wrote the core.
        $poolRange = '{"validFrom":"2018-01-01","validTo":null,"immediateMax":"250.00","poolMin":"250.01","poolMax":"1000.00"';
        $withoutYears = json_decode('{"gwgThresholds":[' . $poolRange . ',"poolReducedOnDisposal":false,"poolProRataInFirstYear":false}]}', false, 512, JSON_THROW_ON_ERROR);
        $withoutDisposalRule = json_decode('{"gwgThresholds":[' . $poolRange . ',"poolYears":5,"poolProRataInFirstYear":false}]}', false, 512, JSON_THROW_ON_ERROR);
        $withoutProRataRule = json_decode('{"gwgThresholds":[' . $poolRange . ',"poolYears":5,"poolReducedOnDisposal":false}]}', false, 512, JSON_THROW_ON_ERROR);
        $complete = json_decode('{"gwgThresholds":[' . $poolRange . ',"poolYears":5,"poolReducedOnDisposal":false,"poolProRataInFirstYear":false}]}', false, 512, JSON_THROW_ON_ERROR);
        self::assertFalse(
            $validator->validate($withoutYears, $base . '#/$defs/depreciationData')->isValid(),
            'a pool range without poolYears must be rejected',
        );
        self::assertFalse(
            $validator->validate($withoutDisposalRule, $base . '#/$defs/depreciationData')->isValid(),
            'a pool range without poolReducedOnDisposal must be rejected',
        );
        self::assertFalse(
            $validator->validate($withoutProRataRule, $base . '#/$defs/depreciationData')->isValid(),
            'a pool range without poolProRataInFirstYear must be rejected',
        );
        self::assertTrue(
            $validator->validate($complete, $base . '#/$defs/depreciationData')->isValid(),
            'the same range with all three answers must pass',
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

            // Layer 2: kinds whose data is already deeply schema'd by an existing $def.
            // 'key' => null = the whole `data` object is the payload (depreciation keeps
            // gwgThresholds and usefulLife at the top level); a string key = payload at data.<key>.
            // (accounts/tax/assetAccounts need per-kind sub-schemas authored in the WB.)
            $deepByKind = [
                'mapping' => ['def' => 'mapping', 'key' => 'mapping'],
                'policy' => ['def' => 'packPolicy', 'key' => 'packPolicy'],
                'depreciation' => ['def' => 'depreciationData', 'key' => null],
                'productionCost' => ['def' => 'productionCostData', 'key' => null],
                'constraint' => ['def' => 'constraintData', 'key' => null],
            ];
            $kind = $arr['kind'] ?? null;
            if (is_string($kind) && isset($deepByKind[$kind])) {
                $deep = $deepByKind[$kind];
                $dataObj = $arr['data'] ?? null;
                if ($deep['key'] === null) {
                    $inner = $dataObj;
                } else {
                    $inner = is_object($dataObj) ? (((array) $dataObj)[$deep['key']] ?? null) : null;
                }
                $deepResult = $validator->validate($inner, $base . '#/$defs/' . $deep['def']);
                if (!$deepResult->isValid()) {
                    $where = $deep['key'] === null ? 'data' : 'data.' . $deep['key'];
                    $violations[] = substr($file, strlen($packDir) + 1) . ' (' . $where . '): '
                        . ($deepResult->error()?->message() ?? '?');
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
