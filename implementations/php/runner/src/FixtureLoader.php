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
        $paths = glob($directory . '/*/*.json');
        if ($paths === false || $paths === []) {
            throw new \RuntimeException(sprintf('Keine Fixtures unter %s', $directory));
        }

        $fixtures = array_map(Fixture::fromFile(...), $paths);
        usort($fixtures, static fn (Fixture $a, Fixture $b): int => strcmp($a->name, $b->name));

        return $fixtures;
    }
}
