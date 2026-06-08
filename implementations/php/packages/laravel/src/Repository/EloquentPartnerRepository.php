<?php

declare(strict_types=1);

namespace Rechnungswesen\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Rechnungswesen\Core\Partner\Partner;
use Rechnungswesen\Core\Port\PartnerRepository;
use Rechnungswesen\Core\Shared\Uuid;
use Rechnungswesen\Laravel\Schema\SchemaInstaller;

final readonly class EloquentPartnerRepository implements PartnerRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function add(Partner $partner): void
    {
        $this->table()->insert([
            'id' => $partner->id->value,
            'tenant_id' => $this->tenantId->value,
            'payload' => Hydrator::encode($partner->jsonSerialize()),
        ]);
    }

    public function save(Partner $partner): void
    {
        $this->table()->where('id', $partner->id->value)->update([
            'payload' => Hydrator::encode($partner->jsonSerialize()),
        ]);
    }

    public function byId(Uuid $id): ?Partner
    {
        $row = $this->table()->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        $partners = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->get() as $row) {
            $partners[] = $this->hydrate($row);
        }

        usort($partners, static function (Partner $a, Partner $b): int {
            $byName = strcmp($a->name(), $b->name());

            return $byName !== 0 ? $byName : $a->id->compareTo($b->id);
        });

        return $partners;
    }

    private function hydrate(object $row): Partner
    {
        /** @var object{id: string, payload: string} $row */
        $data = Hydrator::decode($row->payload);

        /** @var list<string> $accountNumbers */
        $accountNumbers = array_values(array_filter(
            is_array($data['accountNumbers'] ?? null) ? $data['accountNumbers'] : [],
            is_string(...),
        ));
        /** @var array<string, mixed> $address */
        $address = is_array($data['address'] ?? null) ? $data['address'] : [];

        return new Partner(
            Uuid::fromString($row->id),
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['kind'] ?? null) ? $data['kind'] : 'both',
            is_string($data['vatId'] ?? null) ? $data['vatId'] : null,
            is_int($data['paymentTermsDays'] ?? null) ? $data['paymentTermsDays'] : null,
            $accountNumbers,
            $address,
        );
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'partners');
    }
}
