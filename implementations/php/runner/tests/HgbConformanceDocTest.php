<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Core\Substrate\AccountSubtype;
use Summae\Runner\FixtureLoader;

/**
 * Gate for `docs/hgb-conformance.md`.
 *
 * The third census, and the first one whose facts are mostly **absences**. Its two siblings claim
 * that something is in place and name the fixture that proves it; this one mostly claims that
 * something is *missing* — no stock, no provisions, no write-up. That inverts what the gate is for.
 *
 * A claim of presence rots when the evidence is renamed. A claim of absence rots the other way, and
 * worse: somebody builds the missing thing, nobody opens the census, and a ⚠️ row goes on describing
 * a hole that was filled months ago. The document then understates the product, which is the exact
 * mirror of the GoBD census's VAT row understating it for five green days — and it is harder to
 * notice, because nothing breaks and the software only looks worse than it is.
 *
 * So §8 of that document is a table of facts and this test reads it back against the real sources.
 * Building any of the named operations, adding the `inventory` subtype, giving the German balance
 * sheet its stock position — each one turns this red until the census is opened and the row is moved
 * with its evidence named. The gate does not merely notice progress; it refuses to let progress go
 * unrecorded.
 *
 * The SAME checks live in the Node hgb-conformance-doc.test.ts.
 */
final class HgbConformanceDocTest extends TestCase
{
    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function doc(): string
    {
        $path = self::repoRoot() . '/docs/hgb-conformance.md';
        self::assertFileExists($path, 'the conformance document is part of the deliverable');

        return (string) file_get_contents($path);
    }

    private static function fixtureRoot(): string
    {
        return self::repoRoot() . '/testing/testsuite/fixtures';
    }

    /** @return list<string> */
    private static function fixtureDirNames(): array
    {
        $dirs = [];
        foreach (scandir(self::fixtureRoot()) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_dir(self::fixtureRoot() . '/' . $name)) {
                $dirs[] = $name;
            }
        }

        return $dirs;
    }

    /**
     * Backticked `dir/name` tokens whose first segment is a real fixture directory — the same
     * anchoring the GoBD gate uses, and for the same reason: this document also contains
     * `pack-library/de-pack/...` paths and `docs/handbuch`, which a shape-only pattern would
     * happily mistake for fixtures.
     *
     * @return list<string>
     */
    private static function citedFixtures(string $doc): array
    {
        preg_match_all('/`([a-z0-9-]+(?:\/[a-z0-9-]+)+)`/', $doc, $matches);
        $dirs = self::fixtureDirNames();
        $cited = [];
        foreach (array_unique($matches[1]) as $token) {
            $first = substr($token, 0, (int) strpos($token, '/'));
            if (in_array($first, $dirs, true)) {
                $cited[] = $token;
            }
        }
        sort($cited);

        return $cited;
    }

    /** @return array<string, array<mixed>> keyed by `dir/name` */
    private static function allFixtures(): array
    {
        $root = self::fixtureRoot();
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $found[substr((string) $file->getPathname(), strlen($root) + 1, -5)] = [];
        }

        return $found;
    }

    public function testTheDocumentIsActuallyParsed(): void
    {
        self::assertGreaterThan(
            10,
            count(self::citedFixtures(self::doc())),
            'no fixture citations found — did the format change?',
        );
    }

    public function testEveryCitedFixtureExists(): void
    {
        $fixtures = self::allFixtures();
        $missing = array_values(array_filter(
            self::citedFixtures(self::doc()),
            static fn (string $name): bool => !array_key_exists($name, $fixtures),
        ));

        self::assertSame([], $missing, 'the census points at fixtures that do not exist — a ✅ nobody can reproduce');
    }

    public function testNoCitedFixtureIsRetired(): void
    {
        $retired = FixtureLoader::superseded(self::repoRoot() . '/testing/testsuite/superseded.json');
        $stale = [];
        foreach (self::citedFixtures(self::doc()) as $path) {
            $name = basename($path);
            if (isset($retired[$name])) {
                $stale[] = sprintf('%s is retired (superseded by %s)', $path, $retired[$name]);
            }
        }

        self::assertSame([], $stale, 'the census cites a fixture the runner no longer runs');
    }

    public function testStatusMarkersStayFour(): void
    {
        // Four, not three: this census needs 🟡 for the shape most of its findings have — the chart
        // carries the right account and nothing carries the rule. A FIFTH symbol would mean the
        // distinction has softened again, which is the one thing a census must not do.
        $rows = array_filter(
            explode("\n", self::doc()),
            static fn (string $line): bool => str_starts_with($line, '|') && !str_starts_with($line, '|---'),
        );
        preg_match_all('/[\x{2705}\x{26A0}\x{2796}\x{274C}\x{2753}\x{1F7E1}\x{1F7E0}\x{1F534}\x{1F7E2}]/u', implode("\n", $rows), $matches);

        $unexpected = array_values(array_diff(
            array_unique($matches[0]),
            ["\u{2705}", "\u{26A0}", "\u{2796}", "\u{1F7E1}"],
        ));
        self::assertSame([], $unexpected);
    }

    /**
     * A §8 row's value is its backtick-quoted tokens; `—` means the source is empty, which is itself
     * an assertion.
     *
     * @return list<string>
     */
    private static function claimRow(string $claim): array
    {
        foreach (explode("\n", self::doc()) as $line) {
            if (!str_starts_with($line, '|')) {
                continue;
            }
            $cells = explode('|', $line);
            if (count($cells) < 5) {
                continue;
            }
            if (trim(str_replace('`', '', $cells[1])) !== $claim) {
                continue;
            }
            if (trim($cells[3]) === '—') {
                return [];
            }
            preg_match_all('/`([^`]+)`/u', $cells[3], $matches);

            return $matches[1];
        }

        self::fail(sprintf('§8 has no row for the claim "%s"', $claim));
    }

    /** @return array<string, mixed> */
    private static function json(string $relative): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = json_decode(
            (string) file_get_contents(self::repoRoot() . '/' . $relative),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $parsed;
    }

    public function testSubtypeRepertoireClaimMatchesTheEngine(): void
    {
        self::assertSame(
            AccountSubtype::all(),
            self::claimRow('engine account subtypes'),
            'the repertoire moved — §§ 1 and 3 of the census argue from the absence of an inventory subtype',
        );
    }

    /**
     * The two rows that make this gate what it is: the census names the operations and projections
     * summae does **not** have, and building one of them must break this.
     */
    public function testTheAbsentOperationsAreStillAbsent(): void
    {
        $declared = self::json('testing/testsuite/schema/api-parameters.json');
        /** @var array<string, mixed> $operations */
        $operations = is_array($declared['operations'] ?? null) ? $declared['operations'] : [];

        $built = array_values(array_filter(
            self::claimRow('operations the engine does not have'),
            static fn (string $name): bool => array_key_exists($name, $operations),
        ));

        self::assertSame(
            [],
            $built,
            'these are built and the census still calls them missing — open docs/hgb-conformance.md, '
            . 'move the row to ✅ with its fixture named, and take the name out of §8',
        );
    }

    public function testTheAbsentProjectionsAreStillAbsent(): void
    {
        $declared = self::json('testing/testsuite/schema/api-parameters.json');
        /** @var array<string, mixed> $projections */
        $projections = is_array($declared['projections'] ?? null) ? $declared['projections'] : [];

        $built = array_values(array_filter(
            self::claimRow('projections the engine does not have'),
            static fn (string $name): bool => array_key_exists($name, $projections),
        ));

        self::assertSame(
            [],
            $built,
            'these are built and the census still calls them missing — open docs/hgb-conformance.md, '
            . 'move the row to ✅ with its fixture named, and take the name out of §8',
        );
    }

    /**
     * @param array<string, mixed> $module
     *
     * @return list<string>
     */
    private static function positionKeys(array $module, ?string $side): array
    {
        /** @var array<string, mixed> $data */
        $data = is_array($module['data'] ?? null) ? $module['data'] : [];
        /** @var array<string, mixed> $mapping */
        $mapping = is_array($data['mapping'] ?? null) ? $data['mapping'] : [];
        $positions = is_array($mapping['positions'] ?? null) ? $mapping['positions'] : [];

        $keys = [];
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }
            if ($side === null) {
                $keys[] = is_string($position['key'] ?? null) ? $position['key'] : '';
                continue;
            }
            if (($position['side'] ?? null) !== $side) {
                continue;
            }
            foreach (is_array($position['children'] ?? null) ? $position['children'] : [] as $child) {
                if (is_array($child) && is_string($child['key'] ?? null)) {
                    $keys[] = $child['key'];
                }
            }
        }

        return $keys;
    }

    public function testBalanceSheetPositionClaimsMatchTheDePack(): void
    {
        $mapping = self::json('pack-library/de-pack/mappings/de-bilanz.json');

        self::assertSame(
            self::positionKeys($mapping, 'assets'),
            self::claimRow('de balance sheet, asset positions'),
            'the German balance sheet changed shape — § 266 rows in §§ 3 and 5 argue from this list',
        );
        self::assertSame(
            self::positionKeys($mapping, 'liabilitiesAndEquity'),
            self::claimRow('de balance sheet, liability positions'),
        );
    }

    public function testIncomeStatementPositionClaimMatchesTheDePack(): void
    {
        self::assertSame(
            self::positionKeys(self::json('pack-library/de-pack/mappings/de-guv.json'), null),
            self::claimRow('de income statement positions'),
            'the § 275 Abs. 2 row claims exactly six positions and names what is missing from them',
        );
    }

    public function testChartSubtypeClaimMatchesTheDePack(): void
    {
        $chart = self::json('pack-library/de-pack/accounts/de-konten.json');
        /** @var array<string, mixed> $data */
        $data = is_array($chart['data'] ?? null) ? $chart['data'] : [];
        $accounts = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];

        $used = [];
        foreach ($accounts as $account) {
            if (is_array($account) && is_string($account['subtype'] ?? null)) {
                $used[$account['subtype']] = true;
            }
        }
        $used = array_keys($used);
        sort($used);

        self::assertSame($used, self::claimRow('de chart, subtypes actually used'));
    }

    public function testModuleKindClaimMatchesTheDePack(): void
    {
        $manifest = self::json('pack-library/de-pack/de.json');
        $modules = is_array($manifest['modules'] ?? null) ? $manifest['modules'] : [];

        $kinds = [];
        foreach ($modules as $module) {
            if (is_array($module) && is_string($module['kind'] ?? null)) {
                $kinds[$module['kind']] = true;
            }
        }
        $kinds = array_keys($kinds);
        sort($kinds);

        self::assertSame(
            $kinds,
            self::claimRow('de pack, module kinds'),
            'the German pack gained or lost a module kind — the census reads this list for what it does not contain',
        );
    }
}
