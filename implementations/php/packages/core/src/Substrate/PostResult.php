<?php

declare(strict_types=1);

namespace Summae\Core\Substrate;

use Summae\Core\Records\OpenItem;

/**
 * Result of `post`: the posting plus the open items
 * created along the way (AR/AP automation, F-CORE-009).
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
