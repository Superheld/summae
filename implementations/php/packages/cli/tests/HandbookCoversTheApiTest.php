<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\OperationParameters;
use Summae\Core\Composition\ProjectionParameters;
use Summae\Core\Policies\Projection\SystemDescriptionProjection;

/**
 * The manual documents every name the API publishes.
 *
 * summae's own rule is that documentation which stops being true turns a build red rather than
 * rotting on the page — the walkthrough scenarios do that for behaviour. This does it for
 * *coverage*: a capability can be finished, fixture-covered and published, and still be
 * undiscoverable because nobody wrote the section. Four of them were (`cashJournal`,
 * `unfinalizedEntries`, `systemDescription`, `allocate`), and nothing said so.
 *
 * Deliberately weak on purpose: it asks for a heading that names the operation or projection,
 * not for a well-written one. A guard that tried to judge the prose would be a guard nobody
 * keeps green. The counterpart on the Node side reads the same file.
 *
 * **Since SPEC-019 it also reaches the vocabulary.** Coverage by NAME was not enough: `taxTag` was
 * published in the manual as "(object, no)" — no shape, no word that `vatReturn` counts only tagged
 * lines, nothing about the sign convention — and an embedding concluded from that that a capability
 * working since v0.4 was impossible, shipped a screen without a discount field, and recorded a
 * legal obligation as unimplementable. A field that is named and never explained is worse than one
 * that is absent: absent, they would have asked. So every key the input contract declares, at every
 * depth, must appear in the section of the operation that accepts it — still weak in the same way,
 * because appearing is not the same as being explained, and quality stays with review.
 */
final class HandbookCoversTheApiTest extends TestCase
{
    /** @return list<string> */
    private function headings(): array
    {
        $manual = (string) file_get_contents(__DIR__ . '/../../../../../docs/handbuch/README.md');

        return array_values(array_filter(
            explode("\n", $manual),
            static fn (string $line): bool => (bool) preg_match('/^#{3,4} /', $line),
        ));
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function undocumented(array $names): array
    {
        $headings = $this->headings();

        return array_values(array_filter($names, function (string $name) use ($headings): bool {
            foreach ($headings as $heading) {
                if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $heading) === 1) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Documented once for every operation in "Conventions for this whole section" rather than in
     * each of the thirty sections that accept it. A list on purpose: adding to it is a decision
     * somebody makes visibly, not a hole that opens by itself.
     *
     * @var list<string>
     */
    private const DOCUMENTED_GLOBALLY = ['actor'];

    private function manual(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../../../../docs/handbuch/README.md');
    }

    /** The text from an operation's heading up to the next heading of the same or a higher level. */
    private function sectionOf(string $name): string
    {
        $lines = explode("\n", $this->manual());
        $heads = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^#{3,4} /', $line) === 1) {
                $heads[] = $i;
            }
        }

        foreach ($heads as $index => $start) {
            if (preg_match('/\b' . preg_quote($name, '/') . '\b/', $lines[$start]) !== 1) {
                continue;
            }

            $end = $heads[$index + 1] ?? count($lines);

            return implode("\n", array_slice($lines, $start, $end - $start));
        }

        return '';
    }

    /**
     * @param array<mixed> $spec
     * @param list<string> $out
     */
    private static function collectKeys(array $spec, array &$out): void
    {
        $fields = is_array($spec['fields'] ?? null) ? $spec['fields'] : [];
        foreach ($fields as $key => $field) {
            $out[] = (string) $key;
            if (is_array($field)) {
                self::collectKeys($field, $out);
            }
        }

        $element = $spec['element'] ?? null;
        if (is_array($element)) {
            self::collectKeys($element, $out);
        }
    }

    public function testExplainsEveryInputKeyTheContractDeclares(): void
    {
        $gaps = [];
        foreach ([OperationParameters::OPERATIONS, ProjectionParameters::PARAMETERS] as $table) {
            foreach ($table as $op => $params) {
                $section = $this->sectionOf((string) $op);
                $want = array_map(strval(...), array_keys($params));
                foreach ($params as $spec) {
                    self::collectKeys($spec, $want);
                }

                foreach (array_unique($want) as $key) {
                    if (in_array($key, self::DOCUMENTED_GLOBALLY, true)) {
                        continue;
                    }
                    if (preg_match('/\b' . preg_quote((string) $key, '/') . '\b/', $section) !== 1) {
                        $gaps[] = $op . '.' . $key;
                    }
                }
            }
        }

        sort($gaps);
        self::assertSame([], $gaps, 'a declared input key must be named in the manual section of the '
            .'operation that accepts it — a field that is published and never explained is worse than '
            .'one that is absent (SPEC-019)');
    }

    public function testDocumentsEveryPublishedOperation(): void
    {
        self::assertSame(
            [],
            $this->undocumented(SystemDescriptionProjection::API_OPERATIONS),
            'every operation needs its own section in docs/handbuch/README.md',
        );
    }

    public function testDocumentsEveryPublishedProjection(): void
    {
        self::assertSame(
            [],
            $this->undocumented(SystemDescriptionProjection::API_PROJECTIONS),
            'every projection needs its own section in docs/handbuch/README.md',
        );
    }

    public function testReadsTheManualItClaimsToRead(): void
    {
        // Without this a moved or renamed file would leave both tests above passing on nothing.
        self::assertGreaterThan(40, count($this->headings()));
    }
}
