<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Composition;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
use Summae\Core\Policies\Projection\SystemDescriptionProjection;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * Contract test for the dispatcher surface (TenantOperations). The runner's behavioral
 * fixtures exercise individual operations with valid input, but they do NOT pin the
 * contract: that every operation/projection named in the API spec resolves to a handler,
 * that an unknown name maps to the defined error, and — across languages — that the
 * surface is identical. A routing gap (a misspelled `case`, a dropped op, PHP/Node drift)
 * must fail loudly here. The SAME two lists live in the Node tenant-operations-contract
 * test; if one language's dispatcher drops or renames a case, that language's test goes red.
 */
final class TenantOperationsContractTest extends TestCase
{
    /** @var list<string> */
    private const OPERATIONS = [
        'expandTax', 'setTaxProfile', 'postVoucher', 'createVoucher', 'post', 'correct',
        'finalize', 'reverse', 'settle', 'closePeriod', 'reopenPeriod', 'closeFiscalYear',
        'createAccount', 'createFiscalYear', 'createPartner', 'updatePartner', 'acquireAsset',
        'disposeAsset', 'runDepreciation', 'allocate', 'setAllocationScheme', 'runCosting',
        'releaseCosting', 'lockAccount', 'unlockAccount', 'importChartOfAccounts', 'importMapping',
        'writeDownAsset', 'bookSpecialDepreciation', 'reportAssetUsage',
        'defineDimensionType', 'defineDimensionValue', 'deactivatePartner', 'reactivatePartner',
        'appropriateResult', 'setEntityProfile', 'erasePartner',
    ];

    /** @var list<string> */
    private const PROJECTIONS = [
        'trialBalance', 'openItems', 'accountSheet', 'auditLog', 'auditTrailIntegrity', 'duplicateVouchers', 'gdpduExport', 'unfinalizedEntries', 'assetRegister',
        'costAllocationSheet', 'ecSalesList', 'incomeStatement', 'balanceSheet', 'vatReturn',
        'cashJournal',
        'cashBasisReport', 'journalExport', 'datevExport', 'auditDataExport', 'systemDescription',
        'overheadRates', 'productionCost', 'accounts', 'fiscalYears', 'journal', 'costingRuns',
        'tenantConfiguration', 'unappropriatedResult',
            'personalDataDescription',
    ];

    private function freshOps(): TenantOperations
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $tenant = Tenant::inMemory('Contract', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));

        return new TenantOperations($tenant);
    }

    /**
     * "Resolved to a handler" = the dispatcher did NOT fall through to its E_NOT_IMPLEMENTED
     * default. The handler may still reject the empty input with a different error — that
     * proves routing worked, which is exactly what this contract pins (not input behavior).
     *
     * @param callable():mixed $call
     */
    private function routesToHandler(callable $call): bool
    {
        try {
            $call();

            return true;
        } catch (\Throwable $error) {
            return !($error instanceof DomainError && $error->errorCode === 'E_NOT_IMPLEMENTED');
        }
    }

    public function testRoutesEveryDocumentedOperationToAHandler(): void
    {
        $gaps = array_values(array_filter(
            self::OPERATIONS,
            fn (string $op): bool => !$this->routesToHandler(fn () => $this->freshOps()->execute($op, [])),
        ));

        self::assertSame([], $gaps, 'every API-spec operation must resolve to a handler');
    }

    public function testRoutesEveryDocumentedProjectionToAHandler(): void
    {
        $gaps = array_values(array_filter(
            self::PROJECTIONS,
            fn (string $name): bool => !$this->routesToHandler(fn () => $this->freshOps()->project($name, [])),
        ));

        self::assertSame([], $gaps, 'every API-spec projection must resolve to a handler');
    }

    public function testUnknownOperationMapsToNotImplemented(): void
    {
        try {
            $this->freshOps()->execute('noSuchOperation', []);
            self::fail('expected a throw');
        } catch (DomainError $error) {
            self::assertSame('E_NOT_IMPLEMENTED', $error->errorCode);
        }
    }

    public function testUnknownProjectionMapsToNotImplemented(): void
    {
        try {
            $this->freshOps()->project('noSuchProjection', []);
            self::fail('expected a throw');
        } catch (DomainError $error) {
            self::assertSame('E_NOT_IMPLEMENTED', $error->errorCode);
        }
    }

    public function testPublishesExactlyTheOperationsThisContractPins(): void
    {
        // systemDescription publishes the operation and projection lists as part of the
        // technical system documentation (F-IO-007). The literal lists in this class are an
        // independent oracle: they come from the API spec, not from the code. Comparing the
        // two means a name dropped from the published list and a `case` dropped from the
        // dispatcher cannot cancel each other out and leave the description quietly lying.
        $published = SystemDescriptionProjection::API_OPERATIONS;
        $pinned = self::OPERATIONS;
        sort($published);
        sort($pinned);

        self::assertSame($pinned, $published);
    }

    public function testPublishesExactlyTheProjectionsThisContractPins(): void
    {
        $published = SystemDescriptionProjection::API_PROJECTIONS;
        $pinned = self::PROJECTIONS;
        sort($published);
        sort($pinned);

        self::assertSame($pinned, $published);
    }

    /**
     * The dispatcher's own routing table, read off its source.
     *
     * The constants above are an oracle for "everything published resolves". They cannot answer
     * the other direction — that nothing resolves which is *not* published — because a `match`
     * has no runtime shape to enumerate. So the test reads the file: `execute` routes everything
     * up to `project`, `project` everything after it, and every arm at the match's own
     * indentation is one routed name (a nested array key sits deeper and is not one).
     *
     * The direction matters. summae had seven routed names in neither published list
     * (`writeDownAsset`, `bookSpecialDepreciation`, `reportAssetUsage`, `defineDimensionType`,
     * `defineDimensionValue`, `overheadRates`, `productionCost`) — finished, documented,
     * fixture-covered capabilities that `systemDescription` did not admit to. An embedding app
     * whose contract test holds every call against the published list cannot call them at all,
     * which is what an app reported. A surface larger than its declaration passes a green suite in
     * both languages as long as only one direction is asked.
     *
     * @return array{operations: list<string>, projections: list<string>}
     */
    private function routedNames(): array
    {
        $source = (string) file_get_contents(
            __DIR__ . '/../../src/Composition/TenantOperations.php'
        );

        // Bounded to the match block itself, not to the rest of the file: the private helpers
        // below `project()` build result arrays whose keys sit at the same indentation and would
        // otherwise read as routed names.
        $arms = static function (string $head) use ($source): array {
            $start = (int) strpos($source, $head);
            $end = (int) strpos($source, "\n        };", $start);
            preg_match_all("/^            '([A-Za-z]+)' =>/m", substr($source, $start, $end - $start), $matches);

            return $matches[1];
        };

        return [
            'operations' => $arms('return match ($op) {'),
            'projections' => $arms('return match ($name) {'),
        ];
    }

    public function testPublishesEveryOperationTheDispatcherRoutes(): void
    {
        $undeclared = array_values(array_diff(
            $this->routedNames()['operations'],
            SystemDescriptionProjection::API_OPERATIONS,
        ));

        self::assertSame([], $undeclared, 'a routed operation that systemDescription does not publish cannot be called by a caller that trusts the published list');
    }

    public function testPublishesEveryProjectionTheDispatcherRoutes(): void
    {
        $undeclared = array_values(array_diff(
            $this->routedNames()['projections'],
            SystemDescriptionProjection::API_PROJECTIONS,
        ));

        self::assertSame([], $undeclared, 'a routed projection that systemDescription does not publish is a surface larger than its declaration');
    }

    public function testReadsTheDispatcherSourceItClaimsToRead(): void
    {
        // Without this, a renamed file or a `match` turned into a lookup table would make both
        // tests above pass on an empty list — the guard would be gone and nothing would say so.
        $routed = $this->routedNames();

        self::assertCount(count(SystemDescriptionProjection::API_OPERATIONS), $routed['operations']);
        self::assertCount(count(SystemDescriptionProjection::API_PROJECTIONS), $routed['projections']);
    }

    public function testDescribesItselfWithoutParametersAndNamesItsOwnLimits(): void
    {
        /** @var array<string, mixed> $description */
        $description = $this->freshOps()->project('systemDescription', []);

        self::assertIsString($description['formatVersion'] ?? null);
        self::assertIsArray($description['invariants'] ?? null);
        self::assertGreaterThan(5, count($description['invariants']));

        /** @var list<string> $notProvided */
        $notProvided = $description['notProvided'];
        $mentionsTheActorLimit = array_filter($notProvided, static fn (string $l): bool => str_contains($l, 'never verified'));
        self::assertNotEmpty($mentionsTheActorLimit);

        /** @var array<string, mixed> $auditTrail */
        $auditTrail = $description['auditTrail'];
        self::assertFalse($auditTrail['actorIsAuthenticated']);
    }
}
