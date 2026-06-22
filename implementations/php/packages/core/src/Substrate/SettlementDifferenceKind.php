<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

/**
 * Settlement with difference (api.md v0.3, Review G2): cash discount,
 * bad debt, minor difference — the difference itself MUST be visible as
 * posting line(s) in the settling posting (§ 17 UStG).
 */
enum SettlementDifferenceKind: string
{
    case Discount = 'discount';
    case BadDebt = 'bad_debt';
    case Minor = 'minor';
}
