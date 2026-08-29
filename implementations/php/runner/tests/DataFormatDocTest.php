<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\FormatVersion;

/**
 * Gate for `knowledge/50-spezifikation/datenformat.md` — the NORMATIVE data-format document.
 *
 * The authority here used to sit upside down (IMPL-037). `format.schema.json`'s `$id` is held
 * against `FORMAT_VERSION` by `FormatVersionTest` and its Node twin, so code and schema cannot
 * drift. The prose both of them are derived *from* was checked by nobody — and so the document
 * described format 0.6 while the product shipped 0.7, for weeks, with the whole gate green. The
 * derived artefacts were guarded; the source was not.
 *
 * This is deliberately NOT a prose check — that is neither achievable nor wanted. Three narrow,
 * mechanical claims:
 *
 *   1. the version in the document's title and in its `$id` line equals `FORMAT_VERSION`;
 *   2. no `## v0.x` section is missing between the oldest documented version and the current one,
 *      so a release cannot skip its own write-up the way 0.7 did;
 *   3. every `$defs` key the schema declares is named in the document.
 *
 * The SAME checks live in the Node data-format-doc.test.ts.
 */
final class DataFormatDocTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function doc(): string
    {
        $path = self::repoRoot() . '/knowledge/50-spezifikation/datenformat.md';
        self::assertFileExists($path, 'the normative data-format document is part of the deliverable');

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        $raw = (string) file_get_contents(self::repoRoot() . '/testing/testsuite/schema/format.schema.json');
        /** @var array<string, mixed> $parsed */
        $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $parsed;
    }

    public function testTheTitleStatesTheCurrentFormatVersion(): void
    {
        $firstLine = strtok(self::doc(), "\n");
        self::assertIsString($firstLine);
        self::assertSame(
            1,
            preg_match('/^# Datenformat-Spezifikation v(\d+\.\d+)/', $firstLine, $matches),
            'the document must open with its own version — got: ' . $firstLine,
        );

        self::assertSame(
            FormatVersion::CURRENT,
            $matches[1] ?? '',
            'the normative document describes a format the product has left behind (IMPL-037)',
        );
    }

    /**
     * The document repeats the schema version in its opening note, in the form
     * "Schema-Datei `$id` → **0.9**". That sentence is what a reader takes away, so it is held
     * against the same source as the title.
     */
    public function testTheIdLineStatesTheCurrentFormatVersion(): void
    {
        self::assertSame(
            1,
            preg_match('/Schema-Datei `\$id` → \*\*(\d+\.\d+)\*\*/u', self::doc(), $matches),
            'the opening note must name the schema version it describes',
        );

        self::assertSame(FormatVersion::CURRENT, $matches[1] ?? '');
    }

    /**
     * A version without a section is a release that skipped its own write-up, which is exactly
     * what happened to 0.7. Minor versions only — the format has never had a major step, and
     * inventing a rule for one that does not exist would be guessing.
     *
     * @return list<int>
     */
    private static function documentedMinors(): array
    {
        preg_match_all('/^## v0\.(\d+)\b/m', self::doc(), $matches);
        $minors = array_map(intval(...), $matches[1]);
        sort($minors);

        return array_values(array_unique($minors));
    }

    public function testTheDocumentIsActuallyParsed(): void
    {
        self::assertGreaterThan(3, count(self::documentedMinors()), 'no version sections found — did the format change?');
    }

    public function testNoVersionBetweenTheOldestAndTheCurrentIsMissing(): void
    {
        $minors = self::documentedMinors();
        [$major, $current] = array_map(intval(...), explode('.', FormatVersion::CURRENT));
        self::assertSame(0, $major, 'this check assumes a 0.x format — a 1.0 needs its own rule, not a silent pass');

        $expected = range($minors[0], $current);
        $missing = array_values(array_diff($expected, $minors));

        self::assertSame(
            [],
            $missing,
            'these format versions have no `## v0.x` section — a release that skipped its own write-up (IMPL-037)',
        );
    }

    public function testTheCurrentVersionHasItsOwnSection(): void
    {
        $current = (int) explode('.', FormatVersion::CURRENT)[1];
        self::assertContains(
            $current,
            self::documentedMinors(),
            sprintf('format %s ships but `## v0.%d` is not written up', FormatVersion::CURRENT, $current),
        );
    }

    /**
     * Every `$defs` key is named somewhere in the document — via the index in
     * § "Wo jeder `$defs`-Schlüssel spezifiziert ist" where the prose calls it something else
     * (`entryLine` is "Position", `manifest` is "Export-Manifest"). An object the schema knows and
     * the specification does not name is the same gap from the other side.
     */
    public function testEverySchemaDefinitionIsNamedInTheDocument(): void
    {
        $doc = self::doc();
        /** @var array<string, mixed> $defs */
        $defs = self::schema()['$defs'] ?? [];
        self::assertNotSame([], $defs, 'no $defs in the schema — did the schema change shape?');

        $unnamed = [];
        foreach (array_keys($defs) as $key) {
            if (!str_contains($doc, (string) $key)) {
                $unnamed[] = (string) $key;
            }
        }

        self::assertSame([], $unnamed, 'the schema declares these and the normative document never names them');
    }
}
