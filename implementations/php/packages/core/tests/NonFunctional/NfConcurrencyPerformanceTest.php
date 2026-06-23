<?php

declare(strict_types=1);

namespace Summae\Core\Tests\NonFunctional;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Substrate\JournalEntry;
use Summae\Core\Tenant;

/**
 * Dedicated tests for the two non-functional requirements that a behavioral fixture
 * cannot express (quality-gate obligation 3):
 *
 * - NF-6 (concurrency): the journal assigns unique, gapless, monotonic sequence numbers.
 *   The framework-free in-memory core is single-threaded; OS-level concurrent writing is
 *   the persistence adapter's concern (documented in the handbook). What is testable —
 *   and what NF-6 protects — is that the sequence allocation never duplicates or skips.
 * - NF-7 (performance): a realistic bulk load (10k postings) plus the two heaviest
 *   journal projections stays well within budget. The bound is generous (>>10x the
 *   expected time) on purpose: it must never flake on a loaded CI runner, only catch a
 *   catastrophic regression (e.g. an accidental O(n²) in posting or a projection).
 */
final class NfConcurrencyPerformanceTest extends TestCase
{
    /**
     * @return array{tenant: Tenant, ops: TenantOperations, voucherId: string}
     */
    private function bulkTenant(): array
    {
        $clock = FixedClock::at('2026-06-08T12:00:00+02:00');
        $tenant = Tenant::inMemory(
            'NF',
            Currency::of('EUR'),
            $clock,
            new DeterministicIdGenerator($clock),
            null,
            TaxCodeRegistry::fromData([[
                'code' => 'USt19',
                'versions' => [[
                    'validFrom' => '2024-01-01', 'validTo' => null, 'rate' => '19.00',
                    'taxAccount' => '1776', 'reportingKey' => '81',
                ]],
            ]]),
            TaxProfile::default(),
        );
        $ops = new TenantOperations($tenant);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $ops->execute('importChartOfAccounts', ['format' => 'datev-csv', 'rows' => [
            ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank'],
            ['number' => '8400', 'name' => 'Revenue 19%', 'type' => 'revenue'],
            ['number' => '1776', 'name' => 'VAT 19%', 'type' => 'liability', 'subtype' => 'tax_out'],
        ]]);
        $voucher = $ops->execute('postVoucher', [
            'voucher' => ['voucherNumber' => 'NF-V', 'voucherDate' => '2026-01-02'],
            'entryDate' => '2026-01-02',
            'text' => 'Voucher for bulk postings',
            'taxCode' => 'USt19',
            'direction' => 'output',
            'netLines' => [['account' => '8400', 'money' => ['amount' => '1.00', 'currency' => 'EUR']]],
            'counterAccount' => '1200',
        ]);
        $voucherId = is_string($voucher['voucherId'] ?? null) ? $voucher['voucherId'] : '';

        return ['tenant' => $tenant, 'ops' => $ops, 'voucherId' => $voucherId];
    }

    private function postBulk(Tenant $tenant, string $voucherId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $tenant->ledger->post([
                'entryDate' => '2026-0' . (($i % 9) + 1) . '-15',
                'voucherId' => $voucherId,
                'text' => 'Bulk posting',
                'lines' => [
                    ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
                    ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
                    ['account' => '1776', 'side' => 'credit', 'money' => ['amount' => '19.00', 'currency' => 'EUR']],
                ],
            ]);
        }
    }

    public function testAssignsUniqueGaplessMonotonicSequenceNumbers(): void
    {
        ['tenant' => $tenant, 'voucherId' => $voucherId] = $this->bulkTenant();
        $this->postBulk($tenant, $voucherId, 1000);

        $seqs = array_map(static fn (JournalEntry $e): int => $e->sequenceNumber, $tenant->journal->all());
        // journal->all() is ordered by (fiscalYear, sequenceNumber); all entries are in the
        // same fiscal year, so the numbers must be exactly 1..N — proves unique + gapless +
        // monotonic in one assertion.
        self::assertSame(range(1, count($seqs)), $seqs);
    }

    public function testBulkPostingAndProjectionsWithinBudget(): void
    {
        ['tenant' => $tenant, 'ops' => $ops, 'voucherId' => $voucherId] = $this->bulkTenant();

        $start = hrtime(true);
        $this->postBulk($tenant, $voucherId, 10_000);
        $ops->project('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => 12]);
        $ops->project('cashBasisReport', ['year' => 2026, 'asOf' => '2026-12-31']);
        $elapsedMs = (hrtime(true) - $start) / 1e6;

        self::assertLessThan(10_000, $elapsedMs, sprintf(
            '10k postings + trialBalance + cashBasisReport took %dms (budget 10000ms)',
            (int) $elapsedMs,
        ));
    }
}
