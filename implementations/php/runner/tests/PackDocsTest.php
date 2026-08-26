<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The pack documentation describes the packs that are actually shipped.
 *
 * `knowledge/99-pack-docs/` is the reference work for whoever builds or audits a pack — one file
 * per module, position by position, account by account. Nothing held it against the modules, and
 * on 2026-08-26 it turned out that two of the three balance-sheet documents described a product
 * that does not exist: the `de` document listed positions `A`, `B.I`, `B.II`, `B.III` where the
 * module ships `A.I`–`A.V` and `P.A1`–`P.D`, and the `us` document had the two sides of the chart
 * swapped — equity at 2000–2499, payables at 3000–3099, the exact opposite of what the pack does.
 * They read as design notes written before the modules were built and never reconciled (IMPL-031).
 *
 * A reference work that is wrong is worse than none: it is believed. So the parts a machine can
 * check are checked, and the prose is left alone.
 *
 * Three rules, each narrow enough that a pack author can satisfy it without guessing:
 *  1. every module the manifest lists has exactly one document, found by its own `id:` header;
 *  2. that header states the module's real `kind`, `id` and `version` — so a version bump cannot
 *     land without the document being opened;
 *  3. a mapping document's table names every position of its module, and each row carries the
 *     account selection that position really has.
 *
 * Deliberately NOT checked: labels and prose. A document may call a position something clearer
 * than the module's own label, and should.
 *
 * Mirror of the Node `pack-docs.test.ts`; the rules must stay identical in both languages.
 */
final class PackDocsTest extends TestCase
{
    public function testEveryShippedModuleHasExactlyOneDocument(): void
    {
        $violations = [];

        foreach ($this->packs() as $pack => $dir) {
            $manifest = $this->manifestOf($dir);
            if ($manifest === null) {
                continue;
            }
            /** @var list<mixed> $refs */
            $refs = is_array($manifest['modules'] ?? null) ? array_values($manifest['modules']) : [];
            $docs = $this->docsIn($this->docsDir($pack));
            if ($docs === []) {
                $violations[] = sprintf(
                    '%s: ships %d modules and has no documentation folder at all',
                    $pack,
                    count($refs),
                );
                continue;
            }

            foreach ($refs as $ref) {
                if (!is_array($ref) || !is_string($ref['id'] ?? null)) {
                    continue;
                }
                $matching = array_values(array_filter(
                    $docs,
                    static fn (array $doc): bool => ($doc['header']['id'] ?? null) === $ref['id'],
                ));
                if ($matching === []) {
                    $kind = is_string($ref['kind'] ?? null) ? $ref['kind'] : '?';
                    $violations[] = sprintf('%s: module "%s" (%s) has no document', $pack, $ref['id'], $kind);
                } elseif (count($matching) > 1) {
                    $files = implode(', ', array_map(static fn (array $d): string => $d['file'], $matching));
                    $violations[] = sprintf('%s: module "%s" is documented twice: %s', $pack, $ref['id'], $files);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "A module nobody documented is a module nobody can audit — and the documents are the\n"
                . "reference work a pack author reads before touching the data:\n"
                . implode("\n", $violations),
        );
    }

    public function testEveryHeaderStatesTheRealKindIdAndVersion(): void
    {
        $violations = [];

        foreach ($this->packs() as $pack => $dir) {
            $modules = $this->modulesOf($dir);
            foreach ($this->docsIn($this->docsDir($pack)) as $doc) {
                $id = $doc['header']['id'] ?? null;
                if ($id === null) {
                    continue; // not a module document (README, decisions, …)
                }
                if (!isset($modules[$id])) {
                    $violations[] = sprintf('%s (%s): documents "%s", which is not a module of this pack', $pack, $doc['file'], $id);
                    continue;
                }
                foreach (['kind', 'version', 'formatVersion'] as $field) {
                    $stated = $doc['header'][$field] ?? null;
                    $real = $modules[$id][$field] ?? null;
                    if ($stated !== null && $stated !== $real) {
                        $violations[] = sprintf(
                            '%s (%s): says %s %s, the module says %s',
                            $pack,
                            $doc['file'],
                            $field,
                            $stated,
                            is_string($real) ? $real : '?',
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "The header is what a reader trusts before reading anything else, and a version bump\n"
                . "that leaves it behind makes the whole document undatable:\n"
                . implode("\n", $violations),
        );
    }

    public function testMappingTablesNameTheAccountsTheModuleClaims(): void
    {
        $violations = [];

        foreach ($this->packs() as $pack => $dir) {
            $modules = $this->modulesOf($dir);
            foreach ($this->docsIn($this->docsDir($pack)) as $doc) {
                $id = $doc['header']['id'] ?? null;
                if ($id === null || !isset($modules[$id]) || ($modules[$id]['kind'] ?? null) !== 'mapping') {
                    continue;
                }

                $data = is_array($modules[$id]['data'] ?? null) ? $modules[$id]['data'] : [];
                $mapping = is_array($data['mapping'] ?? null) ? $data['mapping'] : [];

                // Table rows, by their first cell — that is where every document puts the key.
                $rows = [];
                foreach (explode("\n", $doc['text']) as $line) {
                    $cells = array_map('trim', explode('|', $line));
                    if (count($cells) > 2 && preg_match('/^[A-Za-z0-9._]+$/', $cells[1]) === 1) {
                        $rows[$cells[1]] = $line;
                    }
                }

                $leaves = [];
                $this->collectLeaves($mapping['positions'] ?? null, $leaves);
                foreach ($leaves as $leaf) {
                    $key = is_string($leaf['key'] ?? null) ? $leaf['key'] : '';
                    if (!isset($rows[$key])) {
                        $violations[] = sprintf('%s (%s): position %s is in the module and in no table row', $pack, $doc['file'], $key);
                        continue;
                    }
                    $missing = [];
                    foreach ($this->selectors($leaf) as $selector) {
                        if (!str_contains($this->normalise($rows[$key]), $this->normalise($selector))) {
                            $missing[] = $selector;
                        }
                    }
                    if ($missing !== []) {
                        $violations[] = sprintf('%s (%s): row %s does not name %s', $pack, $doc['file'], $key, implode(', ', $missing));
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "A mapping table that names other accounts than the mapping is the most dangerous kind\n"
                . "of wrong: it is specific, it looks checked, and it is what somebody builds the next\n"
                . "pack from:\n"
                . implode("\n", $violations),
        );
    }

    /** @return array<string, string> pack name => directory */
    private function packs(): array
    {
        $root = dirname(__DIR__, 4) . '/pack-library';
        $packs = [];
        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $packs[basename($dir)] = $dir;
        }

        return $packs;
    }

    private function docsDir(string $pack): string
    {
        return dirname(__DIR__, 4) . '/knowledge/99-pack-docs/' . $pack;
    }

    /** @return array<string, mixed>|null */
    private function manifestOf(string $dir): ?array
    {
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            /** @var array<string, mixed> $parsed */
            $parsed = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($parsed['modules'] ?? null)) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Every module file of a pack, by id. `versions/` holds retired copies on purpose — they are
     * not what the pack ships today.
     *
     * @return array<string, array<string, mixed>>
     */
    private function modulesOf(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            if (str_contains($file->getPathname(), '/versions/')) {
                continue;
            }
            /** @var array<string, mixed> $parsed */
            $parsed = json_decode((string) file_get_contents($file->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            if (is_string($parsed['kind'] ?? null) && is_string($parsed['id'] ?? null)) {
                $out[$parsed['id']] = $parsed;
            }
        }

        return $out;
    }

    /**
     * The header block every module document opens with: `kind: x · id: y · version: z`, in a
     * fenced block, one fact per `key: value` pair separated by `·`.
     *
     * @return list<array{file: string, text: string, header: array<string, string>}>
     */
    private function docsIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $docs = [];
        foreach (glob($dir . '/*.md') ?: [] as $file) {
            $text = (string) file_get_contents($file);
            $header = [];
            if (preg_match('/```[\s\S]*?```/', $text, $fence) === 1) {
                foreach (preg_split('/[·\n]/u', $fence[0]) ?: [] as $part) {
                    if (preg_match('/^\s*(kind|id|version|formatVersion)\s*:\s*([A-Za-z0-9._-]+)/', $part, $m) === 1) {
                        $header[$m[1]] = $m[2];
                    }
                }
            }
            $docs[] = ['file' => basename($file), 'text' => $text, 'header' => $header];
        }

        return $docs;
    }

    /**
     * @param mixed              $node
     * @param list<array<mixed>> $out
     */
    private function collectLeaves(mixed $node, array &$out): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['key']) && !isset($node['children'])) {
            $out[] = $node;
        }
        foreach ($node as $child) {
            $this->collectLeaves($child, $out);
        }
    }

    /**
     * How a position's account selection reads, in the form the documents use.
     *
     * @param array<mixed> $leaf
     *
     * @return list<string>
     */
    private function selectors(array $leaf): array
    {
        $out = [];
        foreach (is_array($leaf['accounts'] ?? null) ? $leaf['accounts'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            if (is_array($spec['numbers'] ?? null)) {
                foreach ($spec['numbers'] as $number) {
                    if (is_string($number)) {
                        $out[] = $number;
                    }
                }
            } elseif (is_string($spec['from'] ?? null) && is_string($spec['to'] ?? null)) {
                $out[] = $spec['from'] . '-' . $spec['to'];
            }
        }

        return $out;
    }

    /** En dashes, spaces and thousands dots are formatting, not content. */
    private function normalise(string $text): string
    {
        return (string) preg_replace('/\s+/u', '', str_replace(['–', '—'], '-', $text));
    }
}
