<?php

declare(strict_types=1);

namespace Summae\Core\Assets;

/**
 * GWG-Weiche (SF-05): drei Pfade als Kern-Mechanik,
 * die Grenzen sind Regelmodul-Daten mit Gültigkeit.
 */
enum AssetRoute: string
{
    case Capitalize = 'capitalize';
    case ImmediateExpense = 'immediate_expense';
    case Pool = 'pool';
}
