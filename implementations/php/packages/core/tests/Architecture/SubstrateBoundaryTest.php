<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Substrate boundary (axis 2): the substrate is frozen and sits at the bottom —
 * it must not import anything from the layers above. Mechanical guard,
 * counterpart to the Node side's eslint rule (`core/src/CLAUDE.md`).
 */
final class SubstrateBoundaryTest extends TestCase
{
    public function testSubstrateImportsNothingFromAbove(): void
    {
        $substrateDir = dirname(__DIR__, 2) . '/src/Substrate';
        // Policies = the policy-kinds layer (law/mechanics). Records is deliberately
        // NOT forbidden: data records (e.g. OpenItem in PostResult) are substrate-near.
        $forbidden = [
            'Policies', 'Ledger', 'Composition', 'Partner', 'Port', 'InMemory',
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($substrateDir, \FilesystemIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            foreach ($forbidden as $layer) {
                if (preg_match('/use\s+Summae\\\\Core\\\\' . $layer . '\\\\/', $contents) === 1) {
                    $violations[] = $file->getFilename() . ' imports Summae\\Core\\' . $layer;
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Substrate must not import anything from above:\n" . implode("\n", $violations),
        );
    }

    /**
     * Axis 2 (jurisdiction freedom): the law-free core must emit no jurisdiction-specific
     * user-facing TEXT. Projection labels and the like come from the pack (the mapping),
     * never as hard-coded German strings in the core — otherwise a German label leaks into
     * every jurisdiction's output (the cash-basis "Vereinnahmte USt" bug). This is the
     * regression guard for that class. (Mechanism *names* like `reverse_charge` are a
     * separate, documented closed/open matter — not covered here.)
     */
    public function testCoreEmitsNoHardcodedJurisdictionLabels(): void
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        $forbidden = [
            'Vereinnahmte', 'Vorsteuer', 'Umsatzsteuer', 'Kleinunternehmer', 'Finanzamt',
            'Betriebsausgabe', 'Betriebseinnahme', 'Wertabgabe', 'Bewirtung', 'Skonto', 'Erlös',
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            foreach ($forbidden as $term) {
                if (mb_strpos($contents, $term) !== false) {
                    $violations[] = $file->getFilename() . ' contains jurisdiction label text "' . $term . '"';
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "The law-free core must not hard-code jurisdiction label text (use the pack/mapping):\n"
                . implode("\n", $violations),
        );
    }
}
