<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Ledger\OpenItem;
use Summae\Core\Shared\Uuid;

interface OpenItemRepository
{
    public function add(OpenItem $item): void;

    public function save(OpenItem $item): void;

    public function byId(Uuid $id): ?OpenItem;

    /** @return list<OpenItem> Posten, die aus dieser Buchung entstanden */
    public function byOriginEntry(Uuid $entryId): array;

    /** @return list<OpenItem> in Entstehungsreihenfolge */
    public function all(): array;
}
