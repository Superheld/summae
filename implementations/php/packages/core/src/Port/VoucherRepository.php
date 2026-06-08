<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\Port;

use Rechnungswesen\Core\Ledger\Voucher;
use Rechnungswesen\Core\Shared\Uuid;

interface VoucherRepository
{
    public function add(Voucher $voucher): void;

    public function byId(Uuid $id): ?Voucher;

    /** @return list<Voucher> sortiert nach ID */
    public function all(): array;
}
