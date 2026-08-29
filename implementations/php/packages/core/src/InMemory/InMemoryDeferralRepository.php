<?php

declare(strict_types=1);

namespace Summae\Core\InMemory;

use Summae\Core\Policies\Expansion\Deferrals\Deferral;
use Summae\Core\Port\DeferralRepository;

final class InMemoryDeferralRepository implements DeferralRepository
{
    /** @var list<Deferral> */
    private array $deferrals = [];

    public function add(Deferral $deferral): void
    {
        $this->deferrals[] = $deferral;
    }

    public function save(Deferral $deferral): void
    {
    }

    public function all(): array
    {
        return $this->deferrals;
    }
}
