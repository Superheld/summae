<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Gate for the two language-neutral documents that enumerate the API (IMPL-044):
 *
 *   - `knowledge/50-spezifikation/api.md` — the normative API specification, whose own words are
 *     "Semantik und Namen sind bindend";
 *   - `knowledge/40-domaenenmodell/jurisdiction-profil.md` — the policy-kind census, which claims to
 *     make "everything above the substrate is exactly one of three kinds" provable *by enumeration*.
 *
 * Neither was held against anything until 2026-08-29, and both had drifted far enough to be wrong
 * rather than merely incomplete: 26 of 80 operations and projections missing from the spec, two
 * names it carried that no implementation ever had (`writeDown`, `writeUp`), and a census whose
 * three buckets had no room for the nineteen master-data operations at all.
 *
 * This is the same defect as IMPL-037 one folder over: `api-parameters.json` is held against the
 * dispatcher's constants by the contract tests in both languages, so **code and contract cannot
 * drift** — and the prose both are supposed to be derived from was checked by nobody. The spec even
 * names a guard for its own completeness; that guard is real and holds `systemDescription` against
 * the dispatcher, never this list. A document that names a guard which guards something else is
 * worse than an unguarded one, because a reader stops checking.
 *
 * Deliberately NOT a prose check. One mechanical claim: every operation and projection declared in
 * `testing/testsuite/schema/api-parameters.json` is *named* in both documents. What the documents
 * say about it stays human work — as with the census gates, only the absence is mechanical.
 *
 * The SAME checks live in the Node api-spec-doc.test.ts.
 */
final class ApiSpecDocTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../../../..';

    private const DOCS = [
        'api.md' => '/knowledge/50-spezifikation/api.md',
        'jurisdiction-profil.md' => '/knowledge/40-domaenenmodell/jurisdiction-profil.md',
    ];

    private static function doc(string $relative): string
    {
        $path = self::REPO_ROOT . $relative;
        self::assertFileExists($path, 'the language-neutral API documents are part of the deliverable');

        return (string) file_get_contents($path);
    }

    /**
     * @return array{operations: list<string>, projections: list<string>}
     */
    private static function declared(): array
    {
        $raw = (string) file_get_contents(self::REPO_ROOT . '/testing/testsuite/schema/api-parameters.json');
        /** @var array<string, array<string, mixed>> $parsed */
        $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $operations = array_keys($parsed['operations'] ?? []);
        $projections = array_keys($parsed['projections'] ?? []);
        sort($operations);
        sort($projections);

        return [
            'operations' => array_map(strval(...), $operations),
            'projections' => array_map(strval(...), $projections),
        ];
    }

    /**
     * Inline-code spans, which is where an operation name legitimately appears. A name counts when a
     * span *starts* with it, so `runCosting(period)` and `createFiscalYear {year, start, end}` count
     * and a sentence mentioning the word does not.
     *
     * @return list<string>
     */
    private static function codeSpans(string $doc): array
    {
        preg_match_all('/`([^`]+)`/u', $doc, $matches);

        return $matches[1];
    }

    /**
     * @param list<string> $spans
     */
    private static function names(array $spans, string $name): bool
    {
        foreach ($spans as $span) {
            if ($span === $name) {
                return true;
            }
            foreach ([' ', '(', '{', '/', '.'] as $boundary) {
                if (str_starts_with($span, $name . $boundary)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function testTheContractIsActuallyParsed(): void
    {
        $declared = self::declared();
        self::assertGreaterThan(30, count($declared['operations']), 'no operations declared — did api-parameters.json change shape?');
        self::assertGreaterThan(20, count($declared['projections']), 'no projections declared — did api-parameters.json change shape?');
    }

    public function testTheDocumentsAreActuallyParsed(): void
    {
        foreach (self::DOCS as $label => $relative) {
            self::assertGreaterThan(50, count(self::codeSpans(self::doc($relative))), $label . ': no inline-code spans found — did the format change?');
        }
    }

    public function testEveryDeclaredOperationIsNamedInBothDocuments(): void
    {
        $this->assertNamed('operations');
    }

    public function testEveryDeclaredProjectionIsNamedInBothDocuments(): void
    {
        $this->assertNamed('projections');
    }

    private function assertNamed(string $group): void
    {
        $declared = self::declared()[$group];

        foreach (self::DOCS as $label => $relative) {
            $spans = self::codeSpans(self::doc($relative));
            $missing = array_values(array_filter($declared, static fn (string $name): bool => !self::names($spans, $name)));

            self::assertSame(
                [],
                $missing,
                $label . ' does not name these declared ' . $group . ' — a spec that calls its own list complete has to be held against the contract (IMPL-044)',
            );
        }
    }
}
