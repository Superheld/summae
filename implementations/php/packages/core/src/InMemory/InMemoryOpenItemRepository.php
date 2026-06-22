<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Ledger\OpenItem;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Substrate\Uuid;

final class InMemoryOpenItemRepository implements OpenItemRepository
{
    /** @var list<OpenItem> */
    private array $items = [];

    /** @var array<string, OpenItem> */
    private array $byId = [];

    public function add(OpenItem $item): void
    {
        $this->items[] = $item;
        $this->byId[$item->id->value] = $item;
    }

    public function save(OpenItem $item): void
    {
    }

    public function byId(Uuid $id): ?OpenItem
    {
        return $this->byId[$id->value] ?? null;
    }

    public function byOriginEntry(Uuid $entryId): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (OpenItem $item): bool => $item->originEntryId->equals($entryId),
        ));
    }

    public function all(): array
    {
        return $this->items;
    }
}
