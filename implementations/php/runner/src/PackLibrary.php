<?php

declare(strict_types=1);

namespace Summae\Runner;

/**
 * Lädt die ausgelieferte Pack-Bibliothek von der Platte: Module (rekursiv unter
 * `modules/`) und Manifeste (unter `packs/`). Der Loader, der den reinen Resolver
 * mit echten Produkt-Daten füttert — Pendant zu „Pack bei Installation/Anlegen
 * wählen". Read-only, daher gecacht. Byte-gleich zur Node-Seite (pack-library.ts).
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

        // Inhaltsbasierte Klassifikation: Ordnerstruktur egal — `modules/`+`packs/` ODER ein
        // gesammelter Pack-Ordner (z. B. `de-pack/`). Manifest = hat `modules[]`; Modul = hat `kind`.
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
