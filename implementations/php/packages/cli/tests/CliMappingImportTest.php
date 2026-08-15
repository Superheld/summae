<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Cli\Cli;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * R-4 — `importMapping` answered `imported: true` and stored nothing.
 *
 * Mappings live in a registry the CLI rebuilds from `summae.json` on every invocation, and the
 * import never wrote back. Inside one process it worked, which is why it looks fine in a unit
 * test; across two calls — the only way a CLI is ever used — the mapping was gone, and the report
 * that followed failed as though it had never been imported. The documented flow could not work.
 *
 * The SAME cases live in the Node cli-mapping-import.test.ts.
 */
final class CliMappingImportTest extends TestCase
{
    private const array MAPPING = [
        'id' => 'guv-test',
        'kind' => 'income-statement',
        'version' => '1',
        'positions' => [['key' => '1', 'label' => 'Umsatzerlöse', 'accounts' => [['from' => '8000', 'to' => '8999']]]],
    ];

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/summae-mapping-' . bin2hex(random_bytes(4));
        mkdir($this->dir);

        $rules = $this->dir . '/rules.json';
        file_put_contents($rules, json_encode([
            'accounts' => [
                ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
                ['number' => '8400', 'name' => 'Erlöse', 'type' => 'revenue'],
            ],
            'fiscalYears' => [['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']],
        ], JSON_THROW_ON_ERROR));

        $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir, '--rules' => $rules]);
    }

    public function testAnImportedMappingIsUsableByALaterInvocation(): void
    {
        $imported = $this->import(self::MAPPING);
        self::assertTrue($imported['json']['imported'] ?? false, 'the import itself already reported success before this fix');

        // The second call is the whole point: a new process, a registry rebuilt from summae.json.
        $report = $this->report('guv-test');
        self::assertArrayNotHasKey('error', $report['json'], 'the mapping must still be there');
        self::assertArrayHasKey('positions', $report['json']);
    }

    public function testImportingTheSameIdReplacesRatherThanDuplicates(): void
    {
        $this->import(self::MAPPING);

        $changed = self::MAPPING;
        $changed['positions'] = [['key' => '1', 'label' => 'Erlöse neu', 'accounts' => [['from' => '8000', 'to' => '8999']]]];
        $this->import($changed);

        // Two mappings with one id would be E_MAPPING_OVERLAP territory on the next load.
        $report = $this->report('guv-test');
        self::assertArrayNotHasKey('error', $report['json']);
    }

    public function testAFailedImportIsNotPersisted(): void
    {
        $overlapping = [
            'id' => 'kaputt',
            'kind' => 'income-statement',
            'version' => '1',
            'positions' => [
                ['key' => '1', 'label' => 'A', 'accounts' => [['from' => '8000', 'to' => '8999']]],
                ['key' => '2', 'label' => 'B', 'accounts' => [['from' => '8400', 'to' => '8400']]],
            ],
        ];

        $failed = $this->import($overlapping);
        self::assertSame('E_MAPPING_OVERLAP', $failed['json']['error'] ?? null);

        $report = $this->report('kaputt');
        self::assertSame('E_INPUT_INVALID', $report['json']['error'] ?? null, 'a rejected mapping must not be persisted');
    }

    /**
     * @param array<string, mixed> $mapping
     *
     * @return array{exit: int, json: array<string, mixed>, raw: string}
     */
    private function import(array $mapping): array
    {
        return $this->runCli([
            'command' => 'op', 'operation' => 'importMapping', '--dir' => $this->dir,
            '--input' => json_encode(['mapping' => $mapping], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{exit: int, json: array<string, mixed>, raw: string}
     */
    private function report(string $mappingId): array
    {
        return $this->runCli([
            'command' => 'report', 'projection' => 'incomeStatement', '--dir' => $this->dir,
            '--params' => json_encode(['fiscalYear' => 2026, 'mapping' => $mappingId], JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{exit: int, json: array<string, mixed>, raw: string}
     */
    private function runCli(array $input): array
    {
        $output = new BufferedOutput();
        $exit = Cli::application()->run(new ArrayInput($input), $output);
        $raw = $output->fetch();
        $json = json_decode(trim($raw) === '' ? '{}' : trim($raw), true);

        /** @var array<string, mixed> $jsonArray */
        $jsonArray = is_array($json) ? $json : [];

        return ['exit' => $exit, 'json' => $jsonArray, 'raw' => $raw];
    }
}
