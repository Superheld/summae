<?php

declare(strict_types=1);

namespace Summae\Cli\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Summae\Cli\Cli;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * R-8, R-9, R-10 — the ways `init` and the workspace file let a caller down.
 *
 * These are deliberately malformed invocations, which is why they live here and not in
 * `testing/scenarios/`: those are the documentation in executable form and every step in them is
 * exemplary. Nonsense input belongs where it cannot be mistaken for a recommendation.
 *
 * The SAME cases live in the Node cli-init-robustness.test.ts.
 */
final class CliInitRobustnessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/summae-init-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    public function testPackAndRulesTogetherAreRejectedInsteadOfDroppingRules(): void
    {
        // The help calls them alternatives; the code took the pack branch and ignored the file
        // entirely, so a caller who meant "pack plus my accounts" silently got only the pack.
        $rules = $this->rulesFile([['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']]);

        $result = $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir, '--pack' => 'de', '--rules' => $rules]);

        self::assertSame('E_INPUT_INVALID', $result['json']['error'] ?? null);
        self::assertFileDoesNotExist($this->dir . '/summae.json', 'nothing may be written on a rejected call');
    }

    /**
     * @return list<array{string}>
     */
    public static function badYears(): array
    {
        return [[''], ['zweitausendsechsundzwanzig'], ['2026.5'], ['-1']];
    }

    #[DataProvider('badYears')]
    public function testFirstFiscalYearIsValidated(string $value): void
    {
        // "" became year 0000 through an unchecked int cast, and the workspace was written before
        // anything looked at it.
        $result = $this->runCli([
            'command' => 'init', '--name' => 'X', '--dir' => $this->dir,
            '--pack' => 'de', '--first-fiscal-year' => $value,
        ]);

        self::assertSame('E_INPUT_INVALID', $result['json']['error'] ?? null);
        self::assertFileDoesNotExist($this->dir . '/summae.json');
    }

    public function testAProperYearIsAccepted(): void
    {
        $result = $this->runCli([
            'command' => 'init', '--name' => 'X', '--dir' => $this->dir,
            '--pack' => 'de', '--first-fiscal-year' => '2027',
        ]);

        self::assertTrue($result['json']['initialized'] ?? false);
    }

    public function testAFailedInitLeavesTheDirectoryReInitialisable(): void
    {
        // Two accounts with the same number: the workspace is written first, then the second
        // createAccount throws — which used to leave a config and a database behind, so every
        // retry answered "Workspace already exists" and the directory was a dead end.
        $broken = $this->rulesFile([
            ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
            ['number' => '1200', 'name' => 'Bank nochmal', 'type' => 'asset', 'subtype' => 'bank'],
        ]);

        $failed = $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir, '--rules' => $broken]);
        self::assertSame('E_ACCOUNT_NUMBER_TAKEN', $failed['json']['error'] ?? null, 'the duplicate must surface');
        self::assertFileDoesNotExist($this->dir . '/summae.json', 'the failed attempt must not leave a workspace');

        // The point of the rollback: fixing the input and retrying has to work.
        $fixed = $this->rulesFile([['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']]);
        $retry = $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir, '--rules' => $fixed]);
        self::assertTrue($retry['json']['initialized'] ?? false);
    }

    public function testAConfigWithoutTenantIdIsRefusedInsteadOfInvented(): void
    {
        $rules = $this->rulesFile([['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']]);
        $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir, '--rules' => $rules]);

        // Parseable JSON, missing the one field that identifies the tenant. Every field was
        // defaulted and the tenantId regenerated, so the CLI opened the same database as a
        // different tenant and reported an empty ledger — books that look wiped.
        $path = $this->dir . '/summae.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        /** @var array<string, mixed> $config */
        $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        unset($config['tenantId']);
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));

        $result = $this->runCli([
            'command' => 'report', 'projection' => 'trialBalance',
            '--dir' => $this->dir, '--params' => '{"fiscalYear":2026}',
        ]);

        self::assertSame('E_WORKSPACE_INVALID', $result['json']['error'] ?? null);
    }

    public function testTheGuardNamesTheMissingField(): void
    {
        $rules = $this->rulesFile([]);
        $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir, '--rules' => $rules]);
        file_put_contents($this->dir . '/summae.json', json_encode(['tenantId' => 'not-a-uuid'], JSON_THROW_ON_ERROR));

        $result = $this->runCli([
            'command' => 'report', 'projection' => 'trialBalance',
            '--dir' => $this->dir, '--params' => '{"fiscalYear":2026}',
        ]);

        self::assertSame('E_WORKSPACE_INVALID', $result['json']['error'] ?? null);
        $details = $result['json']['details'] ?? null;
        self::assertIsArray($details);
        self::assertNotSame('', $details['field'] ?? '');
    }

    public function testAnUntouchedWorkspaceStillWorks(): void
    {
        $rules = $this->rulesFile(
            [['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']],
            [['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']],
        );
        $this->runCli(['command' => 'init', '--name' => 'X', '--dir' => $this->dir, '--rules' => $rules]);

        $result = $this->runCli([
            'command' => 'report', 'projection' => 'trialBalance',
            '--dir' => $this->dir, '--params' => '{"fiscalYear":2026}',
        ]);

        self::assertArrayNotHasKey('error', $result['json'], 'the guard must not forbid the healthy case');
    }

    /**
     * @param list<array<string, mixed>> $accounts
     * @param list<array<string, mixed>> $fiscalYears
     */
    private function rulesFile(array $accounts, array $fiscalYears = []): string
    {
        $path = $this->dir . '/rules-' . bin2hex(random_bytes(3)) . '.json';
        file_put_contents($path, json_encode(
            ['accounts' => $accounts] + ($fiscalYears === [] ? [] : ['fiscalYears' => $fiscalYears]),
            JSON_THROW_ON_ERROR,
        ));

        return $path;
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
