<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Partner\Partner;
use Summae\Core\Shared\Uuid;

interface PartnerRepository
{
    public function add(Partner $partner): void;

    public function save(Partner $partner): void;

    public function byId(Uuid $id): ?Partner;

    /** @return list<Partner> sortiert nach Name, dann ID */
    public function all(): array;
}
