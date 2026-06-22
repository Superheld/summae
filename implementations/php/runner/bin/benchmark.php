<?php

declare(strict_types=1);

/**
 * NF-7-Messung (JOB-014): Einzelbuchung inkl. Tax-Expansion (NF-7.1),
 * trialBalance über ein Geschäftsjahr mit 100.000 Buchungen (NF-7.2),
 * In-Memory-Port (ohne Adapter-I/O, wie NF-7.1 es definiert).
 */

use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Tax\TaxCodeRegistry;
use Summae\Core\Tax\TaxProfile;
use Summae\Core\Tenant;

require __DIR__ . '/../../vendor/autoload.php';

$clock = FixedClock::at('2026-06-08T12:00:00+02:00');
$tenant = Tenant::inMemory(
    'Benchmark',
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
    ['number' => '8400', 'name' => 'Erlöse 19%', 'type' => 'revenue'],
    ['number' => '1776', 'name' => 'USt 19%', 'type' => 'liability', 'subtype' => 'tax_out'],
]]);

$count = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 100_000;

// NF-7.1: Einzelbuchung über die volle Komposition (postVoucher).
$start = hrtime(true);
$ops->execute('postVoucher', [
    'voucher' => ['voucherNumber' => 'BENCH-0', 'voucherDate' => '2026-01-15'],
    'entryDate' => '2026-01-15',
    'text' => 'Benchmark-Einzelbuchung',
    'taxCode' => 'USt19',
    'direction' => 'output',
    'netLines' => [['account' => '8400', 'money' => ['amount' => '100.00', 'currency' => 'EUR']]],
    'counterAccount' => '1200',
]);
$singleMs = (hrtime(true) - $start) / 1e6;

// Massendaten: direkte post-Aufrufe (Tax vorab expandiert wäre realistisch;
// hier konservativ mit voller Prüfreihenfolge je Buchung).
$voucherResult = $ops->execute('postVoucher', [
    'voucher' => ['voucherNumber' => 'BENCH-V', 'voucherDate' => '2026-01-02'],
    'entryDate' => '2026-01-02',
    'text' => 'Beleg für Massenbuchungen',
    'taxCode' => 'USt19',
    'direction' => 'output',
    'netLines' => [['account' => '8400', 'money' => ['amount' => '1.00', 'currency' => 'EUR']]],
    'counterAccount' => '1200',
]);
$voucherId = $voucherResult['voucherId'];
assert(is_string($voucherId));

$start = hrtime(true);
for ($i = 0; $i < $count; $i++) {
    $tenant->ledger->post([
        'entryDate' => '2026-0' . (($i % 9) + 1) . '-15',
        'voucherId' => $voucherId,
        'text' => 'Massenbuchung',
        'lines' => [
            ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '119.00', 'currency' => 'EUR']],
            ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
            ['account' => '1776', 'side' => 'credit', 'money' => ['amount' => '19.00', 'currency' => 'EUR']],
        ],
    ]);
}
$postSeconds = (hrtime(true) - $start) / 1e9;

// NF-7.2: SuSa über das volle Jahr.
$start = hrtime(true);
$result = $ops->project('trialBalance', ['fiscalYear' => 2026, 'throughPeriod' => 12]);
$trialBalanceSeconds = (hrtime(true) - $start) / 1e9;

// EÜR-Projektion über denselben Bestand.
$start = hrtime(true);
$ops->project('cashBasisReport', ['year' => 2026, 'asOf' => '2026-12-31']);
$cashBasisSeconds = (hrtime(true) - $start) / 1e9;

echo json_encode([
    'entries' => $count + 2,
    'nf71_single_postVoucher_ms' => round($singleMs, 2),
    'bulk_post_total_s' => round($postSeconds, 2),
    'bulk_post_per_entry_ms' => round($postSeconds * 1000 / max(1, $count), 3),
    'nf72_trialBalance_s' => round($trialBalanceSeconds, 2),
    'nf72_cashBasisReport_s' => round($cashBasisSeconds, 2),
    'trialBalance_rows' => count((array) $result['rows']),
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
