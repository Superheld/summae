<?php

declare(strict_types=1);

namespace Summae\Runner;

final class FixtureLoader
{
    /**
     * @return list<Fixture> sorted by fixture name (deterministic)
     */
    public function discover(string $directory): array
    {
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
            if ($fixture !== null) {
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
