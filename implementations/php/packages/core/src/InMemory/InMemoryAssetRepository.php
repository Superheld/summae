<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\InMemory;

use Rechnungswesen\Core\Assets\Asset;
use Rechnungswesen\Core\Port\AssetRepository;
use Rechnungswesen\Core\Shared\Uuid;

final class InMemoryAssetRepository implements AssetRepository
{
    /** @var list<Asset> */
    private array $assets = [];

    /** @var array<string, Asset> */
    private array $byId = [];

    public function add(Asset $asset): void
    {
        $this->assets[] = $asset;
        $this->byId[$asset->id->value] = $asset;
    }

    public function save(Asset $asset): void
    {
    }

    public function byId(Uuid $id): ?Asset
    {
        return $this->byId[$id->value] ?? null;
    }

    public function all(): array
    {
        return $this->assets;
    }
}
