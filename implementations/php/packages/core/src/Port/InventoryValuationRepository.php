<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Policies\Expansion\Inventory\InventoryValuation;

/**
 * Inventory valuations (F-CORE-050).
 *
 * The same shape as `CostingRunRepository`, and for the same reason it exists at all: a valuation
 * that lives in the process that made it cannot be read back, and the record of *how* a stock
 * figure was reached is precisely what an inventory has to be able to show. `all()` is sorted
 * because the next version of a period comes out of the store, not out of a counter.
 */
interface InventoryValuationRepository
{
    /**
     * Deliberately no `byId` and no `save`.
     *
     * A valuation is one act that never changes, and nothing asks for a single one: the projection
     * reports them all and the version counter reads them all. An interface method nobody calls is
     * a burden on every adapter author for a convenience the core does not have — and it is the
     * kind of thing that reads as "supported" long after it stopped being exercised.
     */
    public function add(InventoryValuation $valuation): void;

    /** @return list<InventoryValuation> sorted by fiscal year, then period, then version */
    public function all(): array;
}
