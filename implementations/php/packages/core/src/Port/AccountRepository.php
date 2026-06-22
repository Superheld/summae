<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Ledger\Account;
use Summae\Core\Substrate\AccountNumber;
use Summae\Core\Substrate\Uuid;

/**
 * Repository-Kontrakt: Kontonummern sind je Mandant eindeutig —
 * der Adapter MUSS das zusichern (ledger-modell.md Aggregat 2).
 */
interface AccountRepository
{
    public function add(Account $account): void;

    /** Mutationen am Aggregat persistieren (In-Memory: No-op). */
    public function save(Account $account): void;

    public function byNumber(AccountNumber $number): ?Account;

    public function byId(Uuid $id): ?Account;

    /** @return list<Account> sortiert nach Kontonummer (Codepoints) */
    public function all(): array;
}
