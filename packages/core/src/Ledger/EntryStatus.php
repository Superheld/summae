<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

/**
 * GoBD-Lebenszyklus: erfasst (korrigierbar mit Audit) -> festgeschrieben
 * (unveränderlich, danach nur Storno).
 */
enum EntryStatus: string
{
    case Entered = 'entered';
    case Finalized = 'finalized';
}
