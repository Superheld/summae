<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Ledger;

/**
 * Ergebnis von `post`: die Buchung plus die dabei entstandenen
 * offenen Posten (AR/AP-Automatik, F-CORE-009).
 */
final readonly class PostResult
{
    /**
     * @param list<OpenItem> $openItemsCreated
     */
    public function __construct(
        public JournalEntry $entry,
        public array $openItemsCreated,
    ) {
    }
}
