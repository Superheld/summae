<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Tests\Projection;

use PHPUnit\Framework\TestCase;
use Rechnungswesen\Core\Ledger\Account;
use Rechnungswesen\Core\Ledger\AccountType;
use Rechnungswesen\Core\Ledger\FiscalYear;
use Rechnungswesen\Core\Ledger\Voucher;
use Rechnungswesen\Core\Projection\AccountSheetProjection;
use Rechnungswesen\Core\Projection\TrialBalanceProjection;
use Rechnungswesen\Core\Shared\AccountNumber;
use Rechnungswesen\Core\Shared\CalendarDate;
use Rechnungswesen\Core\Shared\Currency;
use Rechnungswesen\Core\Shared\FixedClock;
use Rechnungswesen\Core\Shared\Uuid;
use Rechnungswesen\Core\Shared\UuidV7IdGenerator;
use Rechnungswesen\Core\Tenant;

final class TrialBalanceTest extends TestCase
{
    private Tenant $tenant;

    private Uuid $voucherId;

    protected function setUp(): void
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $this->tenant = Tenant::inMemory('Test', Currency::of('EUR'), $clock, new UuidV7IdGenerator($clock));

        foreach ([
            ['1200', 'Bank', AccountType::Asset],
            ['8400', 'Erlöse', AccountType::Revenue],
            ['4930', 'Bürobedarf', AccountType::Expense],
        ] as [$number, $name, $type]) {
            $this->tenant->accounts->add(new Account(
                $this->tenant->ids->next(),
                AccountNumber::of($number),
                $name,
                $type,
                null,
            ));
        }

        foreach ([2025, 2026] as $year) {
            $this->tenant->fiscalYears->add(FiscalYear::create(
                $this->tenant->ids->next(),
                $year,
                CalendarDate::of($year . '-01-01'),
                CalendarDate::of($year . '-12-31'),
                [[
                    'period' => 1,
                    'start' => CalendarDate::of($year . '-01-01'),
                    'end' => CalendarDate::of($year . '-12-31'),
                ]],
            ));
        }

        $this->voucherId = $this->tenant->ids->next();
        $this->tenant->vouchers->add(new Voucher($this->voucherId, 'V-1', CalendarDate::of('2025-06-01')));

        // 2025: Erlös 1000; 2026: Aufwand 300 (two-year-carryover-Szenario)
        $this->post('2025-06-01', [['1200', 'debit', '1000.00'], ['8400', 'credit', '1000.00']]);
        $this->post('2026-02-01', [['4930', 'debit', '300.00'], ['1200', 'credit', '300.00']]);
    }

    /** @param list<array{string, string, string}> $lines */
    private function post(string $date, array $lines): void
    {
        $this->tenant->ledger->post([
            'entryDate' => $date,
            'voucherId' => $this->voucherId->value,
            'text' => 'Test',
            'lines' => array_map(static fn (array $line): array => [
                'account' => $line[0],
                'side' => $line[1],
                'money' => ['amount' => $line[2], 'currency' => 'EUR'],
            ], $lines),
        ]);
    }

    private function projection(): TrialBalanceProjection
    {
        return new TrialBalanceProjection(Currency::of('EUR'), $this->tenant->accounts, $this->tenant->journal);
    }

    public function testBalanceCarryingAccountsCarryOverImplicitly(): void
    {
        $rows = $this->projection()->compute(['fiscalYear' => 2026, 'throughPeriod' => 1])['rows'];

        // 1200 kumulativ (1000 − 300), 4930 nur 2026; 8400 fehlt (Erfolgskonto ohne Bewegung 2026)
        self::assertSame([
            ['account' => '1200', 'debitTotal' => '1000.00', 'creditTotal' => '300.00', 'balance' => '700.00'],
            ['account' => '4930', 'debitTotal' => '300.00', 'creditTotal' => '0.00', 'balance' => '300.00'],
        ], $rows);
    }

    public function testIncomeAccountsStartAtZeroPerYear(): void
    {
        $rows = $this->projection()->compute(['fiscalYear' => 2025, 'throughPeriod' => 1])['rows'];

        self::assertSame([
            ['account' => '1200', 'debitTotal' => '1000.00', 'creditTotal' => '0.00', 'balance' => '1000.00'],
            ['account' => '8400', 'debitTotal' => '0.00', 'creditTotal' => '1000.00', 'balance' => '-1000.00'],
        ], $rows);
    }

    public function testIncludeZeroBalancesListsAllAccounts(): void
    {
        $rows = $this->projection()->compute([
            'fiscalYear' => 2025,
            'throughPeriod' => 1,
            'includeZeroBalances' => true,
        ])['rows'];

        self::assertSame(['1200', '4930', '8400'], array_column($rows, 'account'));
    }

    public function testAccountSheetRunningBalanceAcrossYears(): void
    {
        $sheet = (new AccountSheetProjection(Currency::of('EUR'), $this->tenant->accounts, $this->tenant->journal))
            ->compute(['account' => '1200', 'fiscalYear' => 2026]);

        self::assertSame('1000.00', $sheet['openingBalance']);
        self::assertSame('700.00', $sheet['closingBalance']);
        self::assertCount(1, (array) $sheet['lines']);
    }
}
