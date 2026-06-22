<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Runner\Subject\CoreSubjectFactory;
use Summae\Runner\SuiteRunner;

/**
 * Volle Konformitäts-Suite unter PHPUnit (Pendant zu Node `conformance.test.ts`):
 * jede erwartet-grüne Fixture muss grün sein, der Doppellauf deterministisch. Damit
 * zählt die Konformität in die Coverage-Messung (sonst nur Unit-Tests). Die
 * autoritative Gate-Form (strict-Doppellauf + Reporting) bleibt `make fixtures`.
 */
final class ConformanceTest extends TestCase
{
    public function testExpectedGreenFixturesArePassing(): void
    {
        $implRoot = dirname(__DIR__, 2);   // implementations/php
        $repoRoot = dirname(__DIR__, 4);   // Repo-Root (geteilte testsuite/)

        $suite = (new SuiteRunner(new CoreSubjectFactory()))
            ->run($repoRoot . '/testsuite/fixtures', null);

        self::assertSame([], $suite->determinismBreaks, 'Doppellauf nicht deterministisch');

        $expected = $this->expectedGreen($implRoot . '/runner/expected-green.txt');
        $regressions = array_values(array_diff($expected, $suite->passedNames()));

        self::assertSame([], $regressions, 'Erwartet grün, aber rot');
    }

    /** @return list<string> */
    private function expectedGreen(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $expected = [];
        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#')) {
                $expected[] = $line;
            }
        }

        return $expected;
    }
}
