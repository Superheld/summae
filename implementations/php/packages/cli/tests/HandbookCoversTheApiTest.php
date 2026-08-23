<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
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
