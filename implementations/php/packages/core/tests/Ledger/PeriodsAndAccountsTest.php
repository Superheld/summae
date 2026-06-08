<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Tests\Ledger;

use Rechnungswesen\Core\Ledger\PeriodStatus;

final class PeriodsAndAccountsTest extends LedgerTestCase
{
    public function testPeriodsCloseOnlyInOrder(): void
    {
        $ledger = $this->tenant->ledger;

        $this->expectDomainError('E_PERIOD_OUT_OF_ORDER', static fn () => $ledger->closePeriod([
            'fiscalYear' => 2026,
            'period' => 3,
        ]));

        self::assertSame(PeriodStatus::Closed, $ledger->closePeriod(['fiscalYear' => 2026, 'period' => 1])->status());
        self::assertSame(PeriodStatus::Closed, $ledger->closePeriod(['fiscalYear' => 2026, 'period' => 2])->status());
        self::assertSame(PeriodStatus::Open, $ledger->reopenPeriod(['fiscalYear' => 2026, 'period' => 2])->status());
    }

    public function testCloseFiscalYearRequiresAllPeriodsClosed(): void
    {
        $this->expectDomainError('E_PERIOD_OUT_OF_ORDER', fn () => $this->tenant->ledger->closeFiscalYear([
            'fiscalYear' => 2026,
        ]));
    }

    public function testCloseFiscalYearRequiresFinalizedEntries(): void
    {
        $ledger = $this->tenant->ledger;
        $ledger->post($this->draft([
            ['1200', 'debit', '10.00'],
            ['8400', 'credit', '10.00'],
        ], entryDate: '2026-01-10'));

        for ($period = 1; $period <= 12; $period++) {
            $ledger->closePeriod(['fiscalYear' => 2026, 'period' => $period]);
        }

        // Buchung nicht festgeschrieben -> Abschluss verweigert (v0.5/F-003).
        $this->expectDomainError('E_FISCALYEAR_UNFINALIZED_ENTRIES', static fn () => $ledger->closeFiscalYear([
            'fiscalYear' => 2026,
        ]));

        $ledger->finalize(['finalizeUntil' => '2026-12-31']);
        $ledger->closeFiscalYear(['fiscalYear' => 2026]);

        $this->expectDomainError('E_FISCALYEAR_CLOSED', static fn () => $ledger->reopenPeriod([
            'fiscalYear' => 2026,
            'period' => 1,
        ]));
    }

    public function testCreateAccountAndCollision(): void
    {
        $ledger = $this->tenant->ledger;

        $account = $ledger->createAccount(['number' => '8401', 'name' => 'Erlöse Spezial', 'type' => 'revenue']);
        self::assertSame('8401', $account->number->value);
        self::assertSame('active', $account->status()->value);

        $this->expectDomainError('E_ACCOUNT_NUMBER_TAKEN', static fn () => $ledger->createAccount([
            'number' => '8400',
            'name' => 'Kollision',
            'type' => 'revenue',
        ]));
    }

    public function testImportChartOfAccountsAtomic(): void
    {
        $ledger = $this->tenant->ledger;

        $count = $ledger->importChartOfAccounts(['format' => 'datev-csv', 'rows' => [
            ['number' => '1400', 'name' => 'Forderungen', 'type' => 'asset', 'subtype' => 'ar'],
            ['number' => '1600', 'name' => 'Verbindlichkeiten', 'type' => 'liability', 'subtype' => 'ap'],
        ]]);
        self::assertSame(2, $count);

        $this->expectDomainError('E_COA_FORMAT_INVALID', static fn () => $ledger->importChartOfAccounts([
            'format' => 'datev-csv',
            'rows' => [['name' => 'Konto ohne Nummer']],
        ]));
    }

    public function testLockAccountWritesAudit(): void
    {
        $ledger = $this->tenant->ledger;
        $account = $ledger->lockAccount(['actor' => 'admin', 'number' => '4930']);

        self::assertSame('locked', $account->status()->value);

        $records = $this->tenant->audit->all();
        $last = $records[count($records) - 1];
        self::assertSame('locked', $last->action);
        self::assertSame('account', $last->objectType);
        self::assertSame(['status' => ['from' => 'active', 'to' => 'locked']], $last->changes);
    }
}
