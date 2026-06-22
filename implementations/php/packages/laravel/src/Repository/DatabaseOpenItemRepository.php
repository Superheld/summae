<?php

declare(strict_types=1);

namespace Summae\Laravel\Repository;

use Illuminate\Database\ConnectionInterface;
use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\OpenItemKind;
use Summae\Core\Policies\Expansion\Settlement;
use Summae\Core\Substrate\SettlementDifferenceKind;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Substrate\Uuid;
use Summae\Laravel\Schema\SchemaInstaller;

final readonly class DatabaseOpenItemRepository implements OpenItemRepository
{
    public function __construct(
        private ConnectionInterface $connection,
        private Uuid $tenantId,
    ) {
    }

    public function add(OpenItem $item): void
    {
        $this->table()->insert([
            'id' => $item->id->value,
            'tenant_id' => $this->tenantId->value,
            'kind' => $item->kind->value,
            'origin_entry_id' => $item->originEntryId->value,
            'origin_line_index' => $item->originLineIndex,
            'amount' => $item->money->amountAsString(),
            'currency' => $item->money->currency->code,
            'voucher_id' => $item->voucherId->value,
            'opened_at' => $item->openedAt->iso,
            'partner_id' => $item->partnerId?->value,
            'settlements' => $this->encodeSettlements($item),
        ]);
    }

    public function save(OpenItem $item): void
    {
        $this->table()->where('id', $item->id->value)->update([
            'settlements' => $this->encodeSettlements($item),
        ]);
    }

    public function byId(Uuid $id): ?OpenItem
    {
        $row = $this->table()->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function byOriginEntry(Uuid $entryId): array
    {
        $items = [];

        foreach ($this->table()->where('origin_entry_id', $entryId->value)->orderBy('origin_line_index')->get() as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    public function all(): array
    {
        $items = [];

        foreach ($this->table()->where('tenant_id', $this->tenantId->value)->orderBy('opened_at')->orderBy('id')->get() as $row) {
            $items[] = $this->hydrate($row);
        }

        return $items;
    }

    private function encodeSettlements(OpenItem $item): string
    {
        return Hydrator::encode(array_map(
            static fn (Settlement $settlement): array => $settlement->jsonSerialize(),
            $item->settlements(),
        ));
    }

    private function hydrate(object $row): OpenItem
    {
        /** @var object{id: string, kind: string, origin_entry_id: string, origin_line_index: int|string, amount: string, currency: string, voucher_id: string, opened_at: string, partner_id: ?string, settlements: string} $row */
        $settlements = [];

        foreach (Hydrator::decodeList($row->settlements) as $data) {
            /** @var array<string, mixed> $difference */
            $difference = is_array($data['difference'] ?? null) ? $data['difference'] : [];
            /** @var array<string, mixed> $differenceMoney */
            $differenceMoney = is_array($difference['money'] ?? null) ? $difference['money'] : [];
            /** @var array<string, mixed> $money */
            $money = is_array($data['money'] ?? null) ? $data['money'] : [];

            $settlements[] = new Settlement(
                Uuid::fromString(is_string($data['entryId'] ?? null) ? $data['entryId'] : ''),
                Hydrator::money($money),
                Hydrator::date($data['settledAt'] ?? null) ?? throw new \RuntimeException('settledAt fehlt'),
                $differenceMoney === [] ? null : Hydrator::money($differenceMoney),
                is_string($difference['kind'] ?? null) ? SettlementDifferenceKind::tryFrom($difference['kind']) : null,
            );
        }

        return OpenItem::restore(
            Uuid::fromString($row->id),
            OpenItemKind::from($row->kind),
            Uuid::fromString($row->origin_entry_id),
            (int) $row->origin_line_index,
            Hydrator::money(['amount' => $row->amount, 'currency' => $row->currency]),
            Uuid::fromString($row->voucher_id),
            Hydrator::date($row->opened_at) ?? throw new \RuntimeException('opened_at fehlt'),
            $row->partner_id === null ? null : Uuid::fromString($row->partner_id),
            $settlements,
        );
    }

    private function table(): \Illuminate\Database\Query\Builder
    {
        return $this->connection->table(SchemaInstaller::PREFIX . 'open_items');
    }
}
