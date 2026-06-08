<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Partner\Partner;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Shared\Uuid;

final class InMemoryPartnerRepository implements PartnerRepository
{
    /** @var array<string, Partner> */
    private array $byId = [];

    public function add(Partner $partner): void
    {
        $this->byId[$partner->id->value] = $partner;
    }

    public function save(Partner $partner): void
    {
    }

    public function byId(Uuid $id): ?Partner
    {
        return $this->byId[$id->value] ?? null;
    }

    public function all(): array
    {
        $partners = array_values($this->byId);
        usort($partners, static function (Partner $a, Partner $b): int {
            $byName = strcmp($a->name(), $b->name());

            return $byName !== 0 ? $byName : $a->id->compareTo($b->id);
        });

        return $partners;
    }
}
