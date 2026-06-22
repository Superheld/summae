<?php

declare(strict_types=1);

/**
 * Coverage-Floor für den Fachkern (PHPUnit hat keinen eingebauten). Liest den
 * Clover-Report, vergleicht die Zeilen-Coverage (clover: statements) gegen den
 * Floor und scheitert darunter. Floor darf nur steigen, nie fallen.
 *
 * Aufruf: php runner/bin/coverage-gate.php <clover.xml> <floor-prozent>
 */

$file = $argv[1] ?? 'coverage.xml';
$floor = (float) ($argv[2] ?? 88);

if (!is_file($file)) {
    fwrite(STDERR, "Clover-Report nicht gefunden: {$file}\n");
    exit(2);
}

$xml = simplexml_load_file($file);
if ($xml === false || !isset($xml->project->metrics)) {
    fwrite(STDERR, "Clover-Report unlesbar: {$file}\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];
$pct = $statements > 0 ? $covered / $statements * 100 : 100.0;

printf("Kern-Zeilen-Coverage: %.2f%% (%d/%d), Floor %.0f%%\n", $pct, $covered, $statements, $floor);

if ($pct + 0.0001 < $floor) {
    fwrite(STDERR, "Coverage unter Floor — Gate rot.\n");
    exit(1);
}
