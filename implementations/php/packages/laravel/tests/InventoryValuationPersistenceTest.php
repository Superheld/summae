<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Tenant;

/**
 * The same shape of test as `CostingRunPersistenceTest`, for the same reason (F-CORE-050).
 *
 * A valuation is the *record of how a stock figure was reached*, and a record that lives in the
 * process which made it is not a record. So: write with one tenant instance, read with a second on
 * the same connection, and nothing in between may come from the object graph.
 *
 * The third test is the one worth having. `valuateInventory` posts the **difference** against the
 * current book value, and the next version comes out of the store — so a second valuation of an
 * unchanged period must book nothing *across a process boundary too*. With a counter that lived in
 * the service it would have come back as version 1 and booked the full amount a second time, which
 * on a balance sheet means the stock is there twice.
 *
 * Node twin: `packages/knex/test/adapter.test.ts`, "inventory valuations survive the process".
 */
final class InventoryValuationPersistenceTest extends AdapterTestCase
{
    private const string TENANT_A = '0195f000-0000-7000-8000-00000000cccc';
    private const string TENANT_B = '0195f000-0000-7000-8000-00000000dddd';

    private function seedInventory(Tenant $tenant, bool $fresh = true): TenantOperations
    {
        $ops = new TenantOperations($tenant);

        // Only on the first instance: the year and the accounts are in the database, which is the
        // whole point — a second tenant object on the same connection reads them rather than
        // creating them.
        if ($fresh) {
            $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
            $ops->execute('createAccount', ['number' => '1120', 'name' => 'Fertige Erzeugnisse', 'type' => 'asset', 'subtype' => 'inventory']);
            $ops->execute('createAccount', ['number' => '4100', 'name' => 'Bestandsveränderungen', 'type' => 'revenue']);
        }

        // The pack data is NOT in the database — it arrives with the pack on every open, which is
        // why every factory injects it and why this test has to as well.
        $tenant->inventory?->setRuleModule([
            'inventory' => ['categories' => [['account' => '1120', 'changeAccount' => '4100']]],
        ]);

        return $ops;
    }

    /** @return array<string, mixed> */
    private function valuate(TenantOperations $ops): array
    {
        /** @var array<string, mixed> $result */
        $result = $ops->execute('valuateInventory', [
            'fiscalYear' => 2026,
            'period' => 12,
            'valuationDate' => '2026-12-31',
            'categories' => [['account' => '1120', 'quantity' => '400', 'unitCost' => '12.50']],
        ]);

        return $result;
    }

    public function testReadsAValuationBackThroughASecondTenantInstance(): void
    {
        $this->valuate($this->seedInventory($this->tenantOn(Uuid::fromString(self::TENANT_A))));

        $reader = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        /** @var array<string, mixed> $report */
        $report = $reader->project('inventoryValuation', []);
        /** @var list<array<string, mixed>> $valuations */
        $valuations = $report['valuations'] ?? [];

        self::assertCount(1, $valuations);
        self::assertSame('5000.00', $valuations[0]['closingTotal'] ?? null);
        self::assertSame('5000.00', $valuations[0]['change'] ?? null);
        self::assertSame(1, $valuations[0]['version'] ?? null);
        self::assertSame('2026-12-31', $valuations[0]['valuationDate'] ?? null);
        // Every detail of the act, back through a column: the quantity is not Money and must
        // survive as the string it was given.
        /** @var list<array<string, mixed>> $categories */
        $categories = $valuations[0]['categories'] ?? [];
        self::assertSame('400', $categories[0]['quantity'] ?? null);
        self::assertSame('input', $categories[0]['source'] ?? null);
        self::assertSame('4100', $categories[0]['changeAccount'] ?? null);
        self::assertIsString($valuations[0]['entryId'] ?? null);
    }

    public function testASecondValuationOfAnUnchangedPeriodBooksNothingAcrossAProcessBoundary(): void
    {
        $this->valuate($this->seedInventory($this->tenantOn(Uuid::fromString(self::TENANT_A))));

        $second = $this->valuate($this->seedInventory($this->tenantOn(Uuid::fromString(self::TENANT_A)), fresh: false));

        self::assertSame(2, $second['version'] ?? null);
        self::assertFalse($second['posted'] ?? null);
        self::assertNull($second['entryId'] ?? null);
    }

    public function testKeepsOneTenantsValuationsOutOfAnothers(): void
    {
        $this->valuate($this->seedInventory($this->tenantOn(Uuid::fromString(self::TENANT_A))));

        $other = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_B), 'Andere GmbH'));
        /** @var array<string, mixed> $report */
        $report = $other->project('inventoryValuation', []);

        self::assertSame([], $report['valuations'] ?? null);
    }
}
