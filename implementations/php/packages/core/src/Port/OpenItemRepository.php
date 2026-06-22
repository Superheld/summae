<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\Uuid;

interface OpenItemRepository
{
    public function add(OpenItem $item): void;

    public function save(OpenItem $item): void;

    public function byId(Uuid $id): ?OpenItem;

    /** @return list<OpenItem> items that arose from this posting */
    public function byOriginEntry(Uuid $entryId): array;

    /** @return list<OpenItem> in creation order */
    public function all(): array;
}
