<?php

declare(strict_types=1);

use Rechnungswesen\Runner\FixtureResult;
use Rechnungswesen\Runner\FixtureStatus;
use Rechnungswesen\Runner\Subject\CoreSubjectFactory;
use Rechnungswesen\Runner\Subject\EloquentSubjectFactory;
use Rechnungswesen\Runner\SuiteRunner;

require __DIR__ . '/../../vendor/autoload.php';

$root = dirname(__DIR__, 2);

/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
$filter = null;
$strict = false;
$subject = 'core';
$expectedFile = $root . '/runner/expected-green.txt';

foreach (array_slice($argvList, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, 9);
    } elseif ($arg === '--strict') {
        $strict = true;
    } elseif (str_starts_with($arg, '--subject=')) {
        $subject = substr($arg, 10);
    } elseif (str_starts_with($arg, '--expected=')) {
        $expectedFile = substr($arg, 11);
    } else {
        fwrite(STDERR, "Unbekanntes Argument: {$arg}\n");
        fwrite(STDERR, "Usage: run-fixtures.php [--filter=name] [--strict] [--subject=core|eloquent] [--expected=datei]\n");
        exit(2);
    }
}

$factory = $subject === 'eloquent' ? new EloquentSubjectFactory() : new CoreSubjectFactory();
printf("Subject: %s\n", $subject);

$suite = (new SuiteRunner($factory))->run($root . '/testsuite/fixtures', $filter);

$colors = [
    FixtureStatus::Pass->value => "\033[32m",
    FixtureStatus::Fail->value => "\033[31m",
    FixtureStatus::Crash->value => "\033[41;97m",
];

foreach ($suite->results as $result) {
    printf(
        "%s%-5s\033[0m %s\n",
        $colors[$result->status->value],
        $result->status->value,
        $result->fixture,
    );

    foreach (array_slice($result->diffs, 0, 5) as $diff) {
        printf("        %s\n", $diff);
    }

    if (count($result->diffs) > 5) {
        printf("        … %d weitere Abweichungen\n", count($result->diffs) - 5);
    }
}

$pass = count($suite->withStatus(FixtureStatus::Pass));
$fail = count($suite->withStatus(FixtureStatus::Fail));
$crash = count($suite->withStatus(FixtureStatus::Crash));

printf("\n%d Fixtures: %d grün, %d rot, %d Crashes\n", count($suite->results), $pass, $fail, $crash);

if ($suite->determinismBreaks !== []) {
    printf("\033[31mDoppellauf NICHT deterministisch: %s\033[0m\n", implode(', ', $suite->determinismBreaks));
} else {
    printf("Doppellauf deterministisch.\n");
}

// Exit-Logik: Crashes sind immer ein Fehler. Im strict-Modus (M3) muss
// alles grün sein; sonst gilt die Expected-Green-Liste als Regressions-Schutz.
if ($crash > 0) {
    exit(2);
}

if ($suite->determinismBreaks !== []) {
    exit(1);
}

if ($strict) {
    exit($fail > 0 ? 1 : 0);
}

// Gefilterte Läufe sind Entwickler-Werkzeug — Expected-Green gilt nur für die volle Suite.
if ($filter !== null) {
    exit(0);
}

$expected = [];
if (is_file($expectedFile)) {
    $lines = file($expectedFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines === false ? [] : $lines as $line) {
        $line = trim($line);
        if ($line !== '' && !str_starts_with($line, '#')) {
            $expected[] = $line;
        }
    }
}

$passed = $suite->passedNames();
$regressions = array_diff($expected, $passed);

if ($regressions !== []) {
    printf("\033[31mRegression — erwartet grün, aber rot: %s\033[0m\n", implode(', ', $regressions));
    exit(1);
}

$unlisted = array_diff($passed, $expected);
if ($unlisted !== [] && $filter === null) {
    printf("Neu grün (in %s aufnehmen): %s\n", basename($expectedFile), implode(', ', $unlisted));
}

exit(0);
