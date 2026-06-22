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
}
