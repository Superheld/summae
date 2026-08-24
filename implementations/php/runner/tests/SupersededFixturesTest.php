<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Runner\FixtureLoader;

/**
 * The retirement register is itself a contract surface.
 *
 * `testing/testsuite/` is append-only, and the runner now skips whatever
 * `testing/testsuite/superseded.json` lists — which is precisely the kind of mechanism that turns
 * into a way of making inconvenient fixtures disappear if nothing watches it. So: an entry has to
 * name a fixture that really exists, has to name a successor that really exists and really runs,
 * and a retired fixture may not still be claimed as green. A typo in the register would otherwise
 * silently retire nothing, or silently retire something nobody meant to.
 */
final class SupersededFixturesTest extends TestCase
{
    public function testEveryEntryNamesAnExistingFixtureAndAnExistingSuccessor(): void
    {
        $names = self::fixtureNames();
        $problems = [];

        foreach (self::register() as $fixture => $successor) {
            if (!isset($names[$fixture])) {
                $problems[] = sprintf('superseded fixture "%s" does not exist', $fixture);
            }
            if (!isset($names[$successor])) {
                $problems[] = sprintf('successor "%s" of "%s" does not exist', $successor, $fixture);
            }
        }

        self::assertSame([], $problems);
    }

    /**
     * A successor that nobody runs would leave the retired fixture's ground uncovered — the point
     * of retiring one is that something else took over its job, not that the job went away.
     */
    public function testEverySuccessorIsExpectedGreen(): void
    {
        $expected = array_flip(self::expectedGreen());
        $missing = [];

        foreach (self::register() as $fixture => $successor) {
            if (!isset($expected[$successor])) {
                $missing[] = sprintf('successor "%s" of "%s" is not in expected-green.txt', $successor, $fixture);
            }
        }

        self::assertSame([], $missing);
    }

    public function testNoRetiredFixtureIsStillExpectedGreen(): void
    {
        $register = self::register();
        $stale = array_values(array_filter(
            self::expectedGreen(),
            static fn (string $name): bool => isset($register[$name]),
        ));

        self::assertSame([], $stale, 'expected-green.txt still demands a retired fixture');
    }

    /**
     * The register is shared, so both runners must skip the same set — a fixture retired for PHP
     * and still running for Node would break the one thing the suite exists for.
     */
    public function testTheRunnerReallySkipsThem(): void
    {
        $loaded = [];
        foreach ((new FixtureLoader())->discover(self::fixturesDirectory()) as $fixture) {
            $loaded[$fixture->name] = true;
        }

        foreach (array_keys(self::register()) as $retired) {
            self::assertArrayNotHasKey($retired, $loaded, sprintf('%s is retired but was loaded', $retired));
        }
    }

    /** @return array<string, string> */
    private static function register(): array
    {
        return FixtureLoader::superseded(self::testsuiteDirectory() . '/superseded.json');
    }

    /** @return array<string, true> every fixture name in the suite, retired ones included */
    private static function fixtureNames(): array
    {
        $names = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::fixturesDirectory(), \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $raw = file_get_contents($file->getPathname());
            /** @var array<string, mixed> $data */
            $data = json_decode(is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);
            if (is_string($data['fixture'] ?? null)) {
                $names[$data['fixture']] = true;
            }
        }

        return $names;
    }

    /** @return list<string> */
    private static function expectedGreen(): array
    {
        $lines = file(dirname(__DIR__) . '/expected-green.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $names = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $names[] = $line;
            }
        }

        return $names;
    }

    private static function testsuiteDirectory(): string
    {
        return dirname(__DIR__, 4) . '/testing/testsuite';
    }

    private static function fixturesDirectory(): string
    {
        return self::testsuiteDirectory() . '/fixtures';
    }
}
