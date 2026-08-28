<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Partner\Partner;
use Summae\Core\Substrate\Uuid;

interface PartnerRepository
{
    public function add(Partner $partner): void;

    public function save(Partner $partner): void;

    public function byId(Uuid $id): ?Partner;

    /** @return list<Partner> sorted by name, then ID */
    public function all(): array;

    /**
     * Remove a partner outright (F-CORE-040) — the only repository in the core that can forget.
     *
     * Guarded by PartnerService::erase, never called from the bookkeeping path. See
     * AuditTrail::eraseFor for why the capability exists at all.
     */
    public function remove(Uuid $id): void;
}
