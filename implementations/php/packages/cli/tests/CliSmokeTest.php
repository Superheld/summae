<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Cli\Cli;
use Summae\Cli\ExitCodes;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * JOB-013-Akzeptanz: Smoke-Tests; SF-02 (Beleg + Steuerexpansion +
 * Buchung) per CLI in einem Aufruf; Exit-Codes = Fehlercodes.
 */
final class CliSmokeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rw-cli-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    public function testSf02InOneCall(): void
    {
        // 1. Arbeitsbereich mit Regeln (Konten, GJ, Steuerschlüssel) anlegen
        $rulesFile = $this->dir . '/regeln.json';
        file_put_contents($rulesFile, json_encode([
            'accounts' => [
                ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
                ['number' => '8400', 'name' => 'Erlöse 19%', 'type' => 'revenue'],
                ['number' => '1776', 'name' => 'USt 19%', 'type' => 'liability', 'subtype' => 'tax_out'],
            ],
            'fiscalYears' => [['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']],
            'taxCodes' => [[
                'code' => 'USt19',
                'versions' => [[
                    'validFrom' => '2024-01-01', 'validTo' => null, 'rate' => '19.00',
                    'taxAccount' => '1776', 'reportingKey' => '81',
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        $init = $this->runCli([
            'command' => 'init',
            '--name' => 'CLI GmbH',
            '--rules' => $rulesFile,
            '--dir' => $this->dir,
        ]);
        self::assertSame(0, $init['exit'], $init['raw']);
        $created = $init['json']['created'] ?? null;
        self::assertIsArray($created);
        self::assertSame(3, $created['accounts'] ?? null);

        // 2. SF-02: ein Aufruf — Beleg anlegen, Steuer expandieren, buchen
        $op = $this->runCli([
            'command' => 'op',
            'operation' => 'postVoucher',
            '--dir' => $this->dir,
            '--input' => json_encode([
                'voucher' => ['voucherNumber' => 'AR-001', 'voucherDate' => '2026-02-10'],
                'entryDate' => '2026-02-10',
                'text' => 'Beratung Februar',
                'taxCode' => 'USt19',
                'direction' => 'output',
                'netLines' => [['account' => '8400', 'money' => ['amount' => '1000.00', 'currency' => 'EUR']]],
                'counterAccount' => '1200',
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(0, $op['exit'], $op['raw']);
        $entry = $op['json']['entry'] ?? null;
        self::assertIsArray($entry);
        self::assertSame(1, $entry['sequenceNumber'] ?? null);
        $gross = $op['json']['grossTotal'] ?? null;
        self::assertIsArray($gross);
        self::assertSame('1190.00', $gross['amount'] ?? null);

        // 3. Persistenz über Aufrufe hinweg: Report in neuem Prozesskontext
        $report = $this->runCli([
            'command' => 'report',
            'projection' => 'trialBalance',
            '--dir' => $this->dir,
            '--params' => '{"fiscalYear": 2026, "throughPeriod": 12}',
        ]);
        self::assertSame(0, $report['exit'], $report['raw']);
        self::assertSame(
            [
                ['account' => '1200', 'balance' => '1190.00'],
                ['account' => '1776', 'balance' => '-190.00'],
                ['account' => '8400', 'balance' => '-1000.00'],
            ],
            array_map(
                static fn (mixed $row): array => is_array($row)
                    ? ['account' => $row['account'] ?? null, 'balance' => $row['balance'] ?? null]
                    : [],
                is_array($report['json']['rows'] ?? null) ? $report['json']['rows'] : [],
            ),
        );
    }

    public function testErrorsMapToExitCodes(): void
    {
        $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir]);

        $result = $this->runCli([
            'command' => 'op',
            'operation' => 'post',
            '--dir' => $this->dir,
            '--input' => '{"entryDate": "2026-01-01", "lines": []}',
        ]);

        self::assertSame('E_ENTRY_TOO_FEW_LINES', $result['json']['error'] ?? null);
        self::assertSame(ExitCodes::for('E_ENTRY_TOO_FEW_LINES'), $result['exit']);
        self::assertGreaterThanOrEqual(10, $result['exit']);
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
