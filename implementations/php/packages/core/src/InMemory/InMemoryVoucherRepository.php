<?php

declare(strict_types=1);

namespace Rechnungswesen\Core\InMemory;

use Rechnungswesen\Core\Ledger\Voucher;
use Rechnungswesen\Core\Port\VoucherRepository;
use Rechnungswesen\Core\Shared\Uuid;

final class InMemoryVoucherRepository implements VoucherRepository
{
    /** @var array<string, Voucher> */
    private array $byId = [];

    public function add(Voucher $voucher): void
    {
        $this->byId[$voucher->id->value] = $voucher;
    }

    public function byId(Uuid $id): ?Voucher
    {
        return $this->byId[$id->value] ?? null;
    }

    public function all(): array
    {
        $vouchers = array_values($this->byId);
        usort($vouchers, static fn (Voucher $a, Voucher $b): int => $a->id->compareTo($b->id));

        return $vouchers;
    }
}
