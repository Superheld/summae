<?php

declare(strict_types=1);

namespace Rechnungswesen\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Rechnungswesen\Core\Ledger\Account;
use Rechnungswesen\Core\Ledger\AccountStatus;
use Rechnungswesen\Core\Ledger\AccountType;
use Rechnungswesen\Core\Port\AccountRepository;
use Rechnungswesen\Core\Shared\AccountNumber;
use Rechnungswesen\Core\Shared\Uuid;
use Rechnungswesen\Laravel\Schema\SchemaInstaller;

final readonly class EloquentAccountRepository implements AccountRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function add(Account $account): void
    {
        $this->table()->insert([
            'id' => $account->id->value,
            'tenant_id' => $this->tenantId->value,
            'number' => $account->number->value,
            'name' => $account->name,
            'type' => $account->type->value,
            'subtype' => $account->subtype,
            'status' => $account->status()->value,
        ]);
    }

    public function save(Account $account): void
    {
        $this->table()->where('id', $account->id->value)->update([
            'name' => $account->name,
            'status' => $account->status()->value,
        ]);
    }

    public function byNumber(AccountNumber $number): ?Account
    {
        $row = $this->table()
            ->where('tenant_id', $this->tenantId->value)
            ->where('number', $number->value)
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function byId(Uuid $id): ?Account
    {
        $row = $this->table()->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $accounts = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->get() as $row) {
            $accounts[] = $this->hydrate($row);
        }

        usort($accounts, static fn (Account $a, Account $b): int => $a->number->compareTo($b->number));

        return $accounts;
    }

    private function hydrate(object $row): Account
    {
        /** @var object{id: string, number: string, name: string, type: string, subtype: ?string, status: string} $row */
        return new Account(
            Uuid::fromString($row->id),
            AccountNumber::of($row->number),
            $row->name,
            AccountType::from($row->type),
            $row->subtype,
            AccountStatus::from($row->status),
        );
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'accounts');
    }
}
