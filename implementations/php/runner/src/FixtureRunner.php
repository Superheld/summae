<?php

declare(strict_types=1);

namespace Summae\Runner;

use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Runner\Subject\Subject;
use Summae\Runner\Subject\SubjectError;

/**
 * Runs a fixture against a fresh subject (runner contract,
 * testsuite/README.md): setup -> steps -> projections.
 *
 * Domain errors of the subject (SubjectError) are expected results;
 * any other exception is a crash of the implementation.
 */
final class FixtureRunner
{
    public function run(Fixture $fixture, Subject $subject): FixtureResult
    {
        $bag = new PlaceholderBag();
        // Deterministic placeholder IDs: the double run must yield byte-identical
        // traces (stream hashes contain IDs). Own time component,
        // so that subject-internal IDs never collide.
        $ids = new DeterministicIdGenerator(FixedClock::at('2026-06-07T00:00:00+00:00'));
        $freshId = static fn (string $name): string => $ids->next()->value;
        $diffs = [];
        $trace = [];

        try {
            /** @var array<string, mixed> $setup */
            $setup = $bag->resolve($fixture->setup, $freshId);
            $subject->setup($setup);
        } catch (SubjectError $e) {
            return FixtureResult::of($fixture->name, [sprintf('setup: %s (%s)', $e->errorCode, $e->getMessage())], []);
        } catch (\Throwable $e) {
            return FixtureResult::crash($fixture->name, sprintf('setup: %s: %s', $e::class, $e->getMessage()), []);
        }

        foreach ($fixture->steps as $index => $step) {
            $op = $step['op'] ?? null;
            if (!is_string($op)) {
                return FixtureResult::crash($fixture->name, sprintf('steps[%d]: op missing', $index), $trace);
            }

            $label = sprintf('steps[%d] %s', $index, $op);

            try {
                /** @var array<string, mixed> $input */
                $input = $bag->resolve($step['input'] ?? [], $freshId);
                $outcome = ['ok' => true, 'result' => $subject->execute($op, $input)];
            } catch (SubjectError $e) {
                $outcome = ['ok' => false, 'error' => $e->errorCode];
            } catch (\Throwable $e) {
                return FixtureResult::crash(
                    $fixture->name,
                    sprintf('%s: %s: %s', $label, $e::class, $e->getMessage()),
                    $trace,
                );
            }

            $trace[] = ['step' => $op, 'outcome' => $outcome];
            $diffs = [...$diffs, ...$this->checkExpectation($step, $outcome, $bag, $label)];
        }

        foreach ($fixture->projections as $index => $projection) {
            $name = $projection['name'] ?? null;
            if (!is_string($name)) {
                return FixtureResult::crash($fixture->name, sprintf('projections[%d]: name missing', $index), $trace);
            }

            $label = sprintf('projections[%d] %s', $index, $name);

            try {
                /** @var array<string, mixed> $params */
                $params = $bag->resolve($projection['params'] ?? [], $freshId);
                $outcome = ['ok' => true, 'result' => $subject->project($name, $params)];
            } catch (SubjectError $e) {
                $outcome = ['ok' => false, 'error' => $e->errorCode];
            } catch (\Throwable $e) {
                return FixtureResult::crash(
                    $fixture->name,
                    sprintf('%s: %s: %s', $label, $e::class, $e->getMessage()),
                    $trace,
                );
            }

            $trace[] = ['projection' => $name, 'outcome' => $outcome];
            $diffs = [...$diffs, ...$this->checkExpectation($projection, $outcome, $bag, $label)];
        }

        return FixtureResult::of($fixture->name, $diffs, $trace);
    }

    /**
     * Compares a step/projection result against expect.
     * Steps carry expect.result, projections their expect directly;
     * both can carry expect.error (v0.3: projections too).
     *
     * @param array<string, mixed> $definition
     * @param array{ok: bool, result?: mixed, error?: string} $outcome
     *
     * @return list<string>
     */
    private function checkExpectation(array $definition, array $outcome, PlaceholderBag $bag, string $label): array
    {
        $expect = $definition['expect'] ?? [];
        if (!is_array($expect)) {
            return [sprintf('%s: expect has unexpected structure', $label)];
        }

        $expectedError = $expect['error'] ?? null;

        if (is_string($expectedError)) {
            if ($outcome['ok']) {
                return [sprintf('%s: error %s expected, operation succeeded', $label, $expectedError)];
            }

            $actualError = $outcome['error'] ?? '?';

            return $actualError === $expectedError
                ? []
                : [sprintf('%s: error %s expected, is %s', $label, $expectedError, $actualError)];
        }

        if (!$outcome['ok']) {
            return [sprintf('%s: success expected, error %s', $label, $outcome['error'] ?? '?')];
        }

        $expected = array_key_exists('result', $expect)
            ? $expect['result']
            : array_diff_key($expect, ['comment' => true]);

        if ($expected === [] || $expected === null) {
            return [];
        }

        return Comparator::diff($expected, $outcome['result'] ?? null, $bag, $label);
    }
}
