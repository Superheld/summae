<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Port\AccountRepository;
use Summae\Core\Substrate\Account;

/**
 * The chart of accounts as a screen can afford to read it (F-CORE-028).
 *
 * Every field here was already published somewhere, and nowhere a caller could use. `subtype`
 * and `status` live in `journalExport.data.accounts` — behind five streams with a SHA-256 each,
 * an archive format answering a question a list view asks on every page load.
 * `datevExport(kind: accounts)` is cheap but DATEV-shaped: number, name, type, and by definition
 * nothing else, because it is master data for an export format rather than the chart itself.
 *
 * So the two facts an application needs most were the two it could not get. `subtype` is what
 * identifies an account's *role* — which one is the bank, which the cash box, which receivables
 * and payables — and without it an app preselecting a counter account has to read the pack it was
 * created from, which is the chart the tenant *started* with and not the chart it has. `status`
 * is the read side of `lockAccount`: the operation exists, and nothing a screen could afford
 * reported whether it had been used, so the only honest test was to post into the account and
 * read the refusal.
 *
 * Deliberately small: no balances (that is `trialBalance`), no movements (`accountSheet`), no
 * hashes. Sorted by account number over Unicode code points, like every other ordering here.
 */
final readonly class AccountsProjection
{
    public function __construct(
        private AccountRepository $accounts,
    ) {
    }

    /**
     * @param array<string, mixed> $params (none)
     *
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function compute(array $params): array
    {
        $accounts = $this->accounts->all();
        usort($accounts, static fn (Account $a, Account $b): int => strcmp($a->number->value, $b->number->value));

        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = [
                'number' => $account->number->value,
                'name' => $account->name,
                'type' => $account->type->value,
                'subtype' => $account->subtype,
                'status' => $account->status()->value,
                // The read side of the validity window (F-CORE-045), and for the same reason
                // `status` is here: a screen offering an account picker has to be able to grey out
                // what the posting date does not allow, rather than letting the user post and then
                // translating `E_ACCOUNT_NOT_VALID_AT_DATE` back into a sentence.
                'validFrom' => $account->validFrom?->iso,
                'validTo' => $account->validTo?->iso,
            ];
        }

        return ['accounts' => $rows];
    }
}
