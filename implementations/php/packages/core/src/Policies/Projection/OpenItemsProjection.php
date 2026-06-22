<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\Ledger\OpenItem;
use Summae\Core\Ledger\OpenItemKind;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\CalendarDate;

/**
 * OP-Liste: deterministisch, asOf-fähig (Zeitreise über settledAt).
 * Sortierung: voucherDate, dann sequenceNumber (determinismus.md §3).
 */
final readonly class OpenItemsProjection
{
    public function __construct(
        private OpenItemRepository $openItems,
        private VoucherRepository $vouchers,
        private JournalRepository $journal,
    ) {
    }

    /**
     * @param array<string, mixed> $params asOf (ISO-Datum), kind?
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function compute(array $params): array
    {
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;
        $kind = is_string($params['kind'] ?? null) ? OpenItemKind::tryFrom($params['kind']) : null;
        $partnerId = is_string($params['partnerId'] ?? null) ? $params['partnerId'] : null;

        $open = [];

        foreach ($this->openItems->all() as $item) {
            if ($kind !== null && $item->kind !== $kind) {
                continue;
            }

            if ($partnerId !== null && $item->partnerId?->value !== $partnerId) {
                continue;
            }

            if ($asOf !== null && $item->openedAt->isAfter($asOf)) {
                continue;
            }

            if ($item->remainingAt($asOf)->isZero()) {
                continue;
            }

            $open[] = $item;
        }

        usort($open, function (OpenItem $a, OpenItem $b): int {
            $byDate = $this->voucherDate($a)->compareTo($this->voucherDate($b));

            return $byDate !== 0 ? $byDate : $this->sequenceNumber($a) <=> $this->sequenceNumber($b);
        });

        return [
            'items' => array_map(fn (OpenItem $item): array => $this->serializeItem($item, $asOf), $open),
        ];
    }

    private function voucherDate(OpenItem $item): CalendarDate
    {
        $voucher = $this->vouchers->byId($item->voucherId);

        return $voucher === null ? $item->openedAt : $voucher->voucherDate;
    }

    private function sequenceNumber(OpenItem $item): int
    {
        $entry = $this->journal->byId($item->originEntryId);

        return $entry === null ? 0 : $entry->sequenceNumber;
    }

    /** @return array<string, mixed> */
    private function serializeItem(OpenItem $item, ?CalendarDate $asOf): array
    {
        return [
            'id' => $item->id->value,
            'kind' => $item->kind->value,
            'voucherNumber' => $this->vouchers->byId($item->voucherId)?->voucherNumber,
            'money' => $item->money->jsonSerialize(),
            'remaining' => $item->remainingAt($asOf)->jsonSerialize(),
            'status' => $item->statusAt($asOf)->value,
        ];
    }
}
