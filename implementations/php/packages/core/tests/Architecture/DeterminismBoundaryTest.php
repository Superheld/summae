<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Iron invariant (determinism): same input -> byte-identical result. The core must
 * never reach for the wall clock or a randomness source directly — time and ids enter
 * only through the injected `Clock` / `IdGenerator` (the runner wires `FixedClock` +
 * `DeterministicIdGenerator`). This guard fails loudly if domain/policy code calls a
 * non-deterministic primitive, the class of bug a single fixture cannot express.
 *
 * Allowlisted: the seam files that ARE the real (production) Clock / id source —
 * `SystemClock.php`, `UuidV7IdGenerator.php`, `Uuid.php`. Everything else must go
 * through the injected ports. Counterpart to the Node side's determinism-guard test.
 */
final class DeterminismBoundaryTest extends TestCase
{
    private const ALLOW = ['SystemClock.php', 'UuidV7IdGenerator.php', 'Uuid.php'];

    public function testCoreCallsNoWallClockOrRandomnessOutsidePorts(): void
    {
        // value => human label. Argless DateTime ctors = current time; with-arg ones
        // (parsing a given ISO string) stay allowed. The function calls require `(`
        // directly after the name (PSR-12: no space) so prose like "supply date (...)"
        // in a comment is not a hit. Negative look-behind keeps method calls (`->date(`)
        // and identifiers (`update(`, `runtime(`) out.
        $forbidden = [
            '/\bnew\s+\\\\?DateTimeImmutable\(\s*\)/' => 'new DateTimeImmutable() (wall clock)',
            '/\bnew\s+\\\\?DateTime\(\s*\)/' => 'new DateTime() (wall clock)',
            '/(?<![\w>$])time\(/' => 'time()',
            '/\bmicrotime\(/' => 'microtime()',
            '/\bhrtime\(/' => 'hrtime()',
            '/(?<![\w>$])date\(/' => 'date()',
            '/\bgmdate\(/' => 'gmdate()',
            '/\bmt_rand\(/' => 'mt_rand()',
            '/(?<![\w>$])rand\(/' => 'rand()',
            '/\brandom_int\(/' => 'random_int()',
            '/\brandom_bytes\(/' => 'random_bytes()',
            '/\buniqid\(/' => 'uniqid()',
        ];

        $srcDir = dirname(__DIR__, 2) . '/src';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS),
        );

        $violations = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (in_array($file->getFilename(), self::ALLOW, true)) {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            foreach (explode("\n", $contents) as $number => $line) {
                foreach ($forbidden as $pattern => $label) {
                    if (preg_match($pattern, $line) === 1) {
                        $violations[] = $file->getFilename() . ':' . ($number + 1) . ' uses ' . $label;
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "The core must take time/ids only through the injected Clock/IdGenerator:\n"
                . implode("\n", $violations),
        );
    }
}
