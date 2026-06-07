<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\InMemory;

use Rechnungswesen\Core\Ledger\OpenItem;
use Rechnungswesen\Core\Port\OpenItemRepository;
use Rechnungswesen\Core\Shared\Uuid;

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

    public function all(): array
    {
        return $this->items;
    }
}
