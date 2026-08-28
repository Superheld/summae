<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Runner\FixtureLoader;

/**
 * Gate between the fixtures' `covers` field and the requirement lists (IMPL-039).
 *
 * The whole quality gate is defined in terms of the requirements — "every requirement is proven by
 * a test", "a requirement without a test is itself a finding". The requirement lists were held by
 * nobody. For about a year, 21 fixtures cited an `F-PACK-*`/`F-RP-*` family that no requirements
 * file declared, using two area words the root CLAUDE.md did not list; `SF-27` was in the same
 * state from the other side — five fixtures covered it and `validate.py` counted it while
 * `lieferumfang.md` ended at SF-26, so a standard case existed only in the arithmetic.
 *
 * The comparison that makes this a gate rather than a chore: the error catalogue and the exit-code
 * tables are held against each other as SETS, in both directions, in both languages, so half the
 * work fails the build. This does the same for `covers` and the requirement lists.
 *
 * Two exception lists, both with reasons, both guarded in reverse so they cannot quietly become the
 * place where a gap hides.
 *
 * **What this cannot check, and it matters:** that the fixture behind an ID actually PROVES the
 * requirement. `F-KLR-005` is declared, cited by three fixtures, and those three are about
 * production cost — the one case that requirement excludes (IMPL-043). A guard over IDs reads
 * green there. Do not let it read as "every requirement is proven".
 *
 * The SAME checks live in the Node covers-contract.test.ts.
 */
final class CoversContractTest extends TestCase
{
    /**
     * `covers` entries that are not requirement IDs. All of them are Gate-1 resolver drafts that
     * cite error codes, resolver invariants and bare words instead — written before the rule
     * existed. A fixture is append-only, so `covers` cannot be corrected; the list is closed and
     * must not grow.
     *
     * @var array<string, string>
     */
    private const array LEGACY_COVERS = [
        'E_PACK_INCOHERENT' => 'Gate-1 resolver draft: cites the error code instead of F-PACK-RESOLVE',
        'E_PACK_UNRESOLVED_REF' => 'Gate-1 resolver draft: cites the error code instead of F-PACK-RESOLVE',
        'E_POLICY_INVALID' => 'Gate-1 resolver draft: cites the error code instead of F-PACK-RESOLVE',
        'I1' => 'Gate-1 resolver draft: cites resolver invariant I1 (tax code without account)',
        'I2' => 'Gate-1 resolver draft: cites resolver invariant I2 (mapping without accounts)',
        'I3' => 'Gate-1 resolver draft: cites resolver invariant I3 (missing reference)',
        'I4' => 'Gate-1 resolver draft: cites resolver invariant I4 (projection without taxTag)',
        'cycle' => 'Gate-1 resolver draft: bare word for the dependency-cycle invariant',
        'mechanism' => 'Gate-1 resolver draft: bare word for the unknown-mechanism invariant',
        'override' => 'Gate-1 resolver draft: bare word for the colliding-override invariant',
        'packPolicy' => 'Gate-1 resolver draft: bare word for the invalid-policy invariant',
    ];

    /**
     * Declared requirements no live fixture names, each with the reason it is legitimately not
     * fixture-backed. Where the reason is "a fixture proves it under another ID", the fixtures are
     * named — and named fixtures are checked to exist and to still run, so the excuse cannot rot
     * the way a ✅ pointing at a renamed fixture does.
     *
     * @var array<string, array{reason: string, fixtures: list<string>}>
     */
    private const array NOT_FIXTURE_BACKED = [
        'F-AST-007' => [
            'reason' => 'built and behaviourally covered, but the four fixtures that exercise it name '
                . 'F-AST-002/F-AST-005 in `covers`, and a fixture is append-only. Merging the requirement into '
                . 'those two would be wrong on content — declining-balance plans are neither the GWG switch nor '
                . 'the asset register — and a fixture whose only purpose is to carry a string is the wrong artefact',
            'fixtures' => [
                'assets/declining-balance-depreciation',
                'assets/declining-balance-asset-class',
                'assets/special-depreciation',
                'assets/asset-register-special-depreciation',
            ],
        ],
        'F-IO-004' => [
            'reason' => 'cross-language data exchange — proven by `make cross`, which a fixture cannot express',
            'fixtures' => [],
        ],
        'F-IO-008' => [
            'reason' => 'DATEV batch import: not built, deliberately deferred with its blocker named (IMPL-042)',
            'fixtures' => [],
        ],
        'F-IO-010' => [
            'reason' => 'the operation parameter contract — proven by OperationParametersTest / '
                . 'operation-parameters.test.ts, which compare the constants against api-parameters.json. A '
                . 'fixture exercises the dispatcher; it cannot pin that the table equals the file',
            'fixtures' => [],
        ],
        'F-KLR-002' => [
            'reason' => 'Abgrenzungsrechnung: not built. Decided 2026-08-28 to be IN scope and unbuilt (IMPL-041), '
                . 'not descoped — so this entry is a gap on record, not an excuse',
            'fixtures' => [],
        ],
        'NF-4' => [
            'reason' => 'embeddability (no UI, no server, no forced DB) — architectural, guarded by '
                . 'SubstrateBoundaryTest and the eslint no-restricted-imports boundary, not by behaviour',
            'fixtures' => [],
        ],
        'NF-5' => [
            'reason' => 'law-dependent values live in packs, not in the core — guarded by NoJurisdictionTextTest / '
                . 'no-jurisdiction-text.test.ts, which is a property of the source and not of a run',
            'fixtures' => [],
        ],
        'NF-6' => [
            'reason' => 'concurrency — dedicated per-implementation test (NfConcurrencyPerformanceTest / '
                . 'nf-concurrency-performance.test.ts), per the Definition of Green',
            'fixtures' => [],
        ],
        'NF-7' => [
            'reason' => 'performance — same dedicated test; a fixture pins results, not timings',
            'fixtures' => [],
        ],
        'SF-15' => [
            'reason' => 'one data set, several engines — proven by `make cross` in both directions, like F-IO-004',
            'fixtures' => [],
        ],
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return list<string> */
    private static function declaredRequirements(): array
    {
        $root = self::repoRoot() . '/knowledge/30-anforderungen/';
        $ids = [];

        preg_match_all(
            '/^\| (F-[A-Z]+(?:-[A-Z]+)*-[A-Z0-9]+)/m',
            (string) file_get_contents($root . 'funktional.md'),
            $functional,
        );
        preg_match_all('/^## (NF-\d+)/m', (string) file_get_contents($root . 'nicht-funktional.md'), $nonFunctional);
        preg_match_all('/^- \[[ x]\] (SF-\d+)/m', (string) file_get_contents($root . 'lieferumfang.md'), $standardCases);

        foreach ([$functional[1], $nonFunctional[1], $standardCases[1]] as $found) {
            foreach ($found as $id) {
                $ids[] = $id;
            }
        }
        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * `dir/name` => covers, fixtures only (pack module and manifest data files carry no `fixture`
     * key and are not fixtures — `validate.py` skips them for the same reason).
     *
     * @return array<string, list<string>>
     */
    private static function fixtureCovers(): array
    {
        $root = self::repoRoot() . '/testing/testsuite/fixtures';
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $decoded = json_decode((string) file_get_contents((string) $file->getPathname()), true);
            if (!is_array($decoded) || !array_key_exists('fixture', $decoded)) {
                continue;
            }
            $covers = [];
            foreach (is_array($decoded['covers'] ?? null) ? $decoded['covers'] : [] as $c) {
                if (is_string($c)) {
                    $covers[] = $c;
                }
            }
            $found[substr((string) $file->getPathname(), strlen($root) + 1, -5)] = $covers;
        }
        ksort($found);

        return $found;
    }

    /** @return array<string, string> fixture name => successor */
    private static function retired(): array
    {
        return FixtureLoader::superseded(self::repoRoot() . '/testing/testsuite/superseded.json');
    }

    /**
     * Only fixtures the runner still RUNS count as coverage. A retired fixture stays on disk
     * byte-identical by design, so existence is the wrong question here as well.
     *
     * @return list<string>
     */
    private static function coveredRequirements(): array
    {
        $retired = self::retired();
        $covered = [];
        foreach (self::fixtureCovers() as $path => $covers) {
            if (isset($retired[basename($path)])) {
                continue;
            }
            foreach ($covers as $c) {
                $covered[] = $c;
            }
        }
        sort($covered);

        return array_values(array_unique($covered));
    }

    public function testTheListsAreActuallyParsed(): void
    {
        self::assertGreaterThan(100, count(self::declaredRequirements()), 'no requirements found — did a table change shape?');
        self::assertGreaterThan(50, count(self::fixtureCovers()), 'no fixtures found');
    }

    public function testEveryCoversEntryIsADeclaredRequirement(): void
    {
        $declared = self::declaredRequirements();
        $undeclared = [];
        foreach (self::fixtureCovers() as $path => $covers) {
            foreach ($covers as $id) {
                if (!in_array($id, $declared, true) && !array_key_exists($id, self::LEGACY_COVERS)) {
                    $undeclared[] = sprintf('%s covers %s', $path, $id);
                }
            }
        }
        sort($undeclared);

        self::assertSame(
            [],
            $undeclared,
            'these fixtures name something no requirements file declares — declare it or use a declared ID',
        );
    }

    public function testEveryDeclaredRequirementIsCoveredOrExcused(): void
    {
        $covered = self::coveredRequirements();
        $unproven = [];
        foreach (self::declaredRequirements() as $id) {
            if (!in_array($id, $covered, true) && !array_key_exists($id, self::NOT_FIXTURE_BACKED)) {
                $unproven[] = $id;
            }
        }

        self::assertSame(
            [],
            $unproven,
            'these requirements have no live fixture and no recorded reason — "a requirement without a test is '
            . 'itself a finding", so either cover it or write down why a fixture cannot',
        );
    }

    /**
     * The reverse guard on both lists: once a real `covers` entry exists, the excuse must go, or the
     * list quietly turns into the place where coverage hides.
     */
    public function testNoExceptionOutlivesItsReason(): void
    {
        $covered = self::coveredRequirements();
        $stale = [];
        foreach (array_keys(self::NOT_FIXTURE_BACKED) as $id) {
            if (in_array($id, $covered, true)) {
                $stale[] = sprintf('%s is now covered by a fixture — remove the NOT_FIXTURE_BACKED entry', $id);
            }
        }

        $allCovers = [];
        foreach (self::fixtureCovers() as $covers) {
            foreach ($covers as $c) {
                $allCovers[$c] = true;
            }
        }
        foreach (array_keys(self::LEGACY_COVERS) as $id) {
            if (!isset($allCovers[$id])) {
                $stale[] = sprintf('%s is in LEGACY_COVERS but no fixture uses it any more — remove the entry', $id);
            }
        }
        sort($stale);

        self::assertSame([], $stale);
    }

    /**
     * An excuse that names fixtures must name fixtures that exist and still run — the same rule the
     * GoBD census learned the hard way. Otherwise "covered under another ID" ages into a claim
     * nobody can reproduce.
     */
    public function testNamedSubstituteFixturesExistAndRun(): void
    {
        $fixtures = self::fixtureCovers();
        $retired = self::retired();
        $broken = [];

        foreach (self::NOT_FIXTURE_BACKED as $id => $entry) {
            foreach ($entry['fixtures'] as $path) {
                if (!array_key_exists($path, $fixtures)) {
                    $broken[] = sprintf('%s names %s, which does not exist', $id, $path);
                } elseif (isset($retired[basename($path)])) {
                    $broken[] = sprintf('%s names %s, which the runner no longer runs', $id, $path);
                }
            }
        }

        self::assertSame([], $broken);
    }

    /**
     * `validate.py` prints the standard cases from `covers` alone, which is how SF-27 came to exist
     * in the arithmetic and nowhere else. Same set, stated as its own check because that is the
     * shape the finding was found in.
     */
    public function testEveryStandardCaseCountedByValidatePyIsDeclared(): void
    {
        $declared = self::declaredRequirements();
        $counted = array_values(array_filter(
            self::coveredRequirements(),
            static fn (string $id): bool => str_starts_with($id, 'SF-'),
        ));
        $undeclared = array_values(array_diff($counted, $declared));

        self::assertSame([], $undeclared, 'validate.py counts standard cases lieferumfang.md does not declare');
    }
}
