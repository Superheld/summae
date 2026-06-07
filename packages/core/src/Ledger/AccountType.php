<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

/**
 * Kontotyp bestimmt die Saldenmechanik (ledger-modell.md):
 * Bestandskonten kumulieren über Jahre, Erfolgskonten je Geschäftsjahr
 * (api.md Zeitraum-Semantik, v0.3).
 */
enum AccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Expense = 'expense';
    case Revenue = 'revenue';

    /** Bestandskonto: Saldo trägt implizit vor (kein SBK/EBK). */
    public function isBalanceCarrying(): bool
    {
        return match ($this) {
            self::Asset, self::Liability, self::Equity => true,
            self::Expense, self::Revenue => false,
        };
    }
}
