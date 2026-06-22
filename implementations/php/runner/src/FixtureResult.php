<?php

declare(strict_types=1);

namespace Summae\Runner;

final readonly class FixtureResult
{
    /**
     * @param list<string> $diffs
     * @param array<int, mixed> $trace comparison-relevant outputs for the double run
     */
    private function __construct(
        public string $fixture,
        public FixtureStatus $status,
        public array $diffs,
        public array $trace,
    ) {
    }

    /**
     * @param list<string> $diffs
     * @param array<int, mixed> $trace
     */
    public static function of(string $fixture, array $diffs, array $trace): self
    {
        return new self(
            $fixture,
            $diffs === [] ? FixtureStatus::Pass : FixtureStatus::Fail,
            $diffs,
            $trace,
        );
    }

    /** @param array<int, mixed> $trace */
    public static function crash(string $fixture, string $reason, array $trace): self
    {
        return new self($fixture, FixtureStatus::Crash, [$reason], $trace);
    }
}
