<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Cli\CliPackage;
use Summae\Core\CorePackage;

/**
 * Drift guard for every version string a release bumps (IMPL-035).
 *
 * `summae --version` answered 0.1.0-dev through fifteen releases, and its Node twin answered
 * 0.1.0 — two different lies about the same build, which is the equivalence policy broken on the
 * one surface a user reads first. Nothing compared the constant to anything, because a version
 * number is not behaviour: no fixture exercises it, the suite stayed green, and the number was
 * wrong from the release after the one that wrote it.
 *
 * The anchor is CHANGELOG.md, and deliberately so. Dating a section (`## X.Y.Z — YYYY-MM-DD`) is
 * what *makes* a release — release-notes.yml refuses to publish without it — so the guard fires in
 * the release commit itself, the moment the bumps are due. An undated `unreleased` section does not
 * move the anchor: between releases the CLI keeps naming the last version that shipped.
 *
 * Its Node twin (`release-version.test.ts`) asserts the same constant against the same file, so the
 * two languages cannot answer `--version` differently again.
 */
final class ReleaseVersionTest extends TestCase
{
    /**
     * The newest *dated* section heading. 0.13.1 carries a parenthetical after the date
     * ("never tagged on its own"), so the date anchors the match and the rest of the line is free.
     */
    private function releasedVersion(): string
    {
        $path = __DIR__ . '/../../../../../CHANGELOG.md';
        self::assertFileExists($path, 'the changelog is the anchor for every released version');

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $found = preg_match('/^## (\d+\.\d+\.\d+) — \d{4}-\d{2}-\d{2}/m', $raw, $matches);
        self::assertSame(1, $found, 'the changelog must carry at least one dated release heading');

        return $matches[1];
    }

    public function testTheCliNamesTheVersionThatWasLastReleased(): void
    {
        self::assertSame($this->releasedVersion(), CliPackage::VERSION);
    }

    /**
     * Nothing prints `CorePackage::VERSION` — it is a package marker from JOB-000, and until this
     * test its only reader was the smoke test that froze it. It is still the declared version of a
     * published package, so it is held to the same anchor rather than left as the stale twin of the
     * constant next to it.
     */
    public function testTheCorePackageMarkerCarriesTheSameVersion(): void
    {
        self::assertSame($this->releasedVersion(), CorePackage::VERSION);
    }

    /**
     * Composer derives the released version from the tag, but never `extra.branch-alias.dev-main`.
     * It has to be bumped by hand in all three, it was missed at 0.8.0, and RELEASING.md records
     * that "nothing catches it" — because it changes only how `dev-main` resolves for somebody
     * tracking the branch, never a released tag and never a test. This is that test.
     */
    public function testEveryComposerPackageAliasesTheReleasedMinor(): void
    {
        [$major, $minor] = explode('.', $this->releasedVersion());
        $expected = sprintf('%s.%s.x-dev', $major, $minor);

        foreach (['core', 'laravel', 'cli'] as $package) {
            $path = __DIR__ . '/../../' . $package . '/composer.json';
            $raw = file_get_contents($path);
            self::assertIsString($raw);

            $manifest = json_decode($raw, true);
            self::assertIsArray($manifest);
            self::assertIsArray($manifest['extra']);
            self::assertIsArray($manifest['extra']['branch-alias']);

            self::assertSame(
                $expected,
                $manifest['extra']['branch-alias']['dev-main'],
                sprintf('superheld/summae-%s branch-alias', $package),
            );
        }
    }
}
