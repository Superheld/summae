<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Tests\Ledger;

use Rechnungswesen\Core\Ledger\EntryStatus;

final class PostTest extends LedgerTestCase
{
    public function testValidPostGetsSequencePerFiscalYear(): void
    {
        $ledger = $this->tenant->ledger;

        $first = $ledger->post($this->draft([
            ['1200', 'debit', '119.00'],
            ['8400', 'credit', '119.00'],
        ]))->entry;

        $second = $ledger->post($this->draft([
            ['1200', 'debit', '59.50'],
            ['8400', 'credit', '59.50'],
        ]))->entry;

        self::assertSame(1, $first->sequenceNumber);
        self::assertSame(2, $second->sequenceNumber);
        self::assertSame(EntryStatus::Entered, $first->status());
        self::assertSame(2026, $first->periodRef->fiscalYear);
        self::assertSame(3, $first->periodRef->period);
    }

    public function testCheckOrderStructureBeforeReferences(): void
    {
        // Nur 1 Position UND unbekanntes Konto: Struktur gewinnt (api.md).
        $this->expectDomainError('E_ENTRY_TOO_FEW_LINES', fn () => $this->tenant->ledger->post(
            $this->draft([['4711', 'debit', '10.00']]),
        ));
    }

    public function testCheckOrderAmountBeforeAccount(): void
    {
        // Negativer Betrag UND unbekanntes Konto: E_ENTRY_INVALID_AMOUNT gewinnt.
        $this->expectDomainError('E_ENTRY_INVALID_AMOUNT', fn () => $this->tenant->ledger->post(
            $this->draft([
                ['4711', 'debit', '-5.00'],
                ['1200', 'credit', '-5.00'],
            ]),
        ));
    }

    public function testCheckOrderVoucherBeforeAccount(): void
    {
        // Kein Beleg UND unbekanntes Konto: Beleg gewinnt (Referenzen-Reihenfolge).
        $input = $this->draft([
            ['4711', 'debit', '10.00'],
            ['1200', 'credit', '10.00'],
        ]);
        $input['voucherId'] = null;

        $this->expectDomainError('E_ENTRY_NO_VOUCHER', fn () => $this->tenant->ledger->post($input));
    }

    public function testCheckOrderBalanceBeforePeriod(): void
    {
        // Unbalanciert UND Datum außerhalb der Geschäftsjahre: Bilanzgleichung gewinnt.
        $this->expectDomainError('E_ENTRY_UNBALANCED', fn () => $this->tenant->ledger->post(
            $this->draft([
                ['1200', 'debit', '10.00'],
                ['8400', 'credit', '9.00'],
            ], entryDate: '2030-01-01'),
        ));
    }

    public function testScaleViolationIsInvalidAmount(): void
    {
        $this->expectDomainError('E_ENTRY_INVALID_AMOUNT', fn () => $this->tenant->ledger->post(
            $this->draft([
                ['1200', 'debit', '10.001'],
                ['8400', 'credit', '10.001'],
            ]),
        ));
    }

    public function testForeignCurrencyIsInvalidAmountInV1(): void
    {
        $input = $this->draft([
            ['1200', 'debit', '10.00'],
            ['8400', 'credit', '10.00'],
        ]);
        $input['lines'] = [
            ['account' => '1200', 'side' => 'debit', 'money' => ['amount' => '10.00', 'currency' => 'USD']],
            ['account' => '8400', 'side' => 'credit', 'money' => ['amount' => '10.00', 'currency' => 'EUR']],
        ];

        $this->expectDomainError('E_ENTRY_INVALID_AMOUNT', fn () => $this->tenant->ledger->post($input));
    }

    public function testLockedAccountRejected(): void
    {
        $this->expectDomainError('E_ACCOUNT_LOCKED', fn () => $this->tenant->ledger->post(
            $this->draft([
                ['0999', 'debit', '10.00'],
                ['1200', 'credit', '10.00'],
            ]),
        ));
    }

    public function testDateOutsideFiscalYearsIsPeriodUnknown(): void
    {
        $this->expectDomainError('E_PERIOD_UNKNOWN', fn () => $this->tenant->ledger->post(
            $this->draft([
                ['1200', 'debit', '10.00'],
                ['8400', 'credit', '10.00'],
            ], entryDate: '2025-12-30'),
        ));
    }

    public function testClosedPeriodRejectsPost(): void
    {
        $this->tenant->ledger->closePeriod(['fiscalYear' => 2026, 'period' => 1]);

        $this->expectDomainError('E_PERIOD_CLOSED', fn () => $this->tenant->ledger->post(
            $this->draft([
                ['1200', 'debit', '10.00'],
                ['8400', 'credit', '10.00'],
            ], entryDate: '2026-01-15'),
        ));
    }

    public function testUnknownDimensionValueRejected(): void
    {
        $input = $this->draft([
            ['4930', 'debit', '10.00'],
            ['1200', 'credit', '10.00'],
        ]);
        $input['lines'] = [
            [
                'account' => '4930',
                'side' => 'debit',
                'money' => ['amount' => '10.00', 'currency' => 'EUR'],
                'dimensions' => [['type' => 'costCenter', 'code' => 'ZZ']],
            ],
            ['account' => '1200', 'side' => 'credit', 'money' => ['amount' => '10.00', 'currency' => 'EUR']],
        ];

        $this->expectDomainError('E_DIMENSION_INVALID', fn () => $this->tenant->ledger->post($input));
    }
}
