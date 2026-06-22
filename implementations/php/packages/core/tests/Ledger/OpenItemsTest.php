<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Ledger;

use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\OpenItemKind;
use Summae\Core\Substrate\OpenItemStatus;
use Summae\Core\Policies\Projection\OpenItemsProjection;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;

final class OpenItemsTest extends LedgerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant->accounts->add(new Account(
            $this->tenant->ids->next(),
            AccountNumber::of('1400'),
            'Forderungen',
            AccountType::Asset,
            'ar',
        ));
    }

    public function testArDebitCreatesReceivable(): void
    {
        $result = $this->tenant->ledger->post($this->draft([
            ['1400', 'debit', '1190.00'],
            ['8400', 'credit', '1190.00'],
        ], entryDate: '2026-02-01'));

        self::assertCount(1, $result->openItemsCreated);
        $item = $result->openItemsCreated[0];
        self::assertSame(OpenItemKind::Receivable, $item->kind);
        self::assertSame('1190.00', $item->money->amountAsString());
        self::assertSame(OpenItemStatus::Open, $item->status());
    }

    public function testPaymentCreditOnArCreatesNoItem(): void
    {
        $result = $this->tenant->ledger->post($this->draft([
            ['1200', 'debit', '500.00'],
            ['1400', 'credit', '500.00'],
        ], entryDate: '2026-02-15'));

        self::assertSame([], $result->openItemsCreated);
    }

    public function testPartialThenFullSettlementWithTimeTravel(): void
    {
        $ledger = $this->tenant->ledger;

        $invoice = $ledger->post($this->draft([
            ['1400', 'debit', '1190.00'],
            ['8400', 'credit', '1190.00'],
        ], entryDate: '2026-02-01'));
        $item = $invoice->openItemsCreated[0];

        $partial = $ledger->post($this->draft([
            ['1200', 'debit', '500.00'],
            ['1400', 'credit', '500.00'],
        ], entryDate: '2026-02-15'))->entry;

        $affected = $ledger->settle(['entryId' => $partial->id->value, 'allocations' => [
            ['openItemId' => $item->id->value, 'money' => ['amount' => '500.00', 'currency' => 'EUR']],
        ]]);

        self::assertSame('690.00', $affected[0]->remaining()->amountAsString());
        self::assertSame(OpenItemStatus::PartiallySettled, $affected[0]->status());

        $rest = $ledger->post($this->draft([
            ['1200', 'debit', '690.00'],
            ['1400', 'credit', '690.00'],
        ], entryDate: '2026-03-01'))->entry;

        // Überzahlung abgewiesen — Posten unverändert.
        $this->expectDomainError('E_SETTLEMENT_EXCEEDS_ITEM', static fn () => $ledger->settle([
            'entryId' => $rest->id->value,
            'allocations' => [['openItemId' => $item->id->value, 'money' => ['amount' => '700.00', 'currency' => 'EUR']]],
        ]));
        self::assertSame('690.00', $item->remaining()->amountAsString());

        $ledger->settle(['entryId' => $rest->id->value, 'allocations' => [
            ['openItemId' => $item->id->value, 'money' => ['amount' => '690.00', 'currency' => 'EUR']],
        ]]);
        self::assertSame(OpenItemStatus::Settled, $item->status());

        // Zeitreise: remainingAt Mitte Februar = nach Teilzahlung.
        self::assertSame('690.00', $item->remainingAt(CalendarDate::of('2026-02-20'))->amountAsString());

        $projection = new OpenItemsProjection($this->tenant->openItems, $this->tenant->vouchers, $this->tenant->journal);
        $atFeb = $projection->compute(['asOf' => '2026-02-20', 'kind' => 'receivable']);
        self::assertCount(1, $atFeb['items']);
        $atYearEnd = $projection->compute(['asOf' => '2026-12-31', 'kind' => 'receivable']);
        self::assertSame([], $atYearEnd['items']);
    }

    public function testDifferenceValidation(): void
    {
        $ledger = $this->tenant->ledger;
        $invoice = $ledger->post($this->draft([
            ['1400', 'debit', '1190.00'],
            ['8400', 'credit', '1190.00'],
        ], entryDate: '2026-04-01'));
        $item = $invoice->openItemsCreated[0];

        $payment = $ledger->post($this->draft([
            ['1200', 'debit', '1166.20'],
            ['1400', 'credit', '1166.20'],
        ], entryDate: '2026-04-09'))->entry;

        // Unbekannte Differenzart.
        $this->expectDomainError('E_SETTLEMENT_DIFFERENCE_INVALID', static fn () => $ledger->settle([
            'entryId' => $payment->id->value,
            'allocations' => [[
                'openItemId' => $item->id->value,
                'money' => ['amount' => '1190.00', 'currency' => 'EUR'],
                'difference' => ['money' => ['amount' => '23.80', 'currency' => 'EUR'], 'kind' => 'phantasie'],
            ]],
        ]));

        // Differenz > Restbetrag.
        $this->expectDomainError('E_SETTLEMENT_DIFFERENCE_INVALID', static fn () => $ledger->settle([
            'entryId' => $payment->id->value,
            'allocations' => [[
                'openItemId' => $item->id->value,
                'money' => ['amount' => '1190.00', 'currency' => 'EUR'],
                'difference' => ['money' => ['amount' => '1200.00', 'currency' => 'EUR'], 'kind' => 'discount'],
            ]],
        ]));

        // Gültiger Skonto-Ausgleich: voll ausgeglichen.
        $affected = $ledger->settle([
            'entryId' => $payment->id->value,
            'allocations' => [[
                'openItemId' => $item->id->value,
                'money' => ['amount' => '1190.00', 'currency' => 'EUR'],
                'difference' => ['money' => ['amount' => '23.80', 'currency' => 'EUR'], 'kind' => 'discount'],
            ]],
        ]);

        self::assertSame(OpenItemStatus::Settled, $affected[0]->status());
        self::assertSame('0.00', $affected[0]->remaining()->amountAsString());
    }

    public function testUnknownOpenItem(): void
    {
        $ledger = $this->tenant->ledger;
        $entry = $ledger->post($this->draft([
            ['1200', 'debit', '10.00'],
            ['8400', 'credit', '10.00'],
        ]))->entry;

        $this->expectDomainError('E_OPENITEM_UNKNOWN', static fn () => $ledger->settle([
            'entryId' => $entry->id->value,
            'allocations' => [['openItemId' => '00000000-0000-7000-8000-000000000000', 'money' => ['amount' => '10.00', 'currency' => 'EUR']]],
        ]));
    }
}
