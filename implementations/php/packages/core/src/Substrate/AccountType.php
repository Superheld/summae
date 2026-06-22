<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Account type determines the balance mechanics (ledger-modell.md):
 * balance-sheet accounts accumulate over years, income accounts per fiscal year
 * (api.md period semantics, v0.3).
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Expense = 'expense';
    case Revenue = 'revenue';

    /** Balance-sheet account: balance carries forward implicitly (no closing/opening account). */
    public function isBalanceCarrying(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Expense, self::Revenue => false,
        };
    }
}
