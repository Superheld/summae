<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Substrat-Grenze (Achse 2): das Substrat ist eingefroren und liegt zuunterst —
 * es darf nichts von den Schichten darüber importieren. Mechanischer Riegel,
 * Pendant zur eslint-Regel der Node-Seite (`core/src/CLAUDE.md`).
 */
final class SubstrateBoundaryTest extends TestCase
{
    public function testSubstrateImportsNothingFromAbove(): void
    {
        $substrateDir = dirname(__DIR__, 2) . '/src/Substrate';
        $forbidden = [
            'Ledger', 'Tax', 'Assets', 'Costing', 'Projection',
            'Mapping', 'Composition', 'Partner', 'Port', 'InMemory',
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
                    $violations[] = $file->getFilename() . ' importiert Summae\\Core\\' . $layer;
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Substrat darf nichts von oben importieren:\n" . implode("\n", $violations),
        );
    }
}
