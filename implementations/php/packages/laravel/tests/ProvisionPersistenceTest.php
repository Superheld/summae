<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Tenant;

/**
 * A provision outlives the process that formed it, and so does its history (F-CORE-051).
 *
 * The second test is the one that matters. A provision is used, released and re-measured over
 * *years* — the movement list is the record an auditor reads, and a list that only exists in the
 * object graph is not a record at all. So the register is read back through a second tenant
 * instance and the movements have to be there, in order, with their entry references.
 *
 * Node twin: `packages/knex/test/adapter.test.ts`, "provisions survive the process".
 */
final class ProvisionPersistenceTest extends AdapterTestCase
{
    private const string TENANT_A = '0195f000-0000-7000-8000-00000000eeee';
    private const string TENANT_B = '0195f000-0000-7000-8000-00000000ffff';

    private function seedProvisions(Tenant $tenant, bool $fresh = true): TenantOperations
    {
        $ops = new TenantOperations($tenant);

        if ($fresh) {
            $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
            $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
            $ops->execute('createAccount', ['number' => '3600', 'name' => 'Rückstellungen', 'type' => 'liability', 'subtype' => 'provision']);
            $ops->execute('createAccount', ['number' => '4900', 'name' => 'Erträge', 'type' => 'revenue']);
            $ops->execute('createAccount', ['number' => '6800', 'name' => 'Zuführung', 'type' => 'expense']);
        }

        // Pack data is not in the database — it arrives with the pack on every open.
        $tenant->provisionService?->setRuleModule([
            'provisions' => [
                'accounts' => [['account' => '3600', 'expenseAccount' => '6800', 'releaseAccount' => '4900']],
                'discounting' => ['fromMonths' => 12, 'basis' => 'test'],
            ],
        ]);

        return $ops;
    }

    private function recognize(TenantOperations $ops): string
    {
        /** @var array<string, mixed> $result */
        $result = $ops->execute('recognizeProvision', [
            'account' => '3600',
            'reason' => 'Prozessrisiko',
            'amount' => ['amount' => '5000.00', 'currency' => 'EUR'],
            'recognizedOn' => '2026-06-30',
        ]);

        $id = $result['provisionId'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    public function testReadsAProvisionAndItsHistoryBackThroughASecondTenantInstance(): void
    {
        $ops = $this->seedProvisions($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        $id = $this->recognize($ops);
        $ops->execute('useProvision', [
            'provisionId' => $id,
            'amount' => ['amount' => '2000.00', 'currency' => 'EUR'],
            'settlementAccount' => '1200',
            'date' => '2026-09-30',
        ]);

        $reader = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        /** @var array<string, mixed> $register */
        $register = $reader->project('provisionRegister', []);
        /** @var list<array<string, mixed>> $rows */
        $rows = $register['provisions'] ?? [];

        self::assertCount(1, $rows);
        self::assertSame('3000.00', $rows[0]['carryingAmount'] ?? null);
        self::assertSame('open', $rows[0]['status'] ?? null);
        self::assertSame('3000.00', $register['total'] ?? null);

        /** @var list<array<string, mixed>> $movements */
        $movements = $rows[0]['movements'] ?? [];
        self::assertCount(2, $movements, 'the history is the record — it must survive the process');
        self::assertSame(['recognized', 'used'], array_column($movements, 'kind'));
        self::assertIsString($movements[1]['entryId'] ?? null, 'every movement names the entry it produced');
    }

    public function testContinuesTheHistoryFromASecondInstanceRatherThanStartingOver(): void
    {
        $id = $this->recognize($this->seedProvisions($this->tenantOn(Uuid::fromString(self::TENANT_A))));

        $second = $this->seedProvisions($this->tenantOn(Uuid::fromString(self::TENANT_A)), fresh: false);
        /** @var array<string, mixed> $released */
        $released = $second->execute('releaseProvision', ['provisionId' => $id, 'date' => '2026-12-31']);

        // The carrying amount came out of the store, not out of a fresh object at its original
        // value — which is what a provision service keeping its own map would have done.
        $amount = $released['released'] ?? null;
        self::assertIsArray($amount);
        self::assertSame('5000.00', $amount['amount'] ?? null);
        self::assertSame('settled', $released['status'] ?? null);
    }

    public function testKeepsOneTenantsProvisionsOutOfAnothers(): void
    {
        $this->recognize($this->seedProvisions($this->tenantOn(Uuid::fromString(self::TENANT_A))));

        $other = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_B), 'Andere GmbH'));
        /** @var array<string, mixed> $register */
        $register = $other->project('provisionRegister', []);

        self::assertSame([], $register['provisions'] ?? null);
    }
}
