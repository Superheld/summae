<?php

declare(strict_types=1);

/**
 * Coverage floor per package (PHPUnit has none built in). Reads the Clover report,
 * groups the files by the package they belong to, and compares each package's line
 * coverage (clover: statements) against its own floor.
 *
 * One floor per package, not one shared number: the domain core is held to a level
 * the runner would never reach, and a single average lets a package rot while the
 * total still looks healthy. Floors may only rise, never fall (root CLAUDE.md,
 * "Definition of Green").
 *
 * A package listed here but missing from the report is an error, not a pass — that is
 * what silently dropping a directory from phpunit.xml.dist would look like. A package
 * in the report but not listed here is an error too: new code arrives measured or not
 * at all.
 *
 * Call: php runner/bin/coverage-gate.php <clover.xml>
 */

/**
 * Measured on 2026-08-16 (lines): core 93.41 · cli 89.24 · laravel 99.11 · runner 83.12.
 * Floors sit just below that — close enough to catch a real drop, far enough not to
 * flap on a single refactored line.
 *
 * `packages/laravel` joined on 2026-08-16 (IMPL-015 closed): it has its own suite now, so its
 * number is asserted by tests rather than produced as a side effect of other suites. Its last
 * uncovered piece, `SummaeServiceProvider`, got its test the same day (`ServiceProviderTest`
 * on orchestra/testbench), which took the package from 96.97 to 99.11 — so the floor rose with
 * it, as floors here only ever do.
 */
const FLOORS = [
    'core' => 91.0,
    'cli' => 87.0,
    'laravel' => 98.0,
    'runner' => 82.0,
];

$file = $argv[1] ?? 'coverage.xml';

if (!is_file($file)) {
    fwrite(STDERR, "Clover report not found: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "Clover report unreadable: {$file}\n");
    exit(2);
}

$nodes = $xml->xpath('//file');
if ($nodes === false || $nodes === null || $nodes === []) {
    fwrite(STDERR, "Clover report contains no files: {$file}\n");
    exit(2);
}

/** @var array<string, array{covered: int, total: int}> $measured */
$measured = [];

foreach ($nodes as $node) {
    $name = (string) $node['name'];

    if (preg_match('#/packages/([^/]+)/src/#', $name, $match) === 1) {
        $package = $match[1];
    } elseif (str_contains($name, '/runner/src/')) {
        $package = 'runner';
    } else {
        continue;
    }

    $metrics = $node->metrics;
    if ($metrics === null) {
        continue;
    }

    $measured[$package] ??= ['covered' => 0, 'total' => 0];
    $measured[$package]['covered'] += (int) $metrics['coveredstatements'];
    $measured[$package]['total'] += (int) $metrics['statements'];
}

$red = [];

foreach (FLOORS as $package => $floor) {
    if (!isset($measured[$package])) {
        $red[] = sprintf('%s: not in the coverage report — is it still in phpunit.xml.dist?', $package);
        continue;
    }

    $total = $measured[$package]['total'];
    $covered = $measured[$package]['covered'];
    $pct = $total > 0 ? $covered / $total * 100 : 100.0;

    printf("%-8s line coverage: %6.2f%% (%d/%d), floor %.0f%%\n", $package, $pct, $covered, $total, $floor);

    if ($pct + 0.0001 < $floor) {
        $red[] = sprintf('%s: %.2f%% below floor %.0f%%', $package, $pct, $floor);
    }
}

foreach (array_keys($measured) as $package) {
    if (!isset(FLOORS[$package])) {
        $red[] = sprintf('%s: measured but has no floor — add one to coverage-gate.php', $package);
    }
}

if ($red !== []) {
    fwrite(STDERR, "Coverage gate red:\n  - " . implode("\n  - ", $red) . "\n");
    exit(1);
}
