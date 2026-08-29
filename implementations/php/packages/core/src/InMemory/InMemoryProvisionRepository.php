<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Policies\Expansion\Provisions\Provision;
use Summae\Core\Port\ProvisionRepository;
use Summae\Core\Substrate\Uuid;

final class InMemoryProvisionRepository implements ProvisionRepository
{
    /** @var list<Provision> */
    private array $provisions = [];

    /** @var array<string, Provision> */
    private array $byId = [];

    public function add(Provision $provision): void
    {
        $this->provisions[] = $provision;
        $this->byId[$provision->id->value] = $provision;
    }

    public function save(Provision $provision): void
    {
    }

    public function byId(Uuid $id): ?Provision
    {
        return $this->byId[$id->value] ?? null;
    }

    public function all(): array
    {
        return $this->provisions;
    }
}
