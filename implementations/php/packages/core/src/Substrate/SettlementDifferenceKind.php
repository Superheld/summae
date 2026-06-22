<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Ausgleich mit Differenz (api.md v0.3, Review G2): Skonto,
 * Forderungsausfall, Kleindifferenz — die Differenz selbst MUSS als
 * Buchungszeile(n) in der ausgleichenden Buchung sichtbar sein (§ 17 UStG).
 */
enum SettlementDifferenceKind: string
{
    case Discount = 'discount';
    case BadDebt = 'bad_debt';
    case Minor = 'minor';
}
