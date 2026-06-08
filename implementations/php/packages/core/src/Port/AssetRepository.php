<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Assets\Asset;
use Summae\Core\Shared\Uuid;

interface AssetRepository
{
    public function add(Asset $asset): void;

    public function save(Asset $asset): void;

    public function byId(Uuid $id): ?Asset;

    /** @return list<Asset> in Zugangsreihenfolge */
    public function all(): array;
}
