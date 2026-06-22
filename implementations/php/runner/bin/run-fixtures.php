<?php

declare(strict_types=1);

use Summae\Runner\FixtureResult;
use Summae\Runner\FixtureStatus;
use Summae\Runner\Subject\CoreSubjectFactory;
use Summae\Runner\Subject\DatabaseSubjectFactory;
use Summae\Runner\SuiteRunner;

require __DIR__ . '/../../vendor/autoload.php';

$implRoot = dirname(__DIR__, 2);   // implementations/php
$root = dirname(__DIR__, 4);       // repo root (shared testsuite/)

/** @var list<string> $argvList */
$argvList = $_SERVER['argv'] ?? [];
$filter = null;
$strict = false;
$subject = 'core';
$expectedFile = $implRoot . '/runner/expected-green.txt';

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
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: run-fixtures.php [--filter=name] [--strict] [--subject=core|database] [--expected=file]\n");
        exit(2);
    }
}

$factory = $subject === 'database' ? new DatabaseSubjectFactory() : new CoreSubjectFactory();
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
        printf("        … %d more deviations\n", count($result->diffs) - 5);
    }
}

$pass = count($suite->withStatus(FixtureStatus::Pass));
$fail = count($suite->withStatus(FixtureStatus::Fail));
$crash = count($suite->withStatus(FixtureStatus::Crash));

printf("\n%d fixtures: %d green, %d red, %d crashes\n", count($suite->results), $pass, $fail, $crash);

if ($suite->determinismBreaks !== []) {
    printf("\033[31mDouble run NOT deterministic: %s\033[0m\n", implode(', ', $suite->determinismBreaks));
} else {
    printf("Double run deterministic.\n");
}

// Exit logic: crashes are always an error. In strict mode (M3) everything
// must be green; otherwise the expected-green list serves as a regression guard.
if ($crash > 0) {
    exit(2);
}

if ($suite->determinismBreaks !== []) {
    exit(1);
}

if ($strict) {
    exit($fail > 0 ? 1 : 0);
}

// Filtered runs are a developer tool — expected-green applies only to the full suite.
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
    printf("\033[31mRegression — expected green, but red: %s\033[0m\n", implode(', ', $regressions));
    exit(1);
}

$unlisted = array_diff($passed, $expected);
if ($unlisted !== [] && $filter === null) {
    printf("Newly green (add to %s): %s\n", basename($expectedFile), implode(', ', $unlisted));
}

exit(0);
