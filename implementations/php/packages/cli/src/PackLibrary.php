<?php

declare(strict_types=1);

namespace Summae\Cli;

use Summae\Core\Composition\PackResolver;

/**
 * Loads the shipped pack library for the CLI and resolves a pack to the
 * `rules` structure that workspace/init consume. This way the CLI selects a
 * pack instead of maintaining rules inline in `summae.json`. Counterpart to
 * Node `packages/cli/src/pack-library.ts`.
 */
final class PackLibrary
{
    /** Default home of the library (repo root; overridable via --pack-library). */
    public static function defaultDir(): string
    {
        return dirname(__DIR__, 5) . '/pack-library';
    }

    /**
     * Resolve pack `<id>` → CLI `rules` structure (ruleModules + accounts + taxCodes + taxProfile).
     *
     * @return array<string, mixed>
     */
    public static function packToRules(string $packId, string $libDir): array
    {
        [$modules, $manifests] = self::load($libDir);

        $manifest = null;
        foreach ($manifests as $candidate) {
            if (($candidate['id'] ?? null) === $packId) {
                $manifest = $candidate;
                break;
            }
        }
        if ($manifest === null) {
            throw new \RuntimeException(sprintf('Pack "%s" not found in the library (%s)', $packId, $libDir));
        }

        $rm = PackResolver::ruleModulesFromResolved(PackResolver::resolve($manifest, $modules));
        $coaList = is_array($rm['chartsOfAccounts'] ?? null) ? $rm['chartsOfAccounts'] : [];
        $coa = is_array($coaList[0] ?? null) ? $coaList[0] : [];
        $profileList = is_array($rm['profiles'] ?? null) ? $rm['profiles'] : [];
        $profile = is_array($profileList[0] ?? null) ? $profileList[0] : [];

        return [
            'pack' => ['id' => $packId],
            'ruleModules' => $rm,
            'accounts' => is_array($coa['accounts'] ?? null) ? $coa['accounts'] : [],
            'taxCodes' => $rm['taxCodes'] ?? [],
            'taxProfile' => is_array($profile['defaults'] ?? null) ? $profile['defaults'] : [],
        ];
    }

    /**
     * Load library, classified by content (manifest=has `modules[]`, module=has `kind`).
     *
     * @return array{0: list<array<mixed>>, 1: list<array<mixed>>}
     */
    private static function load(string $dir): array
    {
        $modules = [];
        $manifests = [];
        if (!is_dir($dir)) {
            return [$modules, $manifests];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            $json = json_decode($contents, true);
            if (!is_array($json)) {
                continue;
            }
            if (isset($json['modules']) && is_array($json['modules'])) {
                $manifests[] = $json;
            } elseif (isset($json['kind']) && is_string($json['kind'])) {
                $modules[] = $json;
            }
        }

        return [$modules, $manifests];
    }
}
