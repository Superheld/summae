<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Composition;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\ProjectionParameters;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * Drift guard for the parameter contract.
 *
 * testing/testsuite/schema/api-parameters.json is the normative source; the core cannot read it
 * (framework-free, no file I/O), so it carries the same table as a constant. A copy nobody
 * compares is a copy that drifts — a parameter added to the schema and forgotten in the code
 * would be rejected as "unknown" by the very implementation that is supposed to accept it, and
 * nothing would say so. This test is the comparison, and its Node twin
 * (`projection-parameters.test.ts`) makes the same assertion on the same file, so the two
 * languages cannot drift apart either.
 */
final class ProjectionParametersTest extends TestCase
{
    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function schemaProjections(): array
    {
        $path = __DIR__ . '/../../../../../../testing/testsuite/schema/api-parameters.json';
        self::assertFileExists($path, 'the normative parameter contract must be mirrored here');

        $raw = file_get_contents($path);
        self::assertIsString($raw);

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['projections'] ?? null);

        /** @var array<string, array<string, array<string, mixed>>> $projections */
        $projections = $decoded['projections'];

        return $projections;
    }

    private function freshOps(): TenantOperations
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = Tenant::inMemory('Parameters', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));

        return new TenantOperations($tenant);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function errorCodeOf(string $name, array $params): string
    {
        try {
            $this->freshOps()->project($name, $params);
        } catch (\Throwable $error) {
            return $error instanceof DomainError ? $error->errorCode : 'NOT_A_DOMAIN_ERROR';
        }

        return 'NO_ERROR';
    }

    public function testDeclaresExactlyTheProjectionsTheSchemaDeclares(): void
    {
        $declared = array_keys(ProjectionParameters::PARAMETERS);
        $schema = array_keys($this->schemaProjections());
        sort($declared);
        sort($schema);

        self::assertSame($schema, $declared);
    }

    public function testDeclaresEveryParameterWithTheSameTypeAndFlagsAsTheSchema(): void
    {
        self::assertEquals($this->schemaProjections(), ProjectionParameters::PARAMETERS);
    }

    public function testRejectsAnUndeclaredParameterInsteadOfIgnoringIt(): void
    {
        // The real-world shape: vatReturn takes year/quarter, and `fiscalYear` used to be
        // swallowed, so a caller asking for a quarter got the whole year back without a word.
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('vatReturn', ['year' => 2026, 'fiscalYear' => 2026]));
    }

    public function testRejectsADeclaredParameterOfTheWrongType(): void
    {
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('trialBalance', ['fiscalYear' => 2026.4]));
        self::assertSame('E_INPUT_INVALID', $this->errorCodeOf('trialBalance', ['fiscalYear' => '2026']));
        self::assertSame(
            'E_INPUT_INVALID',
            $this->errorCodeOf('trialBalance', ['fiscalYear' => 2026, 'includeZeroBalances' => 'yes']),
        );
    }

    public function testAcceptsAWholeNumberWrittenWithADecimalPoint(): void
    {
        // json_decode('2026.0') is a float here and an integer in Node — the same JSON call must
        // not produce a report in one language and an empty result in the other.
        self::assertSame('NO_ERROR', $this->errorCodeOf('trialBalance', ['fiscalYear' => 2026.0]));
    }

    public function testLeavesAnAbsentOptionalParameterToItsDocumentedDefault(): void
    {
        self::assertSame('NO_ERROR', $this->errorCodeOf('trialBalance', ['fiscalYear' => 2026]));
        self::assertSame(
            'NO_ERROR',
            $this->errorCodeOf('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => null]),
        );
    }

    public function testLeavesAnUnknownProjectionNameToTheDispatcher(): void
    {
        // Validation must not answer "unknown parameter" for a projection that does not exist —
        // the routing error is the more specific one and has to win.
        self::assertSame('E_NOT_IMPLEMENTED', $this->errorCodeOf('doesNotExist', ['whatever' => 1]));
    }
}
