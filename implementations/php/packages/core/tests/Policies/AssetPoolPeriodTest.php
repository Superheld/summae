<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Policies;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\DomainError;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * F-004: the low-value-asset pool period is pack data, not core code.
 *
 * Until v0.6 the core wrote a pooled asset off over a hard-coded five years — one jurisdiction's
 * rule sitting in the law-free substrate, which every other jurisdiction with a pooled de-minimis
 * regime would have inherited without ever saying so. The conformance fixture `gwg-pool-period`
 * pins the same behaviour across both languages; this test covers the half a fixture cannot reach:
 * what happens when the rule data opens a pool range but forgets to say over how long.
 *
 * Node twin: `packages/core/test/asset-pool-period.test.ts`.
 */
final class AssetPoolPeriodTest extends TestCase
{
    private const array POOL_RANGE = [
        'validFrom' => '2018-01-01',
        'validTo' => null,
        'immediateMax' => '250.00',
        'poolMin' => '250.01',
        'poolMax' => '1000.00',
    ];

    /**
     * @param array<string, mixed> $threshold
     */
    private function opsWith(array $threshold): TenantOperations
    {
        $clock = FixedClock::at('2026-06-08T12:00:00+02:00');
        $tenant = Tenant::inMemory('Pool', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
        $tenant->assetService->setRuleModule([
            'gwgThresholds' => [$threshold],
            'usefulLife' => [['assetClass' => 'it-hardware', 'months' => 36]],
            'assetAccounts' => [
                'acquisitionCounterAccount' => '1200',
                'depreciationExpenseAccount' => '4830',
                'gwgExpenseAccount' => '4855',
            ],
        ]);

        $ops = new TenantOperations($tenant);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        foreach ([
            ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
            ['number' => '0480', 'name' => 'Pool', 'type' => 'asset', 'subtype' => 'fixed_asset'],
            ['number' => '4830', 'name' => 'Depreciation', 'type' => 'expense'],
            ['number' => '4855', 'name' => 'Low-value write-off', 'type' => 'expense'],
        ] as $account) {
            $ops->execute('createAccount', $account);
        }

        return $ops;
    }

    /**
     * @return array<string, mixed>
     */
    private function acquire(TenantOperations $ops): array
    {
        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'POOL-1', 'voucherDate' => '2026-01-01'],
        ]);

        return $ops->execute('acquireAsset', [
            'name' => 'Pooled batch',
            'assetClass' => 'it-hardware',
            'assetAccount' => '0480',
            'acquisitionCost' => ['amount' => '900.00', 'currency' => 'EUR'],
            'acquiredOn' => '2026-01-01',
            'voucherId' => is_string($voucher['id'] ?? null) ? $voucher['id'] : '',
            'gwgChoice' => 'auto',
        ]);
    }

    public function testSpreadsTheAssetOverExactlyTheYearsTheRuleDataDeclares(): void
    {
        $result = $this->acquire($this->opsWith(self::POOL_RANGE + ['poolYears' => 3]));

        self::assertSame('pool', $result['route'] ?? null);
        // 3 × 12, not the 60 months the core used to impose.
        self::assertSame(36, $result['usefulLifeMonths'] ?? null);
    }

    public function testRefusesAPoolRangeThatDoesNotSayOverHowLong(): void
    {
        // Not defaulted to five: choosing a number here would put a statute back into the core, which
        // is the finding itself. The schema requires the field next to poolMax, so this path is only
        // reachable with hand-fed rule data that never went through a pack.
        try {
            $this->acquire($this->opsWith(self::POOL_RANGE));
            self::fail('DomainError E_PACK_INCOHERENT expected');
        } catch (DomainError $error) {
            self::assertSame('E_PACK_INCOHERENT', $error->errorCode);
            self::assertStringContainsString('poolYears', $error->getMessage());
        }
    }
}
