<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Tenant;

/**
 * The release plan survives the process, and so does the record of what has already run (F-CORE-053).
 *
 * The second test is the reason the port exists. `runDeferralRelease` is idempotent because each
 * deferral *records* which periods it has released — not because it checks a balance. Get that wrong
 * and the second process to run a period books it again, which on a prepaid item means the expense
 * lands twice and the asset goes negative. So: release period 1 with one tenant instance, release it
 * again with a second, and nothing may happen.
 *
 * Node twin: `packages/knex/test/adapter.test.ts`, "deferrals survive the process".
 */
final class DeferralPersistenceTest extends AdapterTestCase
{
    private const string TENANT_A = '0195f000-0000-7000-8000-000000001111';
    private const string TENANT_B = '0195f000-0000-7000-8000-000000002222';

    private function seedDeferrals(Tenant $tenant, bool $fresh = true): TenantOperations
    {
        $ops = new TenantOperations($tenant);

        if ($fresh) {
            $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
            $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
            $ops->execute('createAccount', ['number' => '1900', 'name' => 'Aktive RAP', 'type' => 'asset']);
            $ops->execute('createAccount', ['number' => '6080', 'name' => 'Versicherungen', 'type' => 'expense']);
        }

        $tenant->deferralService?->setRuleModule([
            'deferrals' => ['kinds' => [['kind' => 'prepaidExpense', 'account' => '1900']]],
        ]);

        return $ops;
    }

    private function recognize(TenantOperations $ops): string
    {
        /** @var array<string, mixed> $result */
        $result = $ops->execute('recognizeDeferral', [
            'kind' => 'prepaidExpense',
            'reason' => 'Versicherung',
            'counterAccount' => '6080',
            'amount' => ['amount' => '1200.00', 'currency' => 'EUR'],
            'recognizedOn' => '2026-01-01',
            'firstFiscalYear' => 2026,
            'firstPeriod' => 1,
            'periods' => 12,
        ]);

        $id = $result['deferralId'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    public function testReadsThePlanAndItsProgressBackThroughASecondTenantInstance(): void
    {
        $ops = $this->seedDeferrals($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        $this->recognize($ops);
        $ops->execute('runDeferralRelease', ['fiscalYear' => 2026, 'period' => 1]);

        $reader = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        /** @var array<string, mixed> $register */
        $register = $reader->project('deferralRegister', []);
        /** @var list<array<string, mixed>> $rows */
        $rows = $register['deferrals'] ?? [];

        self::assertCount(1, $rows);
        self::assertSame('100.00', $rows[0]['released'] ?? null);
        self::assertSame('1100.00', $rows[0]['outstanding'] ?? null);

        /** @var list<array<string, mixed>> $plan */
        $plan = $rows[0]['plan'] ?? [];
        self::assertCount(12, $plan, 'the plan is fixed at recognition and must come back whole');
        self::assertTrue($plan[0]['released'] ?? null);
        self::assertFalse($plan[1]['released'] ?? null);
    }

    public function testASecondProcessDoesNotReleaseAPeriodTwice(): void
    {
        $ops = $this->seedDeferrals($this->tenantOn(Uuid::fromString(self::TENANT_A)));
        $this->recognize($ops);
        $ops->execute('runDeferralRelease', ['fiscalYear' => 2026, 'period' => 1]);

        // The whole reason the released periods are stored rather than inferred: a second process
        // that decided from the balance would book period 1 again.
        $second = $this->seedDeferrals($this->tenantOn(Uuid::fromString(self::TENANT_A)), fresh: false);
        /** @var array<string, mixed> $again */
        $again = $second->execute('runDeferralRelease', ['fiscalYear' => 2026, 'period' => 1]);

        self::assertTrue($again['alreadyRun'] ?? null);
        self::assertSame(0, $again['entriesCreated'] ?? null);
    }

    public function testKeepsOneTenantsDeferralsOutOfAnothers(): void
    {
        $this->recognize($this->seedDeferrals($this->tenantOn(Uuid::fromString(self::TENANT_A))));

        $other = new TenantOperations($this->tenantOn(Uuid::fromString(self::TENANT_B), 'Andere GmbH'));
        /** @var array<string, mixed> $register */
        $register = $other->project('deferralRegister', []);

        self::assertSame([], $register['deferrals'] ?? null);
    }
}
