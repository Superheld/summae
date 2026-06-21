<?php

declare(strict_types=1);

namespace Summae\Runner;

final class FixtureLoader
{
    /**
     * @return list<Fixture> sortiert nach Fixture-Name (deterministisch)
     */
    public function discover(string $directory): array
    {
        // Rekursiv (auch pack/<gruppe>/<name>.json); Dateien ohne "fixture"-Schlüssel
        // (Modul-/Pack-Daten) werden übersprungen. Sortierung nach Name folgt.
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
            throw new \RuntimeException(sprintf('Keine Fixtures unter %s', $directory));
        }

        usort($fixtures, static fn (Fixture $a, Fixture $b): int => strcmp($a->name, $b->name));

        return $fixtures;
    }
}
