<?php

declare(strict_types=1);

namespace Summae\Core\Policies\Projection;

use Summae\Core\DomainError;
use Summae\Core\Records\OpenItem;
use Summae\Core\Substrate\OpenItemKind;
use Summae\Core\Port\JournalRepository;
use Summae\Core\Port\PartnerRepository;
use Summae\Core\Port\OpenItemRepository;
use Summae\Core\Port\VoucherRepository;
use Summae\Core\Substrate\CalendarDate;

/**
 * Open items list: deterministic, asOf-capable (time travel via settledAt).
 * Sorting: voucherDate, then sequenceNumber (determinismus.md §3).
 */
final readonly class OpenItemsProjection
{
    public function __construct(
        private OpenItemRepository $openItems,
        private VoucherRepository $vouchers,
        private JournalRepository $journal,
        private PartnerRepository $partners,
    ) {
    }

    /**
     * @param array<string, mixed> $params asOf (ISO date), kind?
     *
     * @return array{items: list<array<string, mixed>>}
     */
    public function compute(array $params): array
    {
        $asOf = is_string($params['asOf'] ?? null) ? CalendarDate::of($params['asOf']) : null;
        // An unparseable kind used to fall back to "no filter", so a mistyped filter widened the
        // result instead of narrowing it: a payment run asking for payables got receivables mixed
        // in and would have paid them out. Absent still means "no filter" — a wrong value must not.
        $kind = null;
        $rawKind = $params['kind'] ?? null;

        if ($rawKind !== null) {
            $kind = is_string($rawKind) ? OpenItemKind::tryFrom($rawKind) : null;

            if ($kind === null) {
                throw new DomainError(
                    'E_INPUT_INVALID',
                    'openItems: "kind" must be "receivable" or "payable"',
                    ['kind' => DomainError::rejectedValue($rawKind)],
                );
            }
        }
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

    /**
     * Both `partnerId` and `due` were known here and neither was published.
     *
     * The partner was accepted as a FILTER and dropped from the result, so a list could be narrowed
     * to one debtor and then could not say which. The due date sits on the voucher — which this
     * method already loads, for the voucher number — and without it the list cannot be aged at all,
     * which is what a maturity disclosure is built from.
     *
     * Null where the voucher names no date: present and null, so "no date agreed" stays
     * distinguishable from "this view does not say".
     *
     * `partnerName` joined the same way and for the same reason (F-CORE-030). The list could name
     * the invoice but not the customer, and resolving the id meant a second projection inside one
     * view — which is exactly the mixing an application's read layer is supposed to avoid. A
     * dunning notice without a recipient is not a notice. Null where the partner is unknown or was
     * never recorded, so a missing partner does not turn into an empty string that reads like a
     * name.
     *
     * @return array<string, mixed>
     */
    private function serializeItem(OpenItem $item, ?CalendarDate $asOf): array
    {
        $voucher = $this->vouchers->byId($item->voucherId);

        return [
            'id' => $item->id->value,
            'kind' => $item->kind->value,
            'voucherNumber' => $voucher?->voucherNumber,
            'partnerId' => $item->partnerId?->value,
            'partnerName' => $item->partnerId === null ? null : $this->partners->byId($item->partnerId)?->name(),
            'due' => $voucher?->due?->iso,
            'money' => $item->money->jsonSerialize(),
            'remaining' => $item->remainingAt($asOf)->jsonSerialize(),
            'status' => $item->statusAt($asOf)->value,
        ];
    }
}
