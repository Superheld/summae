<?php

declare(strict_types=1);

namespace Summae\Runner\Tests;

use PHPUnit\Framework\TestCase;
use Summae\Runner\Fixture;
use Summae\Runner\FixtureRunner;
use Summae\Runner\FixtureStatus;
use Summae\Runner\Subject\Subject;
use Summae\Runner\Subject\SubjectError;

final class FixtureRunnerTest extends TestCase
{
    public function testExpectedErrorMatchesAndPasses(): void
    {
        $fixture = $this->fixture([
            'steps' => [
                ['op' => 'post', 'input' => [], 'expect' => ['error' => 'E_ENTRY_UNBALANCED']],
            ],
        ]);

        $subject = $this->subject(execute: static fn () => throw new SubjectError('E_ENTRY_UNBALANCED'));

        $result = (new FixtureRunner())->run($fixture, $subject);

        self::assertSame(FixtureStatus::Pass, $result->status);
    }

    public function testWrongErrorCodeFails(): void
    {
        $fixture = $this->fixture([
            'steps' => [
                ['op' => 'post', 'input' => [], 'expect' => ['error' => 'E_ENTRY_UNBALANCED']],
            ],
        ]);

        $subject = $this->subject(execute: static fn () => throw new SubjectError('E_NOT_IMPLEMENTED'));

        $result = (new FixtureRunner())->run($fixture, $subject);

        self::assertSame(FixtureStatus::Fail, $result->status);
        self::assertStringContainsString('E_ENTRY_UNBALANCED', $result->diffs[0]);
    }

    public function testUnexpectedExceptionIsCrashNotFail(): void
    {
        $fixture = $this->fixture([
            'steps' => [['op' => 'post', 'input' => [], 'expect' => ['result' => []]]],
        ]);

        $subject = $this->subject(execute: static fn () => throw new \TypeError('kaputt'));

        $result = (new FixtureRunner())->run($fixture, $subject);

        self::assertSame(FixtureStatus::Crash, $result->status);
    }

    public function testSetupPlaceholdersResolveToSameIdAcrossSteps(): void
    {
        /** @var array{setupVoucherId?: string, inputVoucherId?: mixed} $captured */
        $captured = [];
        $fixture = $this->fixture([
            'setup' => ['vouchers' => [['id' => '$V1', 'voucherNumber' => 'AR-1']]],
            'steps' => [
                ['op' => 'post', 'input' => ['voucherId' => '$V1'], 'expect' => ['result' => []]],
            ],
        ]);

        $subject = $this->subject(
            setup: static function (array $setup) use (&$captured): void {
                /** @var array{vouchers: list<array{id: string}>} $setup */
                $captured['setupVoucherId'] = $setup['vouchers'][0]['id'];
            },
            execute: static function (string $op, array $input) use (&$captured): array {
                $captured['inputVoucherId'] = $input['voucherId'] ?? null;

                return [];
            },
        );

        $result = (new FixtureRunner())->run($fixture, $subject);

        self::assertSame(FixtureStatus::Pass, $result->status);
        $setupVoucherId = $captured['setupVoucherId'] ?? null;
        self::assertIsString($setupVoucherId);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $setupVoucherId);
        self::assertSame($setupVoucherId, $captured['inputVoucherId'] ?? null);
    }

    public function testProjectionCanExpectError(): void
    {
        $fixture = $this->fixture([
            'projections' => [
                [
                    'name' => 'cashBasisReport',
                    'params' => ['year' => 2026],
                    'expect' => ['error' => 'E_CASHBASIS_DEVIATING_FISCAL_YEAR'],
                ],
            ],
        ]);

        $subject = $this->subject(
            project: static fn () => throw new SubjectError('E_CASHBASIS_DEVIATING_FISCAL_YEAR'),
        );

        $result = (new FixtureRunner())->run($fixture, $subject);

        self::assertSame(FixtureStatus::Pass, $result->status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function fixture(array $data): Fixture
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture');
        self::assertIsString($path);

        file_put_contents($path, json_encode(array_merge([
            'fixture' => 'test-fixture',
            'setup' => [],
            'steps' => [],
            'projections' => [],
        ], $data), JSON_THROW_ON_ERROR));

        return Fixture::fromFile($path);
    }

    /**
     * @param null|callable(array<string, mixed>): void $setup
     * @param null|callable(string, array<string, mixed>): array<string, mixed> $execute
     * @param null|callable(string, array<string, mixed>): array<string, mixed> $project
     */
    private function subject(?callable $setup = null, ?callable $execute = null, ?callable $project = null): Subject
    {
        return new class(
            $setup === null ? null : $setup(...),
            $execute === null ? null : $execute(...),
            $project === null ? null : $project(...),
        ) implements Subject {
            public function __construct(
                private readonly ?\Closure $setupFn,
                private readonly ?\Closure $executeFn,
                private readonly ?\Closure $projectFn,
            ) {
            }

            public function setup(array $setup): void
            {
                if ($this->setupFn !== null) {
                    ($this->setupFn)($setup);
                }
            }

            public function execute(string $op, array $input): array
            {
                if ($this->executeFn === null) {
                    return [];
                }

                /** @var array<string, mixed> */
                return ($this->executeFn)($op, $input);
            }

            public function project(string $name, array $params): array
            {
                if ($this->projectFn === null) {
                    return [];
                }

                /** @var array<string, mixed> */
                return ($this->projectFn)($name, $params);
            }
        };
    }
}
