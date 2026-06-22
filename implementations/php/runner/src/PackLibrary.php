<?php

declare(strict_types=1);

namespace Summae\Runner;

/**
 * Loads the shipped pack library from disk: modules (recursively under
 * `modules/`) and manifests (under `packs/`). The loader that feeds the pure
 * resolver with real product data — counterpart to "choose a pack at
 * installation/creation". Read-only, hence cached. Byte-equal to the Node side (pack-library.ts).
 */
final class PackLibrary
{
    /** @var array{modules: list<array<mixed>>, manifests: list<array<mixed>>}|null */
    private static ?array $cached = null;

    /**
     * @return array{modules: list<array<mixed>>, manifests: list<array<mixed>>}
     */
    public static function load(?string $dir = null): array
    {
        $default = dirname(__DIR__, 4) . '/pack-library';
        $dir ??= $default;
        if ($dir === $default && self::$cached !== null) {
            return self::$cached;
        }

        // Content-based classification: folder structure irrelevant — `modules/`+`packs/` OR a
        // collected pack folder (e.g. `de-pack/`). Manifest = has `modules[]`; module = has `kind`.
        $modules = [];
        $manifests = [];
        foreach (self::readJsonRecursive($dir) as $json) {
            if (isset($json['modules']) && is_array($json['modules'])) {
                $manifests[] = $json;
            } elseif (isset($json['kind']) && is_string($json['kind'])) {
                $modules[] = $json;
            }
        }
        $library = ['modules' => $modules, 'manifests' => $manifests];

        if ($dir === $default) {
            self::$cached = $library;
        }

        return $library;
    }

    /**
     * @return list<array<mixed>>
     */
    private static function readJsonRecursive(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        $out = [];
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }
}
