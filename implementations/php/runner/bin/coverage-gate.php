<?php

declare(strict_types=1);

/**
 * Coverage floor for the domain core (PHPUnit has none built in). Reads the
 * Clover report, compares the line coverage (clover: statements) against the
 * floor and fails below it. Floor may only rise, never fall.
 *
 * Call: php runner/bin/coverage-gate.php <clover.xml> <floor-percent>
 */

$file = $argv[1] ?? 'coverage.xml';
$floor = (float) ($argv[2] ?? 88);

if (!is_file($file)) {
    fwrite(STDERR, "Clover report not found: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "Clover report unreadable: {$file}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$pct = $statements > 0 ? $covered / $statements * 100 : 100.0;

printf("Core line coverage: %.2f%% (%d/%d), floor %.0f%%\n", $pct, $covered, $statements, $floor);

if ($pct + 0.0001 < $floor) {
    fwrite(STDERR, "Coverage below floor — gate red.\n");
    exit(1);
}
