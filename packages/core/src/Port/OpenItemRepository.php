<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Port;

use Rechnungswesen\Core\Ledger\OpenItem;
use Rechnungswesen\Core\Shared\Uuid;

interface OpenItemRepository
{
    public function add(OpenItem $item): void;

    public function save(OpenItem $item): void;

    public function byId(Uuid $id): ?OpenItem;

    /** @return list<OpenItem> in Entstehungsreihenfolge */
    public function all(): array;
}
