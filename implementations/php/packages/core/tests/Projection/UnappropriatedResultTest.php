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
 * The loss half of `unappropriatedResult`, which the fixtures do not reach.
 *
 * Every appropriation fixture appropriates a profit, because that is what a resolution normally
 * does. The direction rule — the pot decides, the year figure only sizes it (IMPL-033) — is
 * symmetrical, and the cases where it earns its keep are the ones where a year and the pot point
 * different ways. Those are cheaper to build here than as fixtures.
 *
 * The SAME cases live in the Node unappropriated-result.test.ts.
 */
final class UnappropriatedResultTest extends TestCase
{
    public function testReportsTheLossNegativeAndCapsAYearByWhatItLost(): void
    {
        $ops = $this->tenantWithYears();
        $this->book($ops, '2026-06-01', '6000', 'debit', '500.00');
        $this->book($ops, '2027-06-01', '6000', 'debit', '400.00');

        $report = $ops->project('unappropriatedResult', []);

        self::assertSame('-900.00', $report['cumulativeResult']);
        self::assertSame('-900.00', $report['unappropriated']);
        self::assertSame(
            [[2026, '-500.00'], [2027, '-900.00']],
            $this->availablePerYear($report),
        );
    }

    public function testBooksALossTheOtherWayRoundAndLeavesTheRest(): void
    {
        $ops = $this->tenantWithYears();
        $this->book($ops, '2026-06-01', '6000', 'debit', '500.00');
        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'BESCHLUSS-1', 'voucherDate' => '2027-05-20', 'kind' => 'internal'],
        ]);

        $result = $ops->execute('appropriateResult', [
            'fiscalYear' => 2026,
            'entryDate' => '2027-05-20',
            'voucherId' => is_string($voucher['id'] ?? null) ? $voucher['id'] : '',
            'appropriations' => [['target' => 'carryForward', 'money' => ['amount' => '300.00', 'currency' => 'EUR']]],
        ]);

        // Positive amount in, and the direction comes from the books: the allocation account in credit.
        $entry = is_array($result['entry'] ?? null) ? $result['entry'] : [];
        $lines = is_array($entry['lines'] ?? null) ? $entry['lines'] : [];
        self::assertIsArray($lines[0]);
        self::assertSame('2300', $lines[0]['account']);
        self::assertSame('credit', $lines[0]['side']);
        self::assertSame('-200.00', $result['remaining']);
        self::assertSame('-200.00', $ops->project('unappropriatedResult', [])['unappropriated']);
    }

    public function testOffersNothingForAYearWhoseProfitALaterLossHasSwallowed(): void
    {
        $ops = $this->tenantWithYears();
        $this->book($ops, '2026-06-01', '4040', 'credit', '900.00');
        $this->book($ops, '2027-06-01', '6000', 'debit', '1400.00');

        $report = $ops->project('unappropriatedResult', []);

        // The pot is a loss of 500. 2026 earned a profit, so it contributes nothing to appropriate —
        // the loss arose in 2027 and has to be resolved naming 2027.
        self::assertSame('-500.00', $report['unappropriated']);
        self::assertSame(
            [[2026, '0.00'], [2027, '-500.00']],
            $this->availablePerYear($report),
        );
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function availablePerYear(array $report): array
    {
        $rows = is_array($report['byFiscalYear'] ?? null) ? $report['byFiscalYear'] : [];
        $out = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $out[] = [$row['fiscalYear'], $row['available']];
        }

        return $out;
    }

    private function tenantWithYears(): TenantOperations
    {
        $clock = FixedClock::at('2028-01-02T09:00:00+01:00');
        $tenant = Tenant::inMemory('Verlust GmbH', Currency::of('EUR'), $clock, new DeterministicIdGenerator($clock));
        $ops = new TenantOperations($tenant);

        foreach ([2026, 2027] as $year) {
            $ops->execute('createFiscalYear', ['year' => $year, 'start' => $year . '-01-01', 'end' => $year . '-12-31']);
        }
        $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
        $ops->execute('createAccount', ['number' => '2100', 'name' => 'Gewinnvortrag', 'type' => 'equity']);
        $ops->execute('createAccount', [
            'number' => '2300', 'name' => 'Ergebnisverwendung', 'type' => 'equity', 'subtype' => 'result_allocation',
        ]);
        $ops->execute('createAccount', ['number' => '4040', 'name' => 'Erlöse', 'type' => 'revenue']);
        $ops->execute('createAccount', ['number' => '6000', 'name' => 'Aufwand', 'type' => 'expense']);
        $tenant->resultAppropriation->setRuleModule([
            'resultAppropriation' => [
                'allocationAccount' => '2300',
                'targets' => ['carryForward' => ['account' => '2100', 'label' => 'Verlustvortrag']],
            ],
        ]);

        return $ops;
    }

    private function book(TenantOperations $ops, string $date, string $account, string $side, string $amount): void
    {
        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'B-' . $date . '-' . $account, 'voucherDate' => $date],
        ]);
        $ops->execute('post', [
            'entryDate' => $date,
            'voucherId' => is_string($voucher['id'] ?? null) ? $voucher['id'] : '',
            'text' => 'Buchung',
            'lines' => [
                ['account' => $account, 'side' => $side, 'money' => ['amount' => $amount, 'currency' => 'EUR']],
                [
                    'account' => '1200',
                    'side' => $side === 'debit' ? 'credit' : 'debit',
                    'money' => ['amount' => $amount, 'currency' => 'EUR'],
                ],
            ],
        ]);
    }
}
