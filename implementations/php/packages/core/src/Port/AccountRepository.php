<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Substrate\Account;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Uuid;

/**
 * Repository contract: account numbers are unique per tenant —
 * the adapter MUST guarantee that (ledger-modell.md aggregate 2).
 */
interface AccountRepository
{
    public function add(Account $account): void;

    /** Persist mutations on the aggregate (in-memory: no-op). */
    public function save(Account $account): void;

    public function byNumber(AccountNumber $number): ?Account;

    public function byId(Uuid $id): ?Account;

    /** @return list<Account> sorted by account number (codepoints) */
    public function all(): array;
}
