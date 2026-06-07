<?php

declare(strict_types=1);

namespace Rechnungswesen\Runner;

use Rechnungswesen\Core\Shared\DeterministicIdGenerator;
use Rechnungswesen\Core\Shared\FixedClock;
use Rechnungswesen\Runner\Subject\Subject;
use Rechnungswesen\Runner\Subject\SubjectError;

/**
 * Führt eine Fixture gegen ein frisches Subject aus (Runner-Kontrakt,
 * testsuite/README.md): setup -> steps -> projections.
 *
 * Fachliche Fehler des Subjects (SubjectError) sind erwartbare Ergebnisse;
 * jede andere Exception ist ein Crash der Implementierung.
 */
final class FixtureRunner
{
    public function run(Fixture $fixture, Subject $subject): FixtureResult
    {
        $bag = new PlaceholderBag();
        // Deterministische Platzhalter-IDs: der Doppellauf muss byte-identische
        // Spuren liefern (Strom-Hashes enthalten IDs). Eigener Zeitanteil,
        // damit Subject-interne IDs nie kollidieren.
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
                return FixtureResult::crash($fixture->name, sprintf('steps[%d]: op fehlt', $index), $trace);
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
                return FixtureResult::crash($fixture->name, sprintf('projections[%d]: name fehlt', $index), $trace);
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
     * Vergleicht ein Step-/Projektions-Ergebnis gegen expect.
     * Steps tragen expect.result, Projektionen ihr expect direkt;
     * beide können expect.error tragen (v0.3: auch Projektionen).
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
            return [sprintf('%s: expect hat unerwartete Struktur', $label)];
        }

        $expectedError = $expect['error'] ?? null;

        if (is_string($expectedError)) {
            if ($outcome['ok']) {
                return [sprintf('%s: Fehler %s erwartet, Operation war erfolgreich', $label, $expectedError)];
            }

            $actualError = $outcome['error'] ?? '?';

            return $actualError === $expectedError
                ? []
                : [sprintf('%s: Fehler %s erwartet, ist %s', $label, $expectedError, $actualError)];
        }

        if (!$outcome['ok']) {
            return [sprintf('%s: Erfolg erwartet, Fehler %s', $label, $outcome['error'] ?? '?')];
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
