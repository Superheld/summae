<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Ledger\Voucher;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Shared\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

final readonly class EloquentVoucherRepository implements VoucherRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function add(Voucher $voucher): void
    {
        $this->table()->insert([
            'id' => $voucher->id->value,
            'tenant_id' => $this->tenantId->value,
            'payload' => Hydrator::encode($voucher->jsonSerialize()),
        ]);
    }

    public function byId(Uuid $id): ?Voucher
    {
        $row = $this->table()->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $vouchers = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->get() as $row) {
            $vouchers[] = $this->hydrate($row);
        }

        usort($vouchers, static fn (Voucher $a, Voucher $b): int => $a->id->compareTo($b->id));

        return $vouchers;
    }

    private function hydrate(object $row): Voucher
    {
        /** @var object{id: string, payload: string} $row */
        $data = Hydrator::decode($row->payload);
        /** @var array<string, mixed> $servicePeriod */
        $servicePeriod = is_array($data['servicePeriod'] ?? null) ? $data['servicePeriod'] : [];

        return new Voucher(
            Uuid::fromString($row->id),
            is_string($data['voucherNumber'] ?? null) ? $data['voucherNumber'] : '',
            Hydrator::date($data['voucherDate'] ?? null) ?? throw new \RuntimeException('voucherDate fehlt'),
            Hydrator::date($data['due'] ?? null),
            (bool) ($data['recurring'] ?? false),
            is_int($data['economicYear'] ?? null) ? $data['economicYear'] : null,
            is_string($data['supplierTaxationMethod'] ?? null) ? $data['supplierTaxationMethod'] : null,
            Hydrator::date($data['serviceDate'] ?? null),
            Hydrator::date($servicePeriod['from'] ?? null),
            Hydrator::date($servicePeriod['to'] ?? null),
            is_string($data['kind'] ?? null) ? $data['kind'] : null,
            is_string($data['partnerId'] ?? null) ? Uuid::fromString($data['partnerId']) : null,
            is_string($data['issuer'] ?? null) ? $data['issuer'] : null,
        );
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'vouchers');
    }
}
