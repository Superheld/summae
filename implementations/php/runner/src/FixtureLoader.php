<?php

declare(strict_types=1);

namespace Summae\Runner;

final class FixtureLoader
{
    /**
     * Fixtures a later fixture replaced (`testing/testsuite/superseded.json`).
     *
     * The suite is append-only, so a fixture whose expectation turned out never to have been a
     * contract is retired rather than edited: the file stays byte-identical, the registry names
     * the successor, and the runner skips it. Kept out of the fixture files themselves so
     * append-only means what it says — the file is not touched at all.
     *
     * @return array<string, string> fixture name → name of the successor
     */
    public static function superseded(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        /** @var array<string, mixed> $data */
        $data = json_decode(is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);

        $out = [];
        foreach (is_array($data['superseded'] ?? null) ? $data['superseded'] : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $name = $entry['fixture'] ?? null;
            $by = $entry['supersededBy'] ?? null;
            if (is_string($name) && is_string($by)) {
                $out[$name] = $by;
            }
        }

        return $out;
    }

    /**
     * @return list<Fixture> sorted by fixture name (deterministic)
     */
    public function discover(string $directory): array
    {
        $superseded = self::superseded(dirname($directory) . '/superseded.json');

        // Recursive (also pack/<group>/<name>.json); files without a "fixture" key
        // (module/pack data) are skipped. Sorting by name follows.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        $fixtures = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $fixture = Fixture::tryFromFile($file->getPathname());
            if ($fixture !== null && !isset($superseded[$fixture->name])) {
                $fixtures[] = $fixture;
            }
        }

        if ($fixtures === []) {
            throw new \RuntimeException(sprintf('No fixtures under %s', $directory));
        }

        usort($fixtures, static fn (Fixture $a, Fixture $b): int => strcmp($a->name, $b->name));

        return $fixtures;
    }
}
