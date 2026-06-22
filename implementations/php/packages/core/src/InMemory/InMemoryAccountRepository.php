<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Ledger\Account;
use Summae\Core\Port\AccountRepository;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Uuid;

final class InMemoryAccountRepository implements AccountRepository
{
    /** @var array<string, Account> Kontonummer -> Account */
    private array $byNumber = [];

    /** @var array<string, Account> id -> Account */
    private array $byId = [];

    public function add(Account $account): void
    {
        if (isset($this->byNumber[$account->number->value])) {
            throw new \LogicException(sprintf(
                'Repository-Kontrakt verletzt: Kontonummer %s doppelt',
                $account->number->value,
            ));
        }

        $this->byNumber[$account->number->value] = $account;
        $this->byId[$account->id->value] = $account;
    }

    public function save(Account $account): void
    {
        // In-Memory: Objektidentität genügt.
    }

    public function byNumber(AccountNumber $number): ?Account
    {
        return $this->byNumber[$number->value] ?? null;
    }

    public function byId(Uuid $id): ?Account
    {
        return $this->byId[$id->value] ?? null;
    }

    public function all(): array
    {
        $accounts = array_values($this->byNumber);
        usort($accounts, static fn (Account $a, Account $b): int => $a->number->compareTo($b->number));

        return $accounts;
    }
}
