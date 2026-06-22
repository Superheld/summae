<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Expansion\Assets;

/**
 * Low-value-asset switch (SF-05): three routes as core mechanics,
 * the thresholds are rule-module data with validity.
 */
enum AssetRoute: string
{
    case Capitalize = 'capitalize';
    case ImmediateExpense = 'immediate_expense';
    case Pool = 'pool';
}
