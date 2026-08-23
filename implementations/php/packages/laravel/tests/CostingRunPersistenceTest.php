<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Tenant;

/**
 * The finding this port exists for (F-KLR-001/004): a costing run created in one process used to be
 * gone in the next, because the service kept it in a private array. An application that builds a
 * tenant per request could therefore release a run and never read it again — and
 * `costAllocationSheet` needs the runId, so there was no way to have a valid one.
 *
 * The tests are the shape of the defect: write with one tenant instance, read with a second one on
 * the same connection, and nothing in between may come from the object graph.
 *
 * Node twin: `packages/knex/test/adapter.test.ts`, "costing runs survive the process".
 */
final class CostingRunPersistenceTest extends AdapterTestCase
{
    private const string TENANT_A = '0195f000-0000-7000-8000-00000000aaaa';
    private const string TENANT_B = '0195f000-0000-7000-8000-00000000bbbb';

    private function seedCosting(Tenant $tenant): string
    {
        $ops = new TenantOperations($tenant);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
        $ops->execute('createAccount', ['number' => '6000', 'name' => 'Aufwand', 'type' => 'expense']);
        $ops->execute('defineDimensionType', ['code' => 'costCenter']);
        $ops->execute('defineDimensionValue', ['type' => 'costCenter', 'code' => 'K100']);
        $ops->execute('setAllocationScheme', ['method' => 'step_ladder', 'steps' => []]);

        /** @var array<string, mixed> $voucher */
        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'ER-1', 'voucherDate' => '2026-01-20'],
        ]);
        self::assertIsString($voucher['id'] ?? null);

        $ops->execute('post', [
            'entryDate' => '2026-01-20',
            'voucherId' => $voucher['id'],
            'text' => 'Kosten der Stelle K100',
            'lines' => [
                [
                    'account' => '6000',
                    'side' => 'debit',
                    'money' => ['amount' => '240.00', 'currency' => 'EUR'],
                    'dimensions' => [['type' => 'costCenter', 'code' => 'K100']],
                ],
                ['account' => '1200', 'side' => 'credit', 'money' => ['amount' => '240.00', 'currency' => 'EUR']],
            ],
        ]);

        /** @var array<string, mixed> $run */
        $run = $ops->execute('runCosting', ['fiscalYear' => 2026, 'period' => 1]);
        self::assertIsString($run['runId'] ?? null);
        $ops->execute('releaseCosting', ['runId' => $run['runId']]);

        return $run['runId'];
    }

    public function testReadsAReleasedRunBackThroughASecondTenantInstance(): void
    {
        $runId = $this->seedCosting($this->tenantOn(Uuid::fromString(self::TENANT_A)));

        $reader = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        /** @var array<string, mixed> $sheet */
        $sheet = $reader->project('costAllocationSheet', ['runId' => $runId]);

        self::assertSame('released', $sheet['status'] ?? null);
        self::assertSame(1, $sheet['version'] ?? null);
        self::assertSame('240.00', $sheet['grandTotal'] ?? null);
        self::assertSame([['costCenter' => 'K100', 'total' => '240.00']], $sheet['primary'] ?? null);
    }

    public function testCountsTheNextVersionFromTheStoreNotFromACounterThatRestarts(): void
    {
        $this->seedCosting($this->tenantOn(Uuid::fromString(self::TENANT_A)));

        // A second run of the SAME period, from a new instance. With the counter that used to live
        // in the service this came back as version 1 — two runs both claiming to be the first.
        $ops = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        /** @var array<string, mixed> $second */
        $second = $ops->execute('runCosting', ['fiscalYear' => 2026, 'period' => 1]);

        self::assertSame(2, $second['version'] ?? null);
    }

    public function testKeepsOneTenantsRunsOutOfAnothers(): void
    {
        $runId = $this->seedCosting($this->tenantOn(Uuid::fromString(self::TENANT_A)));

        $other = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_B), 'Andere GmbH'));

        $this->expectExceptionMessageMatches('/does not exist/');
        $other->project('costAllocationSheet', ['runId' => $runId]);
    }
}
