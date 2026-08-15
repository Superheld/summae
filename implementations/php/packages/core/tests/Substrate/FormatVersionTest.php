<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Substrate;

use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\FormatVersion;

/**
 * The export manifest must state the current spec version — datenformat.md says so in as many
 * words. It said 0.4 for two spec releases while the schema, the pack modules and the parameter
 * contract were on 0.6, and nothing noticed, because a version number that is merely wrong still
 * looks like a version number.
 *
 * The schema's `$id` carries the authoritative version, so that is what this compares against.
 * The SAME check lives in the Node format-version.test.ts.
 */
final class FormatVersionTest extends TestCase
{
    public function testMatchesTheVersionInTheSchemaId(): void
    {
        $path = __DIR__ . '/../../../../../../testing/testsuite/schema/format.schema.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw, 'schema not readable: ' . $path);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['$id'] ?? null);

        $matched = preg_match('#/format/([0-9]+\.[0-9]+)/#', $decoded['$id'], $m);
        self::assertSame(1, $matched, 'could not read a version out of ' . $decoded['$id']);
        self::assertArrayHasKey(1, $m);
        self::assertSame($m[1], FormatVersion::CURRENT);
    }
}
