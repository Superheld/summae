<?php

declare(strict_types=1);

namespace Summae\Runner;

use Summae\Core\Substrate\CanonicalJson;
use Summae\Runner\Subject\SubjectFactory;

/**
 * Complete suite run incl. double-run determinism check
 * (runner contract point 4): both runs must yield identical traces
 * after normalization. UUIDs are normalized to order of appearance —
 * fixtures never compare ID values (determinismus.md §5).
 */
final class SuiteRunner
{
    public function __construct(
        private readonly SubjectFactory $subjectFactory,
        private readonly FixtureLoader $loader = new FixtureLoader(),
        private readonly FixtureRunner $fixtureRunner = new FixtureRunner(),
    ) {
    }

    public function run(string $fixturesDirectory, ?string $filter = null): SuiteResult
    {
        $fixtures = $this->loader->discover($fixturesDirectory);

        if ($filter !== null) {
            $fixtures = array_values(array_filter(
                $fixtures,
                static fn (Fixture $fixture): bool => str_contains($fixture->name, $filter),
            ));
        }

        $firstRun = [];
        $secondRun = [];

        foreach ($fixtures as $fixture) {
            $firstRun[] = $this->fixtureRunner->run($fixture, $this->subjectFactory->create());
        }

        foreach ($fixtures as $fixture) {
            $secondRun[] = $this->fixtureRunner->run($fixture, $this->subjectFactory->create());
        }

        $determinismBreaks = [];
        foreach ($firstRun as $index => $result) {
            $first = self::normalizedTrace($result->trace);
            $second = self::normalizedTrace($secondRun[$index]->trace);

            if ($first !== $second) {
                $determinismBreaks[] = $result->fixture;
            }
        }

        return new SuiteResult($firstRun, $determinismBreaks);
    }

    /**
     * Canonical JSON of the trace, UUIDs replaced by appearance index.
     *
     * @param array<int, mixed> $trace
     */
    private static function normalizedTrace(array $trace): string
    {
        $json = CanonicalJson::encode($trace);

        $seen = [];
        $normalized = preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            static function (array $match) use (&$seen): string {
                $seen[$match[0]] ??= sprintf('#uuid%d', count($seen) + 1);

                return $seen[$match[0]];
            },
            $json,
        );

        return $normalized ?? $json;
    }
}
