<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Policies\Expansion\Provisions\Provision;
use Summae\Core\Substrate\Uuid;

/**
 * Provisions (F-CORE-051).
 *
 * Shaped like `AssetRepository`, because a provision is the same kind of thing an asset is: a
 * record with a life, whose movements matter as much as its balance. `save` exists here and not on
 * the inventory port for exactly that reason — a valuation is one act and never changes, a
 * provision is used, released and re-measured over years.
 */
interface ProvisionRepository
{
    public function add(Provision $provision): void;

    public function save(Provision $provision): void;

    public function byId(Uuid $id): ?Provision;

    /** @return list<Provision> in the order they were recognised */
    public function all(): array;
}
