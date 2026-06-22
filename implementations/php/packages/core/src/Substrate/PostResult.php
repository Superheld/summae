<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Records\OpenItem;

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
