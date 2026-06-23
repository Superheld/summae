<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Composition;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
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
        'releaseCosting', 'lockAccount', 'importChartOfAccounts', 'importMapping',
    ];

    /** @var list<string> */
    private const PROJECTIONS = [
        'trialBalance', 'openItems', 'accountSheet', 'auditLog', 'assetRegister',
        'costAllocationSheet', 'ecSalesList', 'incomeStatement', 'balanceSheet', 'vatReturn',
        'cashBasisReport', 'journalExport', 'datevExport',
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
}
