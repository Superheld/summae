<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner;

final readonly class SuiteResult
{
    /**
     * @param list<FixtureResult> $results
     * @param list<string> $determinismBreaks Fixture-Namen mit abweichendem Doppellauf
     */
    public function __construct(
        public array $results,
        public array $determinismBreaks,
    ) {
    }

    /** @return list<FixtureResult> */
    public function withStatus(FixtureStatus $status): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (FixtureResult $result): bool => $result->status === $status,
        ));
    }

    /** @return list<string> */
    public function passedNames(): array
    {
        return array_map(
            static fn (FixtureResult $result): string => $result->fixture,
            $this->withStatus(FixtureStatus::Pass),
        );
    }
}
