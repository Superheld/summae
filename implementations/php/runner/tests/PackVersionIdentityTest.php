<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Runner\PackLibrary;

/**
 * A published `(id, version)` names exactly one bundle.
 *
 * This is the rule that was missing, not a rule that was broken by accident. Until 2026-08-23 the
 * `de` manifest kept the version `2026.2` while the modules underneath it moved twice and a new
 * one joined, and the old module files were overwritten — so `de@2026.2` named at least three
 * different bundles and nothing anywhere said so. Whoever pinned that version got different books
 * depending on the day they installed.
 *
 * A test cannot see history, so it cannot catch a version that was silently reused last week. What
 * it can catch is the moment the library holds two files claiming the same published identity —
 * which is exactly what happens the first time somebody keeps an old version around (the point of
 * versioning) and edits it instead of adding a new one. `contentDigest` covers the other half at
 * runtime: it is derived, so a bundle that changed cannot present itself as unchanged.
 */
final class PackVersionIdentityTest extends TestCase
{
    public function testEveryPublishedManifestIdentityIsUnique(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (PackLibrary::load()['manifests'] as $manifest) {
            $key = sprintf('%s@%s', self::text($manifest['id'] ?? null), self::text($manifest['version'] ?? null));
            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }
            $seen[$key] = true;
        }

        self::assertSame([], $duplicates, 'Two manifests claim the same published (id, version)');
    }

    public function testEveryPublishedModuleIdentityIsUnique(): void
    {
        $seen = [];
        $duplicates = [];

        foreach (PackLibrary::load()['modules'] as $module) {
            $key = sprintf(
                '%s|%s@%s',
                self::text($module['kind'] ?? null),
                self::text($module['id'] ?? null),
                self::text($module['version'] ?? null),
            );
            if (isset($seen[$key])) {
                $duplicates[] = $key;
            }
            $seen[$key] = true;
        }

        self::assertSame([], $duplicates, 'Two modules claim the same published (kind, id, version)');
    }

    /**
     * Every manifest carries a version at all — an absent one would make the whole rule vacuous,
     * and "highest version wins" would sort an empty string to the bottom without complaining.
     */
    public function testEveryManifestAndModuleCarriesAVersion(): void
    {
        $missing = [];

        foreach (PackLibrary::load()['manifests'] as $manifest) {
            if (self::text($manifest['version'] ?? null) === '') {
                $missing[] = 'manifest ' . self::text($manifest['id'] ?? null);
            }
        }
        foreach (PackLibrary::load()['modules'] as $module) {
            if (self::text($module['version'] ?? null) === '') {
                $missing[] = 'module ' . self::text($module['id'] ?? null);
            }
        }

        self::assertSame([], $missing);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
