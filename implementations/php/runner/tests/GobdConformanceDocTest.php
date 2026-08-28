<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Core\Policies\Expansion\Tax\TaxBases;
use Summae\Core\Policies\Expansion\Tax\TaxMechanisms;
use Summae\Runner\FixtureLoader;

/**
 * Gate for `docs/gobd-conformance.md`.
 *
 * That document answers an audit question — "is this GoBD-compliant, and where is the
 * proof?" — by naming, per obligation, the fixture or test that fails if the claim stops
 * being true. A document like that is worse than useless once it drifts: a ✅ pointing at a
 * renamed fixture reads exactly like a ✅ pointing at a real one, and nobody notices until
 * an auditor tries to run it.
 *
 * So the claims are checked mechanically. Every fixture path and every requirement ID the
 * document cites must exist, and every requirement it cites must really be covered by a
 * fixture that says so in `covers`. The document is allowed to be wrong about the law; it
 * is not allowed to be wrong about its own evidence.
 *
 * The SAME checks live in the Node gobd-conformance-doc.test.ts.
 */
final class GobdConformanceDocTest extends TestCase
{
    /**
     * Requirements the document cites that no fixture covers — each with the reason it is
     * legitimately not fixture-backed. Anything else must be a real `covers` entry.
     *
     * @var array<string, string>
     */
    private const NOT_FIXTURE_BACKED = [
        'F-IO-004' => 'cross-language data exchange — proven by `make cross`, which a fixture cannot express',
    ];

    private static function repoRoot(): string
    {
        return dirname(__DIR__, 4);
    }

    private static function doc(): string
    {
        $path = self::repoRoot() . '/docs/gobd-conformance.md';
        self::assertFileExists($path, 'the conformance document is part of the deliverable');

        return (string) file_get_contents($path);
    }

    private static function fixtureRoot(): string
    {
        return self::repoRoot() . '/testing/testsuite/fixtures';
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
            $key = substr((string) $file->getPathname(), strlen($root) + 1, -5);
            $decoded = json_decode((string) file_get_contents((string) $file->getPathname()), true);
            $found[$key] = is_array($decoded) ? $decoded : [];
        }
        ksort($found);

        return $found;
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
     * Backticked `dir/name` tokens whose first segment is a real fixture directory. Anchoring
     * on the directory set rather than on the shape matters: the document also contains
     * `brick/math` (a library) and `period/reopened` (an audit objectType/action pair), which
     * a shape-only pattern happily mistakes for fixtures.
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

    /** @return list<string> */
    private static function citedRequirements(string $doc): array
    {
        preg_match_all('/`(F-[A-Z]+-\d{3})`/', $doc, $matches);
        $ids = array_values(array_unique($matches[1]));
        sort($ids);

        return $ids;
    }

    /** @return list<string> */
    private static function coveredRequirements(): array
    {
        $covered = [];
        foreach (self::allFixtures() as $fixture) {
            foreach (is_array($fixture['covers'] ?? null) ? $fixture['covers'] : [] as $c) {
                if (is_string($c)) {
                    $covered[] = $c;
                }
            }
        }

        return array_values(array_unique($covered));
    }

    public function testTheDocumentIsActuallyParsed(): void
    {
        $doc = self::doc();
        self::assertGreaterThan(10, count(self::citedFixtures($doc)), 'no fixture citations found — did the format change?');
        self::assertGreaterThan(10, count(self::citedRequirements($doc)));
    }

    public function testEveryCitedFixtureExists(): void
    {
        $fixtures = self::allFixtures();
        $missing = array_values(array_filter(
            self::citedFixtures(self::doc()),
            static fn (string $name): bool => !array_key_exists($name, $fixtures),
        ));

        self::assertSame([], $missing, 'the conformance document points at fixtures that do not exist — a ✅ nobody can reproduce');
    }

    /**
     * A cited fixture must still RUN, not merely still exist.
     *
     * Retiring a fixture leaves the file in place, byte-identical, by design — which is exactly why
     * existence was the wrong question. Four rows of this document went on citing retired fixtures
     * for a day without anything noticing: three of them lost their evidence when the data format
     * moved to 0.7, one when the capability list stopped being pinned. A ✅ whose proof no longer
     * runs is the failure mode this whole document exists to prevent, one level up.
     */
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

        self::assertSame([], $stale, 'the conformance document cites a fixture the runner no longer runs');
    }

    public function testEveryCitedRequirementIsCoveredOrDeclaredAnException(): void
    {
        $covered = self::coveredRequirements();
        $unbacked = [];
        foreach (self::citedRequirements(self::doc()) as $id) {
            if (!in_array($id, $covered, true) && !array_key_exists($id, self::NOT_FIXTURE_BACKED)) {
                $unbacked[] = $id;
            }
        }

        self::assertSame(
            [],
            $unbacked,
            'the document cites these requirements as evidence but no fixture covers them — '
            .'either add the fixture or record the reason in NOT_FIXTURE_BACKED',
        );
    }

    public function testEveryDeclaredExceptionIsStillAnException(): void
    {
        // The reverse guard: once a fixture DOES cover one of these, the excuse must go, or
        // the list quietly turns into a place where real coverage hides.
        $covered = self::coveredRequirements();
        $stale = [];
        foreach (array_keys(self::NOT_FIXTURE_BACKED) as $id) {
            if (in_array($id, $covered, true)) {
                $stale[] = $id;
            }
        }

        self::assertSame([], $stale, 'these are listed as not fixture-backed but a fixture now covers them — remove the entry');
    }

    public function testStatusMarkersStayThree(): void
    {
        // A fourth symbol would mean the three-way distinction (verified / open / not
        // verifiable) has quietly softened — which is the one thing this document must not do.
        $rows = array_filter(
            explode("\n", self::doc()),
            static fn (string $line): bool => str_starts_with($line, '|') && !str_starts_with($line, '|---'),
        );
        preg_match_all('/[✅⚠➖❌❓]/u', implode("\n", $rows), $matches);

        $unexpected = array_values(array_diff(array_unique($matches[0]), ['✅', '⚠', '➖']));
        self::assertSame([], $unexpected);
    }

    /**
     * §15 of the document is a table of FACTS about the shipped product, and this reads it back.
     *
     * The rows above it are argued in prose on purpose — a compliance census reduced to a
     * machine-readable list would stop being readable by the person who has to defend it. But the
     * facts quoted inside that prose were checked by nobody, and on 2026-08-23 §4 named two tax
     * codes as missing that were built the same day; the row stayed wrong through five green
     * builds. So the facts moved into one table and the table is held against its sources.
     *
     * A row's value is its backtick-quoted tokens; `—` means the source is empty, which is itself
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

        self::fail(sprintf('§15 has no row for the claim "%s"', $claim));
    }

    /**
     * @return list<string>
     */
    private static function packTaxCodes(string $pack, string $manifest): array
    {
        $raw = (string) file_get_contents(self::repoRoot() . '/pack-library/' . $pack . '/' . $manifest);
        /** @var array<string, mixed> $parsed */
        $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $codes = $parsed['taxCodes'] ?? null;
        if (!is_array($codes)) {
            return [];
        }

        $out = [];
        foreach ($codes as $code) {
            $out[] = is_string($code) ? $code : '';
        }

        return $out;
    }

    public function testTaxCodeClaimsMatchTheShippedPacks(): void
    {
        self::assertSame(self::packTaxCodes('de-pack', 'de.json'), self::claimRow('de pack tax codes'));
        self::assertSame(self::packTaxCodes('us-pack', 'us.json'), self::claimRow('us pack tax codes'));
        self::assertSame(self::packTaxCodes('default-pack', 'default.json'), self::claimRow('default pack tax codes'));
    }

    public function testEngineRepertoireClaimsMatchWhatTheCoreRegisters(): void
    {
        self::assertSame(TaxMechanisms::all(), self::claimRow('engine tax mechanisms'));
        self::assertSame(TaxBases::all(), self::claimRow('engine tax base kinds'));
    }

    /**
     * The A-13 row is ✅ only because a SHIPPED pack declares the rule. If the rule changes its
     * accounts, that ✅ describes something else — so the accounts are part of the claim.
     */
    public function testAccountCombinationClaimMatchesTheDePack(): void
    {
        $raw = (string) file_get_contents(
            self::repoRoot() . '/pack-library/de-pack/constraint/de-entgeltminderung.json',
        );
        /** @var array<string, mixed> $parsed */
        $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $data */
        $data = is_array($parsed['data'] ?? null) ? $parsed['data'] : [];
        $rules = is_array($data['accountCombinationRules'] ?? null) ? $data['accountCombinationRules'] : [];

        $actual = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || !is_array($rule['requireAccountIn'] ?? null) || !is_array($rule['whenAccountIn'] ?? null)) {
                continue;
            }
            foreach ([$rule['whenAccountIn'], $rule['requireAccountIn']] as $range) {
                foreach (['from', 'to'] as $edge) {
                    $bound = $range[$edge] ?? null;
                    $actual[] = is_string($bound) ? $bound : '';
                }
            }
        }

        self::assertSame($actual, self::claimRow('de pack account-combination rules'));
    }
}
