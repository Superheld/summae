<?php

declare(strict_types=1);

namespace Summae\Laravel\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Summae\Core\Composition\TenantOperations;
use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\AccountStatus;
use Summae\Core\Substrate\AccountType;
use Summae\Core\Substrate\Uuid;
use Summae\Core\Tenant;

/**
 * NF-015: two tenants share one database. That is the point of the `tenant_id` column, and the
 * root CLAUDE.md calls summae "multi-tenant at the data level" — so a repository built for tenant A
 * must never hand out, or write over, a row belonging to tenant B.
 *
 * No existing suite could see this: the conformance runner builds exactly one tenant per fixture,
 * and the cross test one per database. A repository that ignores `tenant_id` entirely passes both.
 */
final class TenantScopingTest extends AdapterTestCase
{
    private const string TENANT_A = '0195f000-0000-7000-8000-00000000aaaa';

    private const string TENANT_B = '0195f000-0000-7000-8000-00000000bbbb';

    /**
     * The ids come back in their own map rather than beside the tenant: they are looked up by a
     * variable key in the data-provider test, and a mixed-value array would defeat the type checker.
     *
     * @return array{tenant: Tenant, ids: array<string, string>}
     */
    private function books(string $tenantId, string $name, string $revenueAccount): array
    {
        $tenant = $this->tenantOn(Uuid::fromString($tenantId), $name);
        $ops = new TenantOperations($tenant);

        $ops->execute('createFiscalYear', ['year' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31']);
        $account = $ops->execute('createAccount', ['number' => '1400', 'name' => 'Forderungen', 'type' => 'asset', 'subtype' => 'ar']);
        $ops->execute('createAccount', ['number' => $revenueAccount, 'name' => 'Erlöse ' . $name, 'type' => 'revenue']);
        $partner = $ops->execute('createPartner', ['name' => 'Kunde ' . $name, 'kind' => 'customer']);

        $voucher = $ops->execute('createVoucher', [
            'voucher' => ['voucherNumber' => 'AR-' . $name, 'voucherDate' => '2026-03-01'],
        ]);
        $entry = $ops->execute('post', [
            'entryDate' => '2026-03-01',
            'voucherId' => is_string($voucher['id'] ?? null) ? $voucher['id'] : '',
            'text' => 'Rechnung ' . $name,
            'lines' => [
                ['account' => '1400', 'side' => 'debit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
                ['account' => $revenueAccount, 'side' => 'credit', 'money' => ['amount' => '100.00', 'currency' => 'EUR']],
            ],
        ]);

        /** @var list<array<string, mixed>> $created */
        $created = is_array($entry['openItemsCreated'] ?? null) ? $entry['openItemsCreated'] : [];

        return [
            'tenant' => $tenant,
            'ids' => [
                'accountId' => is_string($account['id'] ?? null) ? $account['id'] : '',
                'voucherId' => is_string($voucher['id'] ?? null) ? $voucher['id'] : '',
                'entryId' => is_string($entry['id'] ?? null) ? $entry['id'] : '',
                'openItemId' => is_string($created[0]['id'] ?? null) ? $created[0]['id'] : '',
                'partnerId' => is_string($partner['id'] ?? null) ? $partner['id'] : '',
            ],
        ];
    }

    public function testListingsNeverLeakTheOtherTenant(): void
    {
        $a = $this->books(self::TENANT_A, 'A', '8400');
        $b = $this->books(self::TENANT_B, 'B', '8500');

        self::assertCount(2, $a['tenant']->accounts->all());
        self::assertCount(2, $b['tenant']->accounts->all());
        self::assertCount(1, $a['tenant']->journal->all());
        self::assertCount(1, $b['tenant']->journal->all());
        self::assertCount(1, $a['tenant']->openItems->all());
        self::assertCount(1, $a['tenant']->partners->all());
        self::assertCount(1, $a['tenant']->vouchers->all());

        self::assertSame('Rechnung A', $a['tenant']->journal->all()[0]->text());
        self::assertSame('Rechnung B', $b['tenant']->journal->all()[0]->text());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function crossTenantLookups(): array
    {
        return [
            'account' => ['accounts', 'accountId'],
            'voucher' => ['vouchers', 'voucherId'],
            'journal entry' => ['journal', 'entryId'],
            'open item' => ['openItems', 'openItemId'],
            'partner' => ['partners', 'partnerId'],
        ];
    }

    private function lookup(Tenant $tenant, string $port, string $id): ?object
    {
        $uuid = Uuid::fromString($id);

        return match ($port) {
            'accounts' => $tenant->accounts->byId($uuid),
            'vouchers' => $tenant->vouchers->byId($uuid),
            'journal' => $tenant->journal->byId($uuid),
            'openItems' => $tenant->openItems->byId($uuid),
            'partners' => $tenant->partners->byId($uuid),
            default => throw new \InvalidArgumentException($port),
        };
    }

    #[DataProvider('crossTenantLookups')]
    public function testByIdRefusesAnotherTenantsRow(string $port, string $idKey): void
    {
        $a = $this->books(self::TENANT_A, 'A', '8400');
        $b = $this->books(self::TENANT_B, 'B', '8500');

        self::assertNotNull($this->lookup($a['tenant'], $port, $a['ids'][$idKey]), 'own row must be found');
        self::assertNull(
            $this->lookup($a['tenant'], $port, $b['ids'][$idKey]),
            'a repository built for tenant A must not hand out a row belonging to tenant B',
        );
    }

    public function testOpenItemsByOriginEntryStaysWithinTheTenant(): void
    {
        $a = $this->books(self::TENANT_A, 'A', '8400');
        $b = $this->books(self::TENANT_B, 'B', '8500');

        self::assertCount(1, $a['tenant']->openItems->byOriginEntry(Uuid::fromString($a['ids']['entryId'])));
        self::assertSame([], $a['tenant']->openItems->byOriginEntry(Uuid::fromString($b['ids']['entryId'])));
    }

    public function testSavingCannotWriteOverAnotherTenantsRow(): void
    {
        $a = $this->books(self::TENANT_A, 'A', '8400');
        $b = $this->books(self::TENANT_B, 'B', '8500');

        // An account carrying B's id, handed to A's repository — the shape a tenant mix-up takes in
        // an app that keeps one repository around and passes aggregates between requests.
        $foreign = new Account(
            Uuid::fromString($b['ids']['accountId']),
            AccountNumber::of('1400'),
            'Übernommen von A',
            AccountType::Asset,
            'ar',
            AccountStatus::Active,
        );
        $a['tenant']->accounts->save($foreign);

        $unchanged = $b['tenant']->accounts->byId(Uuid::fromString($b['ids']['accountId']));
        self::assertNotNull($unchanged);
        self::assertSame('Forderungen', $unchanged->name, "tenant B's account must be untouched");
    }
}
