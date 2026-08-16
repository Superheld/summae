<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Axis 2 (jurisdiction freedom): the law-free core must emit no jurisdiction-specific user-facing
 * TEXT and cite no jurisdiction's statutes. Twin of Node's `no-jurisdiction-text.test.ts` — it
 * existed there and not here, which left the *reference* implementation as the unguarded one.
 *
 * Projection labels come from the pack (the mapping), never as hard-coded German strings in the
 * core — otherwise a German label leaks into every jurisdiction's output (the cash-basis
 * "Vereinnahmte USt" bug). A statute citation is provenance and belongs in the pack docs.
 *
 * **What this guard does not catch, and cannot:** a statute that arrives *translated* rather than
 * quoted. `route !== Pool` was § 6 Abs. 2a EStG with no § in sight, and this test was green while
 * it sat in the core (NF-025). The question that catches those is not mechanical — "would another
 * jurisdiction answer this differently?" — and lives in the root `CLAUDE.md`.
 */
final class NoJurisdictionTextTest extends TestCase
{
    private const array FORBIDDEN = [
        'Vereinnahmte', 'Vorsteuer', 'Umsatzsteuer', 'Kleinunternehmer', 'Finanzamt',
        'Betriebsausgabe', 'Betriebseinnahme', 'Wertabgabe', 'Bewirtung', 'Skonto', 'Erlös',
    ];

    /**
     * Matches "§ 17 UStG", "§ 4 Abs. 3 EStG" and friends — but not a doc-section reference like
     * "determinismus.md § 3", where no statute keyword follows.
     */
    private const string STATUTE = '/§\s*\d+[a-z]?\s*(Abs\.?|Nr\.?|UStG|EStG|HGB|BGB|AO|GewStG|KStG)/u';

    /** @return list<string> */
    private function phpFiles(): array
    {
        $srcDir = dirname(__DIR__, 2) . '/src';
        self::assertDirectoryExists($srcDir);

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        self::assertGreaterThan(50, count($files), 'the scan must not silently find nothing');

        return $files;
    }

    private function relative(string $path): string
    {
        $srcDir = dirname(__DIR__, 2) . '/src/';

        return str_replace($srcDir, '', $path);
    }

    public function testContainsNoGermanJurisdictionLabelTextInSrc(): void
    {
        $violations = [];
        foreach ($this->phpFiles() as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);

            foreach (self::FORBIDDEN as $term) {
                if (str_contains($contents, $term)) {
                    $violations[] = sprintf('%s contains jurisdiction label text "%s"', $this->relative($file), $term);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'the law-free core must not hard-code jurisdiction label text (use the pack/mapping)',
        );
    }

    public function testCitesNoJurisdictionStatutesInSrc(): void
    {
        $violations = [];
        foreach ($this->phpFiles() as $file) {
            $contents = file_get_contents($file);
            self::assertIsString($contents);

            foreach (explode("\n", $contents) as $index => $line) {
                if (preg_match(self::STATUTE, $line) === 1) {
                    $violations[] = sprintf('%s:%d cites a statute', $this->relative($file), $index + 1);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            'the law-free core must not cite jurisdiction statutes (provenance belongs in the pack)',
        );
    }
}
