<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Gate for docs/gdpr-conformance.md.
 *
 * The twin of GobdConformanceDocTest, and it exists for the same reason: a compliance document that
 * names its own evidence is worse than no document once the evidence moves. A ✅ pointing at a
 * renamed fixture reads exactly like a ✅ pointing at a real one.
 *
 * It carries one check the GoBD twin does not need. Section 1 of that document is an **inventory of
 * the fields that can hold personal data**, and an inventory is the kind of list that rots quietly:
 * a field renamed in format.schema.json leaves the row standing, still readable, still wrong, and
 * the next person to answer an Art. 30 question copies it. So every record/field pair the inventory
 * names is resolved against the schema, and a row that no longer describes the format turns this red.
 *
 * The document is allowed to be wrong about the law. It is not allowed to be wrong about summae.
 *
 * The SAME checks live in the Node gdpr-conformance-doc.test.ts.
 */
final class GdprConformanceDocTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../..';

    private function doc(): string
    {
        $path = self::REPO_ROOT . '/docs/gdpr-conformance.md';
        self::assertFileExists($path);
        $raw = file_get_contents($path);
        self::assertIsString($raw);

        return $raw;
    }

    /**
     * Fixture basename => suite path. Fixtures are cited by bare name in this document, not by
     * `dir/name`: it argues about rights and mechanisms rather than about suite paths, and a reader
     * looking one up greps for the name.
     *
     * @return array<string, string>
     */
    private function fixturesByName(): array
    {
        $root = self::REPO_ROOT . '/testing/testsuite/fixtures';
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $found[$file->getBasename('.json')] = $file->getPathname();
        }

        return $found;
    }

    /**
     * @param array<string, string> $known
     *
     * @return list<string>
     */
    private function citedFixtures(string $doc, array $known): array
    {
        preg_match_all('/`([a-z0-9]+(?:-[a-z0-9]+)+)`/', $doc, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $token): bool => isset($known[$token]),
        )));
    }

    /**
     * The inventory rows: `| `record` | `field` | …`.
     *
     * @return list<array{record: string, field: string}>
     */
    private function inventoryRows(string $doc): array
    {
        $rows = [];
        foreach (explode("\n", $doc) as $line) {
            if (preg_match('/^\| `([a-zA-Z]+)` \| ([^|]+)\|/', $line, $match) !== 1) {
                continue;
            }
            preg_match_all('/`([a-zA-Z]+)`/', $match[2], $fields);
            foreach ($fields[1] as $field) {
                $rows[] = ['record' => $match[1], 'field' => $field];
            }
        }

        return $rows;
    }

    /**
     * @return array<mixed>
     */
    private function schemaDefs(): array
    {
        $raw = file_get_contents(self::REPO_ROOT . '/testing/testsuite/schema/format.schema.json');
        self::assertIsString($raw);
        $schema = json_decode($raw, true);
        self::assertIsArray($schema);
        self::assertIsArray($schema['$defs']);

        return $schema['$defs'];
    }

    public function testTheDocumentIsActuallyParsed(): void
    {
        $doc = $this->doc();
        self::assertGreaterThan(8, count($this->inventoryRows($doc)), 'no inventory rows — did the §1 table change shape?');
        self::assertGreaterThan(4, count($this->citedFixtures($doc, $this->fixturesByName())));
    }

    public function testEveryInventoryFieldExistsInTheDataFormat(): void
    {
        $defs = $this->schemaDefs();
        $missing = [];

        foreach ($this->inventoryRows($this->doc()) as $row) {
            $record = $defs[$row['record']] ?? null;
            $properties = is_array($record) && isset($record['properties']) && is_array($record['properties'])
                ? $record['properties']
                : [];

            if (!array_key_exists($row['field'], $properties)) {
                $missing[] = $row['record'] . '.' . $row['field'];
            }
        }

        self::assertSame(
            [],
            $missing,
            'the inventory describes fields the format does not have — an Art. 30 answer copied from a stale list',
        );
    }

    public function testEveryCitedFixtureExists(): void
    {
        $known = $this->fixturesByName();
        $missing = array_values(array_filter(
            $this->citedFixtures($this->doc(), $known),
            static fn (string $name): bool => !isset($known[$name]),
        ));

        self::assertSame([], $missing, 'the document points at fixtures that do not exist');
    }

    public function testCitesNoRetiredFixture(): void
    {
        $raw = file_get_contents(self::REPO_ROOT . '/testing/testsuite/superseded.json');
        self::assertIsString($raw);
        $register = json_decode($raw, true);
        self::assertIsArray($register);

        $entries = $register['superseded'] ?? [];
        self::assertIsArray($entries);

        $retired = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['fixture'] ?? null)) {
                // The register names fixtures bare (`de-pack-resolves`); a path form would be
                // legal too. `strrpos` returns false for the bare form, and casting that to int
                // silently chops the first character — so the two cases are separated.
                $name = $entry['fixture'];
                $slash = strrpos($name, '/');
                $retired[$slash === false ? $name : substr($name, $slash + 1)] = true;
            }
        }

        $stale = array_values(array_filter(
            $this->citedFixtures($this->doc(), $this->fixturesByName()),
            static fn (string $name): bool => isset($retired[$name]),
        ));

        self::assertSame([], $stale, 'the document cites a fixture the runner no longer runs');
    }

    public function testEveryCitedRequirementIsCoveredByAFixture(): void
    {
        $covered = [];
        foreach ($this->fixturesByName() as $path) {
            $raw = file_get_contents($path);
            if (!is_string($raw)) {
                continue;
            }
            $fixture = json_decode($raw, true);
            if (!is_array($fixture) || !is_array($fixture['covers'] ?? null)) {
                continue;
            }
            foreach ($fixture['covers'] as $requirement) {
                if (is_string($requirement)) {
                    $covered[$requirement] = true;
                }
            }
        }

        preg_match_all('/\b(F-[A-Z]+-\d{3})\b/', $this->doc(), $matches);
        $unbacked = array_values(array_filter(
            array_unique($matches[1]),
            static fn (string $id): bool => !isset($covered[$id]),
        ));

        self::assertSame([], $unbacked, 'the document cites these requirements as evidence but no fixture covers them');
    }

    public function testEveryStatusMarkerIsOneOfTheThreeDefinedOnes(): void
    {
        $rows = array_filter(
            explode("\n", $this->doc()),
            static fn (string $line): bool => str_starts_with($line, '|') && !str_starts_with($line, '|---'),
        );

        preg_match_all('/[\x{2705}\x{26A0}\x{2796}\x{274C}\x{2753}]/u', implode("\n", $rows), $matches);
        $unexpected = array_values(array_unique(array_filter(
            $matches[0],
            static fn (string $marker): bool => !in_array($marker, ["\u{2705}", "\u{26A0}", "\u{2796}"], true),
        )));

        self::assertSame([], $unexpected, 'a fourth status marker would soften the three-way distinction');
    }
}
