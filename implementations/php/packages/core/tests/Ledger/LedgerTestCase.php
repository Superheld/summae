<?php

declare(strict_types=1);

namespace Summae\Core\Tests\Ledger;

use PHPUnit\Framework\TestCase;
use Summae\Core\DomainError;
use Summae\Core\Ledger\Account;
use Summae\Core\Ledger\AccountStatus;
use Summae\Core\Ledger\AccountType;
use Summae\Core\Ledger\DimensionRegistry;
use Summae\Core\Ledger\FiscalYear;
use Summae\Core\Ledger\Voucher;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\CalendarDate;
use Summae\Core\Substrate\Currency;
use Summae\Core\Substrate\FixedClock;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Substrate\UuidV7IdGenerator;
use Summae\Core\Tenant;

abstract class LedgerTestCase extends TestCase
{
    protected Tenant $tenant;

    protected Uuid $voucherId;

    protected function setUp(): void
    {
        $clock = FixedClock::at('2026-06-07T12:00:00+02:00');
        $this->tenant = Tenant::inMemory(
            'Test GmbH',
            Currency::of('EUR'),
            $clock,
            new UuidV7IdGenerator($clock),
            DimensionRegistry::fromData(
                [['code' => 'costCenter']],
                [['typeCode' => 'costCenter', 'code' => '100']],
                [],
            ),
        );

        foreach ([
            ['1200', 'Bank', AccountType::Asset, 'bank', AccountStatus::Active],
            ['8400', 'Erlöse', AccountType::Revenue, null, AccountStatus::Active],
            ['4930', 'Bürobedarf', AccountType::Expense, null, AccountStatus::Active],
            ['0999', 'Gesperrt', AccountType::Expense, null, AccountStatus::Locked],
        ] as [$number, $name, $type, $subtype, $status]) {
            $this->tenant->accounts->add(new Account(
                $this->tenant->ids->next(),
                AccountNumber::of($number),
                $name,
                $type,
                $subtype,
                $status,
            ));
        }

        $this->tenant->fiscalYears->add(FiscalYear::create(
            $this->tenant->ids->next(),
            2026,
            CalendarDate::of('2026-01-01'),
            CalendarDate::of('2026-12-31'),
        ));

        $this->voucherId = $this->tenant->ids->next();
        $this->tenant->vouchers->add(new Voucher($this->voucherId, 'V-1', CalendarDate::of('2026-03-01')));
    }

    /**
     * @param list<array{string, string, string}> $lines [account, side, amount]
     *
     * @return array<string, mixed>
     */
    protected function draft(array $lines, string $entryDate = '2026-03-05', ?string $voucherId = null): array
    {
        return [
            'entryDate' => $entryDate,
            'voucherId' => $voucherId ?? $this->voucherId->value,
            'text' => 'Test',
            'lines' => array_map(
                static fn (array $line): array => [
                    'account' => $line[0],
                    'side' => $line[1],
                    'money' => ['amount' => $line[2], 'currency' => 'EUR'],
                ],
                $lines,
            ),
        ];
    }

    protected function expectDomainError(string $code, callable $action): void
    {
        try {
            $action();
            self::fail(sprintf('DomainError %s erwartet', $code));
        } catch (DomainError $e) {
            self::assertSame($code, $e->errorCode);
        }
    }
}
