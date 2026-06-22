<?php

declare(strict_types=1);

namespace Summae\Core\Port;

use Summae\Core\Ledger\Voucher;
use Summae\Core\Substrate\Uuid;

interface VoucherRepository
{
    public function add(Voucher $voucher): void;

    public function byId(Uuid $id): ?Voucher;

    /** @return list<Voucher> sortiert nach ID */
    public function all(): array;
}
