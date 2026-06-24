<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Projection;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * #32: the AICPA Audit Data Standard (GL) export — US counterpart to journalExport (GoBD-Z3).
 * Mirror of the Node audit-data-export test: the three ADS streams + the signed-amount
 * convention (debit +, credit -).
 */
final class AuditDataExportTest extends TestCase
{
    public function testEmitsTheThreeAdsStreamsWithSignedLineAmounts(): void
    {
        $clock = FixedClock::at('2026-06-08T12:00:00+02:00');
        $tenant = Tenant::inMemory('ADS', Currency::of('USD'), $clock, new DeterministicIdGenerator($clock));
        $ops = new TenantOperations($tenant);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $ops->execute('createAccount', ['number' => '1010', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'bank']);
        $ops->execute('createAccount', ['number' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue']);
        $voucher = $ops->execute('createVoucher', ['voucher' => ['voucherNumber' => 'JE-1', 'voucherDate' => '2026-01-15']]);
        $voucherId = is_string($voucher['id'] ?? null) ? $voucher['id'] : '';
        $ops->execute('post', [
            'entryDate' => '2026-01-15',
            'voucherId' => $voucherId,
            'text' => 'Cash sale',
            'lines' => [
                ['account' => '1010', 'side' => 'debit', 'money' => ['amount' => '100.00', 'currency' => 'USD']],
                ['account' => '4000', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'USD']],
            ],
        ]);

        $result = $ops->project('auditDataExport', ['fiscalYear' => 2026]);

        self::assertSame('aicpa-ads-gl', $result['standard']);
        self::assertSame('USD', $result['currency']);

        $journals = $result['journals'];
        self::assertIsArray($journals);
        self::assertCount(1, $journals);
        $je = $journals[0];
        self::assertIsArray($je);
        self::assertSame('2026-01-15', $je['effectiveDate']);
        self::assertSame('JE-1', $je['source']);

        $lines = $je['glLineItems'];
        self::assertIsArray($lines);
        self::assertCount(2, $lines);
        /** @var list<array<string, mixed>> $lines */
        $amounts = array_column($lines, 'transactionAmount', 'glAccountNumber');
        self::assertSame('100.00', $amounts['1010'] ?? null);   // debit positive
        self::assertSame('-100.00', $amounts['4000'] ?? null);  // credit negative

        $tb = $result['trialBalance'];
        self::assertIsArray($tb);
        /** @var list<array<string, mixed>> $tb */
        $ending = array_column($tb, 'amountEnding', 'glAccountNumber');
        self::assertSame('100.00', $ending['1010'] ?? null);
        self::assertSame('-100.00', $ending['4000'] ?? null);

        $accounts = $result['accounts'];
        self::assertIsArray($accounts);
        /** @var list<array<string, mixed>> $accounts */
        $types = array_column($accounts, 'accountType', 'glAccountNumber');
        $names = array_column($accounts, 'glAccountName', 'glAccountNumber');
        self::assertSame('asset', $types['1010'] ?? null);
        self::assertSame('revenue', $types['4000'] ?? null);
        self::assertSame('Sales Revenue', $names['4000'] ?? null);
    }
}
