<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Composition;

use PHPUnit\Framework\TestCase;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Policies\Expansion\Tax\TaxCodeRegistry;
use Summae\Core\Policies\Expansion\Tax\TaxProfile;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\DeterministicIdGenerator;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Tenant;

/**
 * #29 / NF-004/F-010: a tax-exempt sale must be postable. A plain rate-0 *standard* code
 * expands to a 0.00 tax line, which the ledger rejects (E_ENTRY_INVALID_AMOUNT). The `exempt`
 * mechanism (a built-in strategy in the tax-mechanism registry) emits NO tax line, so the
 * voucher posts cleanly with gross = net. Built-in = the "closed" path; no closed/open decision
 * needed. Wiring a shipped pack's exempt code to this mechanism + a conformance fixture is the
 * remaining knowledge-base step.
 */
final class ExemptMechanismTest extends TestCase
{
    public function testPostsAnExemptSaleWithoutARejectedZeroTaxLine(): void
    {
        $clock = FixedClock::at('2026-06-08T12:00:00+02:00');
        $tenant = Tenant::inMemory(
            'Exempt',
            Currency::of('EUR'),
            $clock,
            new DeterministicIdGenerator($clock),
            null,
            TaxCodeRegistry::fromData([[
                'code' => 'EXEMPT',
                'versions' => [[
                    'validFrom' => '2024-01-01', 'validTo' => null, 'rate' => '0.00',
                    'taxAccount' => '2100', 'mechanism' => 'exempt',
                ]],
            ]]),
            TaxProfile::default(),
        );
        $ops = new TenantOperations($tenant);
        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $ops->execute('createAccount', ['number' => '1200', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'bank']);
        $ops->execute('createAccount', ['number' => '8400', 'name' => 'Revenue', 'type' => 'revenue']);
        $ops->execute('createAccount', ['number' => '2100', 'name' => 'Sales tax', 'type' => 'liability', 'subtype' => 'tax_out']);

        // The old behaviour (rate-0 standard code) threw E_ENTRY_INVALID_AMOUNT on the 0.00 line.
        $ops->execute('postVoucher', [
            'voucher' => ['voucherNumber' => 'EX-1', 'voucherDate' => '2026-01-15'],
            'entryDate' => '2026-01-15',
            'text' => 'Exempt sale',
            'taxCode' => 'EXEMPT',
            'direction' => 'output',
            'netLines' => [['account' => '8400', 'money' => ['amount' => '100.00', 'currency' => 'EUR']]],
            'counterAccount' => '1200',
        ]);

        // Exactly one entry was posted, and it balanced at net (no tax line was generated).
        self::assertCount(1, $tenant->journal->all());
    }
}
